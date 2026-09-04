<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Facades\Environment as EnvironmentFacade;
use Valet\Facades\ProjectContext as ProjectContextFacade;

class Sqlite
{
    public const DEFAULT_RELATIVE_PATH = 'database/database.sqlite';

    public function __construct(
        public Filesystem $files,
        public Configuration $config
    ) {
    }

    /**
     * Create a SQLite database file and optionally update `.env`.
     *
     * @return array<int, string>
     */
    public function create(?string $name = null, ?string $relativePath = null, bool $env = false): array
    {
        $sitePath = rtrim((string) getcwd(), '/');
        $context = ProjectContextFacade::fromCwd();
        $site = $name !== null && $name !== '' ? $name : (string) $context['site'];
        $relative = $relativePath !== null && $relativePath !== '' ? ltrim($relativePath, '/') : self::DEFAULT_RELATIVE_PATH;
        $absolute = $sitePath . '/' . $relative;
        $steps = [];

        if (!$this->pdoSqliteAvailable()) {
            Writer::warn('PHP extension pdo_sqlite is not loaded. Install php-sqlite3 / pdo_sqlite for your PHP version.');
        }

        $dir = dirname($absolute);
        if (!$this->files->isDir($dir)) {
            $this->files->mkdirAsUser($dir);
            $steps[] = sprintf('Created directory [%s]', $dir);
        }

        if (!$this->files->exists($absolute)) {
            $this->files->putAsUser($absolute, '');
            $steps[] = sprintf('Created SQLite database [%s]', $absolute);
        } else {
            $steps[] = sprintf('SQLite database already exists [%s]', $absolute);
        }

        @chmod($absolute, 0664);

        if ($env) {
            $keys = [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $absolute,
            ];
            $result = EnvironmentFacade::ensureAndWrite($sitePath, $keys, true);
            $steps[] = $result['created']
                ? sprintf('Created %s for SQLite', $result['path'])
                : sprintf('Updated %s for SQLite', $result['path']);
        }

        foreach ($steps as $step) {
            Writer::info('✓ ' . $step);
        }

        Writer::info(sprintf('SQLite ready for [%s].', $site));

        return $steps;
    }

    /**
     * Recreate the SQLite file (never touches MySQL/Postgres).
     *
     * @return array<int, string>
     */
    public function reset(bool $yes = false): array
    {
        $sitePath = rtrim((string) getcwd(), '/');
        $absolute = $this->resolveDatabasePath($sitePath);

        if (!$yes) {
            $confirm = Writer::confirm(sprintf('Recreate SQLite database [%s]?', $absolute));
            if (!$confirm) {
                Writer::warn('Aborted');

                return ['Aborted'];
            }
        }

        $dir = dirname($absolute);
        if (!$this->files->isDir($dir)) {
            $this->files->mkdirAsUser($dir);
        }

        if ($this->files->exists($absolute)) {
            $this->files->unlink($absolute);
        }

        $this->files->putAsUser($absolute, '');
        @chmod($absolute, 0664);

        $step = sprintf('Recreated SQLite database [%s]', $absolute);
        Writer::info('✓ ' . $step);

        return [$step];
    }

    public function pdoSqliteAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    /**
     * Absolute path currently configured or default Laravel path.
     */
    public function resolveDatabasePath(string $sitePath): string
    {
        $envPath = rtrim($sitePath, '/') . '/.env';
        if ($this->files->exists($envPath)) {
            $contents = $this->files->get($envPath);
            if (preg_match('/^DB_CONNECTION\s*=\s*sqlite\s*$/mi', $contents)
                && preg_match('/^DB_DATABASE\s*=\s*(.+)$/mi', $contents, $m)) {
                $db = trim($m[1], " \t\"'");
                if ($db !== '' && $db !== ':memory:') {
                    if ($db[0] === '/' || preg_match('#^[A-Za-z]:\\\\#', $db)) {
                        return $db;
                    }

                    return rtrim($sitePath, '/') . '/' . ltrim($db, '/');
                }
            }
        }

        return rtrim($sitePath, '/') . '/' . self::DEFAULT_RELATIVE_PATH;
    }
}
