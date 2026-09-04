<?php

namespace Valet;

use ConsoleComponents\Writer;
use InvalidArgumentException;
use Valet\Facades\Mysql as MysqlFacade;
use Valet\Facades\Profile as ProfileFacade;
use Valet\Facades\ProjectContext as ProjectContextFacade;

class Snapshot
{
    public function __construct(
        public Filesystem $files,
        public Configuration $config,
        public CommandLine $cli
    ) {
    }

    /**
     * Create a per-project snapshot.
     *
     * @return string Absolute path to the snapshot directory
     */
    public function create(?string $name = null, bool $withDb = false, ?string $notes = null): string
    {
        $context = ProjectContextFacade::fromCwd();
        $site = (string) $context['site'];
        if ($site === '') {
            throw new InvalidArgumentException('Could not resolve current project site name');
        }

        $name = $this->sanitizeName($name ?: ('snap-' . date('Ymd-His')));
        $dir = $this->snapshotDir($site, $name);

        if ($this->files->isDir($dir)) {
            throw new InvalidArgumentException(sprintf('Snapshot [%s] already exists for project [%s]', $name, $site));
        }

        $this->files->ensureDirExists($dir, user());

        $projectPath = rtrim((string) getcwd(), '/');
        $profileSrc = $projectPath . '/.valet/profile.json';
        if ($this->files->exists($profileSrc)) {
            $this->files->putAsUser($dir . '/profile.json', $this->files->get($profileSrc));
        }

        $envPath = ProjectContextFacade::envPath();
        if (is_string($envPath) && $this->files->exists($envPath)) {
            $this->files->putAsUser($dir . '/env.redacted', $this->redactEnv($this->files->get($envPath)));
        }

        $includesDb = false;
        $databaseName = $this->sanitizeDatabaseName($site);
        if ($withDb) {
            try {
                $export = MysqlFacade::exportDatabase($databaseName, true);
                $filename = (string) ($export['filename'] ?? '');
                if ($filename !== '' && $this->files->exists($filename)) {
                    $contents = $this->files->get($filename);
                    $this->files->putAsUser($dir . '/database.sql', $contents);
                    @unlink($filename);
                    $includesDb = true;
                }
            } catch (\Throwable $e) {
                Writer::warn('Database dump skipped: ' . $e->getMessage());
            }
        }

        $manifest = [
            'name' => $name,
            'site' => $site,
            'project_path' => $projectPath,
            'url' => (string) $context['url'],
            'timestamp' => gmdate('c'),
            'valet_version' => $this->valetVersion(),
            'includes_db' => $includesDb,
            'database' => $includesDb ? $databaseName : null,
            'notes' => $notes,
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $this->files->putAsUser($dir . '/manifest.json', $json);

        Writer::info(sprintf('Snapshot [%s] created at %s', $name, $dir));

        return $dir;
    }

    /**
     * @return array<int, array{name: string, site: string, timestamp: string, includes_db: bool, notes: string|null, path: string}>
     */
    public function list(?string $site = null): array
    {
        $site = $site !== null && $site !== '' ? $site : (string) ProjectContextFacade::fromCwd()['site'];
        $root = $this->projectRoot($site);
        if (!$this->files->isDir($root)) {
            return [];
        }

        $rows = [];
        foreach ($this->files->scandir($root) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $root . '/' . $entry;
            if (!$this->files->isDir($dir)) {
                continue;
            }
            $manifest = $this->readManifest($dir);
            $rows[] = [
                'name' => is_string($manifest['name'] ?? null) ? (string) $manifest['name'] : $entry,
                'site' => $site,
                'timestamp' => is_string($manifest['timestamp'] ?? null) ? (string) $manifest['timestamp'] : '',
                'includes_db' => !empty($manifest['includes_db']),
                'notes' => isset($manifest['notes']) && is_scalar($manifest['notes']) ? (string) $manifest['notes'] : null,
                'path' => $dir,
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($b['timestamp'], $a['timestamp']));

        return $rows;
    }

    public function runList(): void
    {
        $rows = $this->list();
        if ($rows === []) {
            Writer::info('No snapshots for this project.');

            return;
        }

        Writer::table(
            ['Name', 'Timestamp', 'DB', 'Notes'],
            array_map(fn (array $row) => [
                $row['name'],
                $row['timestamp'],
                $row['includes_db'] ? 'yes' : 'no',
                $row['notes'] ?? '—',
            ], $rows)
        );
    }

    public function restore(string $name, bool $force = false): void
    {
        $context = ProjectContextFacade::fromCwd();
        $site = (string) $context['site'];
        $dir = $this->snapshotDir($site, $this->sanitizeName($name));

        if (!$this->files->isDir($dir) || !$this->files->exists($dir . '/manifest.json')) {
            throw new InvalidArgumentException(sprintf('Snapshot [%s] not found for project [%s]', $name, $site));
        }

        if (!$force && !Writer::confirm(sprintf('Restore snapshot [%s]? This may overwrite the database and profile.', $name), false)) {
            Writer::warn('Restore aborted.');

            return;
        }

        $projectPath = rtrim((string) getcwd(), '/');
        $profileSnap = $dir . '/profile.json';
        if ($this->files->exists($profileSnap)) {
            $this->files->ensureDirExists($projectPath . '/.valet', user());
            $this->files->putAsUser($projectPath . '/.valet/profile.json', $this->files->get($profileSnap));
            try {
                ProfileFacade::apply(ProfileFacade::show(null));
            } catch (\Throwable $e) {
                Writer::warn('Profile apply: ' . $e->getMessage());
            }
        }

        $dbFile = $dir . '/database.sql';
        $manifest = $this->readManifest($dir);
        if ($this->files->exists($dbFile)) {
            $database = is_string($manifest['database'] ?? null)
                ? (string) $manifest['database']
                : $this->sanitizeDatabaseName($site);
            try {
                if (!MysqlFacade::isDatabaseExists($database)) {
                    MysqlFacade::createDatabase($database);
                }
                MysqlFacade::importDatabase($dbFile, $database);
                Writer::info(sprintf('Restored database [%s]', $database));
            } catch (\Throwable $e) {
                Writer::warn('Database restore: ' . $e->getMessage());
            }
        }

        Writer::info(sprintf('Snapshot [%s] restored.', $name));
    }

    public function delete(string $name): void
    {
        $site = (string) ProjectContextFacade::fromCwd()['site'];
        $dir = $this->snapshotDir($site, $this->sanitizeName($name));

        if (!$this->files->isDir($dir)) {
            throw new InvalidArgumentException(sprintf('Snapshot [%s] not found', $name));
        }

        $this->files->remove($dir);
        Writer::info(sprintf('Snapshot [%s] deleted.', $name));
    }

    private function snapshotDir(string $site, string $name): string
    {
        return $this->projectRoot($site) . '/' . $name;
    }

    private function projectRoot(string $site): string
    {
        return VALET_HOME_PATH . '/snapshots/' . $this->sanitizeName($site);
    }

    private function sanitizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9._-]+/', '-', $name) ?? $name;
        $name = trim($name, '-.');

        if ($name === '') {
            throw new InvalidArgumentException('Invalid snapshot name');
        }

        return $name;
    }

    private function sanitizeDatabaseName(string $name): string
    {
        return str_replace('-', '_', strtolower($name));
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $dir): array
    {
        $path = $dir . '/manifest.json';
        if (!$this->files->exists($path)) {
            return [];
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function redactEnv(string $contents): string
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (!str_contains($line, '=')) {
                $out[] = $line;
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $upper = strtoupper(trim($key));
            if (preg_match('/(PASSWORD|SECRET|TOKEN|KEY|PRIVATE)/', $upper)) {
                $out[] = $key . '=********';
            } else {
                $out[] = $key . '=' . $value;
            }
        }

        return implode("\n", $out) . "\n";
    }

    private function valetVersion(): string
    {
        try {
            if (!defined('VALET_ROOT_PATH') || !$this->files->exists(VALET_ROOT_PATH . '/composer.json')) {
                return 'unknown';
            }
            $composer = $this->files->get(VALET_ROOT_PATH . '/composer.json');
            $decoded = json_decode($composer, true);
            if (is_array($decoded) && isset($decoded['version']) && is_string($decoded['version'])) {
                return $decoded['version'];
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return 'unknown';
    }
}
