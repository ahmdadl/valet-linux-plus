<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Drivers\ValetDriver;
use Valet\Facades\Environment as EnvironmentFacade;
use Valet\Facades\Mysql as MysqlFacade;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\Postgres as PostgresFacade;
use Valet\Facades\ProjectContext as ProjectContextFacade;
use Valet\Facades\SiteIsolate as SiteIsolateFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

class Init
{
    public function __construct(
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $config
    ) {
    }

    /**
     * Bootstrap a project for local Valet development.
     *
     * @return array<int, string>
     */
    public function run(
        bool $db = false,
        bool $migrate = false,
        bool $composer = false,
        ?string $isolate = null,
        bool $secure = false,
        bool $force = false,
        bool $pg = false
    ): array {
        $sitePath = rtrim((string) getcwd(), '/');
        $context = ProjectContextFacade::fromCwd();
        $site = (string) $context['site'];
        $host = (string) $context['url'];
        $steps = [];

        Writer::info(sprintf('Initializing [%s] (%s)', $site, $context['framework'] ?: 'unknown'));

        $driver = $this->resolveDriver($sitePath, $site);
        $dbName = $this->sanitizeDatabaseName($site);

        if ($db) {
            $steps[] = $this->ensureDatabase($dbName, $force, $pg);
        }

        if ($db || $this->files->exists($sitePath . '/.env.example') || $this->files->exists($sitePath . '/.env')) {
            $keys = $this->databaseEnvKeys($driver, $sitePath, $dbName, $pg);
            $result = EnvironmentFacade::ensureAndWrite($sitePath, $keys, $force);
            if ($result['created']) {
                $steps[] = sprintf('Created %s', $result['path']);
            } elseif ($result['updated']) {
                $steps[] = sprintf('Updated %s', $result['path']);
            } elseif ($this->files->exists($result['path']) && !$force) {
                Writer::warn('.env already exists; skipping overwrite (use --force)');
                $steps[] = 'Skipped .env overwrite';
            }
        }

        if ($composer) {
            $steps[] = $this->runComposerInstall($sitePath, $host);
        }

        if ($migrate) {
            $steps[] = $this->runDriverCommand($driver, $sitePath, $host, 'migrate');
        }

        if ($isolate !== null && $isolate !== '') {
            $ok = SiteIsolateFacade::isolateDirectory($site, $isolate, $secure);
            $steps[] = $ok
                ? sprintf('Isolated site to PHP %s', $isolate)
                : sprintf('Failed to isolate site to PHP %s', $isolate);
            if ($ok && $secure) {
                $secure = false; // already secured via isolate
            }
        }

        if ($secure) {
            SiteSecureFacade::secure($host);
            $steps[] = sprintf('Secured %s', $host);
        }

        if ($steps === []) {
            Writer::warn('Nothing to do. Pass --db, --migrate, --composer, --isolate, and/or --secure.');
        } else {
            foreach ($steps as $step) {
                Writer::info('✓ ' . $step);
            }
            Writer::info('Init complete.');
        }

        return $steps;
    }

    private function resolveDriver(string $sitePath, string $site): ValetDriver
    {
        $driver = ValetDriver::assign($sitePath, $site, '/');

        if ($driver instanceof ValetDriver) {
            return $driver;
        }

        // Fallback basic driver for plain PHP / unknown projects.
        return new \Valet\Drivers\BasicValetDriver();
    }

    private function ensureDatabase(string $name, bool $force, bool $pg): string
    {
        if ($pg) {
            if (PostgresFacade::isDatabaseExists($name)) {
                if (!$force) {
                    Writer::warn(sprintf('Database [%s] already exists; skipping (use --force)', $name));

                    return sprintf('Skipped existing Postgres database [%s]', $name);
                }
                PostgresFacade::dropDatabase($name);
            }

            $created = PostgresFacade::createDatabase($name);

            return $created
                ? sprintf('Created Postgres database [%s]', $name)
                : sprintf('Failed to create Postgres database [%s]', $name);
        }

        if (MysqlFacade::isDatabaseExists($name)) {
            if (!$force) {
                Writer::warn(sprintf('Database [%s] already exists; skipping (use --force)', $name));

                return sprintf('Skipped existing MySQL database [%s]', $name);
            }
            MysqlFacade::dropDatabase($name);
        }

        $created = MysqlFacade::createDatabase($name);

        return $created
            ? sprintf('Created MySQL database [%s]', $name)
            : sprintf('Failed to create MySQL database [%s]', $name);
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvKeys(ValetDriver $driver, string $sitePath, string $dbName, bool $pg): array
    {
        if ($pg) {
            /** @var array<string, mixed> $config */
            $config = $this->config->get('pgsql', []);
            $db = [
                'connection' => 'pgsql',
                'host' => is_scalar($config['host'] ?? null) ? (string) $config['host'] : '127.0.0.1',
                'port' => is_scalar($config['port'] ?? null) ? (string) $config['port'] : '5432',
                'database' => $dbName,
                'username' => is_scalar($config['user'] ?? null) ? (string) $config['user'] : 'valet',
                'password' => is_scalar($config['password'] ?? null) ? (string) $config['password'] : '',
            ];
        } else {
            /** @var array<string, mixed> $config */
            $config = $this->config->get('mysql', []);
            $db = [
                'connection' => 'mysql',
                'host' => is_scalar($config['host'] ?? null) ? (string) $config['host'] : '127.0.0.1',
                'port' => is_scalar($config['port'] ?? null) ? (string) $config['port'] : '3306',
                'database' => $dbName,
                'username' => is_scalar($config['user'] ?? null) ? (string) $config['user'] : 'valet',
                'password' => is_scalar($config['password'] ?? null) ? (string) $config['password'] : '',
            ];
        }

        return $driver->envKeys($sitePath, $db);
    }

    private function runComposerInstall(string $sitePath, string $host): string
    {
        if (!$this->files->exists($sitePath . '/composer.json')) {
            Writer::warn('No composer.json found; skipping composer install');

            return 'Skipped composer install';
        }

        $php = $this->phpBinary($host);
        $composer = trim($this->cli->run('command -v composer 2>/dev/null')) ?: 'composer';
        $command = sprintf(
            'cd %s && %s %s install --no-interaction',
            escapeshellarg($sitePath),
            escapeshellarg($php),
            escapeshellarg($composer)
        );

        $failed = false;
        $this->cli->runAsUser($command, function ($code, $output) use (&$failed) {
            $failed = true;
            Writer::warn(trim((string) $output) !== '' ? trim((string) $output) : 'composer install failed');
        }, 600);

        return $failed ? 'composer install failed' : 'Ran composer install';
    }

    private function runDriverCommand(ValetDriver $driver, string $sitePath, string $host, string $name): string
    {
        $commands = $driver->initCommands($sitePath);
        if (!isset($commands[$name]) || $commands[$name] === []) {
            Writer::warn(sprintf('No [%s] command for this driver; skipping', $name));

            return sprintf('Skipped %s', $name);
        }

        $php = $this->phpBinary($host);
        $argv = $commands[$name];
        $escaped = array_map('escapeshellarg', $argv);
        $command = sprintf(
            'cd %s && %s %s',
            escapeshellarg($sitePath),
            escapeshellarg($php),
            implode(' ', $escaped)
        );

        $failed = false;
        $this->cli->runAsUser($command, function ($code, $output) use (&$failed, $name) {
            $failed = true;
            Writer::warn(trim((string) $output) !== '' ? trim((string) $output) : sprintf('%s failed', $name));
        }, 600);

        return $failed ? sprintf('%s failed', $name) : sprintf('Ran %s', $name);
    }

    private function phpBinary(string $host): string
    {
        $version = null;
        try {
            $version = SiteIsolateFacade::isolatedPhpVersion($host);
        } catch (\Throwable $e) {
            $version = null;
        }

        $php = PhpFpmFacade::getPhpExecutablePath(is_string($version) ? $version : null);

        return is_string($php) && $php !== '' ? $php : 'php';
    }

    private function sanitizeDatabaseName(string $site): string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $site) ?? $site);

        return trim($name, '_') ?: 'valet';
    }
}
