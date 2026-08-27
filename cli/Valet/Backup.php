<?php

namespace Valet;

use ConsoleComponents\Writer;

class Backup
{
    public const BACKUP_DIR = VALET_HOME_PATH . '/Backups';

    public CommandLine $cli;
    public Filesystem $files;
    public Configuration $configuration;
    public Mysql $mysql;
    public Postgres $postgres;

    /**
     * Create a new Backup instance.
     */
    public function __construct(
        CommandLine $cli,
        Filesystem $files,
        Configuration $configuration,
        Mysql $mysql,
        Postgres $postgres
    ) {
        $this->cli = $cli;
        $this->files = $files;
        $this->configuration = $configuration;
        $this->mysql = $mysql;
        $this->postgres = $postgres;
    }

    /**
     * Create a backup archive of the Valet home directory.
     *
     * The archive contains config.json, the Nginx site configurations, the
     * SSL certificates and (optionally) database dumps. A manifest.json is
     * embedded in the archive describing its contents.
     *
     * @return string The path to the created archive.
     */
    public function backup(?string $output = null, bool $withDb = false): string
    {
        $this->files->ensureDirExists(self::BACKUP_DIR, user());

        $output = $output ?: (self::BACKUP_DIR . '/valet-backup-' . date('Y-m-d-H-i-s') . '.tar.gz');

        $staging = sys_get_temp_dir() . '/valet-backup-' . uniqid();
        $this->files->ensureDirExists($staging);

        // Config file.
        $configPath = VALET_HOME_PATH . '/config.json';
        if ($this->files->exists($configPath)) {
            $this->files->copy($configPath, $staging . '/config.json');
        }

        // Nginx site configurations.
        $nginxDir = VALET_HOME_PATH . '/Nginx';
        if ($this->files->isDir($nginxDir)) {
            $this->files->copyDirectory($nginxDir, $staging . '/Nginx');
        }

        // SSL certificates.
        $certsDir = VALET_HOME_PATH . '/Certificates';
        if ($this->files->isDir($certsDir)) {
            $this->files->copyDirectory($certsDir, $staging . '/Certificates');
        }

        $databases = ['mysql' => [], 'postgres' => []];
        $includesDb = false;
        if ($withDb) {
            $includesDb = $this->backupDatabases($staging, $databases);
        }

        $manifest = [
            'version' => $this->version(),
            'domain' => $this->configuration->get('domain'),
            'timestamp' => date('c'),
            'includes_db' => $includesDb,
            'databases' => $databases,
        ];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($manifestJson === false) {
            $manifestJson = '{}';
        }
        $this->files->put($staging . '/manifest.json', $manifestJson);

        $this->createArchive($staging, $output);

        $this->files->remove($staging);

        Writer::info('Backup created at: ' . $output);

        return $output;
    }

    /**
     * Restore a previously created backup archive.
     *
     * @param string $archive Path to the backup archive.
     * @param bool $force Skip the confirmation prompt when true.
     */
    public function restore(string $archive, bool $force = false): void
    {
        if (!$this->files->exists($archive)) {
            Writer::error('Backup archive not found: ' . $archive);

            return;
        }

        if (!$force && !Writer::confirm('This will overwrite your current Valet configuration and databases. Are you sure you want to continue?', false)) {
            Writer::warn('Restore aborted.');

            return;
        }

        $extractDir = sys_get_temp_dir() . '/valet-restore-' . uniqid();
        $this->files->ensureDirExists($extractDir);

        $this->cli->run(
            'tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($extractDir),
            function ($statusCode, $output) use ($archive, $extractDir) {
                Writer::warn('tar extraction failed, attempting unzip fallback: ' . $output);
                $this->cli->run('unzip -o ' . escapeshellarg($archive) . ' -d ' . escapeshellarg($extractDir));
            }
        );

        // Restore the configuration file (backing up the current one first).
        if ($this->files->exists($extractDir . '/config.json')) {
            $this->files->backup(VALET_HOME_PATH . '/config.json');
            $this->files->copy($extractDir . '/config.json', VALET_HOME_PATH . '/config.json');
        }

        $this->restoreDirectory($extractDir . '/Nginx', VALET_HOME_PATH . '/Nginx');
        $this->restoreDirectory($extractDir . '/Certificates', VALET_HOME_PATH . '/Certificates');

        $this->restoreDatabases($extractDir);

        $this->files->remove($extractDir);

        Writer::info('Restore complete. You may want to restart the Nginx and PHP services.');
    }

    /**
     * List all existing backup archives.
     *
     * @return array<int, string>
     */
    public function listBackups(): array
    {
        $this->files->ensureDirExists(self::BACKUP_DIR, user());

        if (!$this->files->isDir(self::BACKUP_DIR)) {
            return [];
        }

        return collect($this->files->scandir(self::BACKUP_DIR))
            ->filter(function ($file) {
                return str_ends_with($file, '.tar.gz');
            })
            ->map(function ($file) {
                return self::BACKUP_DIR . '/' . $file;
            })
            ->values()
            ->all();
    }

    /**
     * Dump the configured databases into the staging directory.
     *
     * @param array<string, array<int, string>> $databases
     */
    private function backupDatabases(string $staging, array &$databases): bool
    {
        $any = false;

        $mysqlDir = $staging . '/Databases/mysql';
        try {
            /** @var array<int, mixed> $mysqlDatabases */
            $mysqlDatabases = $this->mysql->getDatabases();
        } catch (\Throwable $e) {
            $mysqlDatabases = [];
            Writer::warn('Could not list MySQL databases: ' . $e->getMessage());
        }

        foreach ($mysqlDatabases as $row) {
            $db = $this->dbNameFromRow($row);
            if ($db === '') {
                continue;
            }

            try {
                $result = $this->mysql->exportDatabase($db, true);
                $filename = $result['filename'] ?? null;
                if (is_string($filename) && $this->files->exists($filename)) {
                    $this->files->ensureDirExists($mysqlDir);
                    $target = $mysqlDir . '/' . $db . '.sql';
                    $this->files->copy($filename, $target);
                    $this->files->unlink($filename);
                    $databases['mysql'][] = $db;
                    $any = true;
                }
            } catch (\Throwable $e) {
                Writer::warn("Failed to export MySQL database [$db]: " . $e->getMessage());
            }
        }

        $postgresDir = $staging . '/Databases/postgres';
        try {
            /** @var array<int, mixed> $postgresDatabases */
            $postgresDatabases = $this->postgres->getDatabases();
        } catch (\Throwable $e) {
            $postgresDatabases = [];
            Writer::warn('Could not list PostgreSQL databases: ' . $e->getMessage());
        }

        foreach ($postgresDatabases as $row) {
            $db = $this->dbNameFromRow($row);
            if ($db === '') {
                continue;
            }

            try {
                $result = $this->postgres->exportDatabase($db, true);
                $filename = $result['filename'] ?? null;
                if (is_string($filename) && $this->files->exists($filename)) {
                    $this->files->ensureDirExists($postgresDir);
                    $target = $postgresDir . '/' . $db . '.sql';
                    $this->files->copy($filename, $target);
                    $this->files->unlink($filename);
                    $databases['postgres'][] = $db;
                    $any = true;
                }
            } catch (\Throwable $e) {
                Writer::warn("Failed to export PostgreSQL database [$db]: " . $e->getMessage());
            }
        }

        return $any;
    }

    /**
     * Coerce a database name row (as returned by getDatabases) into a string.
     *
     * @param mixed $row
     */
    private function dbNameFromRow($row): string
    {
        $value = is_array($row) ? ($row[0] ?? '') : $row;

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Recursively copy a directory's contents from one location to another,
     * creating the destination if necessary.
     */
    private function restoreDirectory(string $from, string $to): void
    {
        if (!$this->files->isDir($from)) {
            return;
        }

        $this->files->ensureDirExists($to);

        $items = $this->files->scandir($from);
        foreach ($items as $item) {
            $src = $from . '/' . $item;
            $dst = $to . '/' . $item;

            if ($this->files->isDir($src)) {
                $this->restoreDirectory($src, $dst);
            } else {
                $this->files->copy($src, $dst);
            }
        }
    }

    /**
     * Import database dumps from the extracted archive, using the manifest to
     * map dump files back to their database names.
     */
    private function restoreDatabases(string $extractDir): void
    {
        $manifestFile = $extractDir . '/manifest.json';
        $manifest = [];
        if ($this->files->exists($manifestFile)) {
            $decoded = json_decode($this->files->get($manifestFile), true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        if (!($manifest['includes_db'] ?? false)) {
            return;
        }

        $mysqlDir = $extractDir . '/Databases/mysql';
        if ($this->files->isDir($mysqlDir)) {
            $databases = $manifest['databases'] ?? [];
            $mysqlDatabases = is_array($databases) ? ($databases['mysql'] ?? []) : [];
            if (!is_array($mysqlDatabases)) {
                $mysqlDatabases = [];
            }
            foreach ($mysqlDatabases as $db) {
                $db = $this->dbNameFromRow($db);
                $file = $mysqlDir . '/' . $db . '.sql';
                if ($this->files->exists($file)) {
                    try {
                        $this->mysql->importDatabase($file, $db);
                    } catch (\Throwable $e) {
                        Writer::warn("Failed to import MySQL database [$db]: " . $e->getMessage());
                    }
                }
            }
        }

        $postgresDir = $extractDir . '/Databases/postgres';
        if ($this->files->isDir($postgresDir)) {
            $databases = $manifest['databases'] ?? [];
            $postgresDatabases = is_array($databases) ? ($databases['postgres'] ?? []) : [];
            if (!is_array($postgresDatabases)) {
                $postgresDatabases = [];
            }
            foreach ($postgresDatabases as $db) {
                $db = $this->dbNameFromRow($db);
                $file = $postgresDir . '/' . $db . '.sql';
                if ($this->files->exists($file)) {
                    try {
                        $this->postgres->importDatabase($file, $db);
                    } catch (\Throwable $e) {
                        Writer::warn("Failed to import PostgreSQL database [$db]: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Create a gzipped tar archive of the staging directory. Falls back to
     * ZipArchive when tar is unavailable or fails.
     */
    private function createArchive(string $staging, string $output): void
    {
        $this->cli->run(
            'tar -czf ' . escapeshellarg($output) . ' -C ' . escapeshellarg($staging) . ' .',
            function ($statusCode, $outputError) use ($staging, $output) {
                Writer::warn('tar archive creation failed, falling back to ZipArchive: ' . $outputError);
                $this->createArchiveZip($staging, $output);
            }
        );
    }

    /**
     * Create a zip archive as a fallback when tar is unavailable.
     */
    private function createArchiveZip(string $staging, string $output): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($output, \ZipArchive::CREATE) !== true) {
            Writer::error('Could not create backup archive at: ' . $output);

            return;
        }

        $this->addDirToZip($zip, $staging, $staging);
        $zip->close();
    }

    /**
     * Recursively add a directory to an open zip archive.
     */
    private function addDirToZip(\ZipArchive $zip, string $dir, string $base): void
    {
        $items = $this->files->scandir($dir);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            $relative = ltrim(substr($path, strlen($base)), '/');

            if ($this->files->isDir($path)) {
                $zip->addEmptyDir($relative);
                $this->addDirToZip($zip, $path, $base);
            } else {
                $zip->addFile($path, $relative);
            }
        }
    }

    /**
     * Determine the current Valet version from composer.json.
     */
    private function version(): string
    {
        try {
            $composer = $this->files->get(VALET_ROOT_PATH . '/composer.json');
            $decoded = json_decode($composer, true);
            if (is_array($decoded) && isset($decoded['version']) && is_string($decoded['version'])) {
                return $decoded['version'];
            }
        } catch (\Throwable $e) {
            // Ignore and fall through to the unknown fallback.
        }

        return 'unknown';
    }
}
