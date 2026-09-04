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

class DatabaseSetup
{
    public function __construct(
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $config
    ) {
    }

    /**
     * Create the project database, wire `.env`, optionally migrate/seed.
     *
     * @return array<int, string>
     */
    public function setup(bool $seed = false, bool $pg = false, bool $force = false): array
    {
        $sitePath = rtrim((string) getcwd(), '/');
        $context = ProjectContextFacade::fromCwd();
        $site = (string) $context['site'];
        $host = (string) $context['url'];
        $dbName = $this->sanitizeDatabaseName($site);
        $driver = $this->resolveDriver($sitePath, $site);
        $steps = [];

        $steps[] = $this->ensureDatabase($dbName, $force, $pg);

        $keys = $this->databaseEnvKeys($driver, $sitePath, $dbName, $pg);
        $result = EnvironmentFacade::ensureAndWrite($sitePath, $keys, true);
        $steps[] = $result['created']
            ? sprintf('Created %s', $result['path'])
            : sprintf('Updated %s', $result['path']);

        $steps[] = $this->runDriverCommand($driver, $sitePath, $host, 'migrate');
        if ($seed) {
            $steps[] = $this->runDriverCommand($driver, $sitePath, $host, 'seed');
        }

        foreach ($steps as $step) {
            Writer::info('✓ ' . $step);
        }

        return $steps;
    }

    /**
     * Reset the project database, then migrate (and optionally seed).
     *
     * @return array<int, string>
     */
    public function refresh(bool $seed = false, bool $pg = false, bool $yes = false): array
    {
        $sitePath = rtrim((string) getcwd(), '/');
        $context = ProjectContextFacade::fromCwd();
        $site = (string) $context['site'];
        $host = (string) $context['url'];
        $dbName = $this->sanitizeDatabaseName($site);
        $driver = $this->resolveDriver($sitePath, $site);

        if (!$yes) {
            $confirm = Writer::confirm(sprintf('Reset database [%s] and re-run migrations?', $dbName));
            if (!$confirm) {
                Writer::warn('Aborted');

                return ['Aborted'];
            }
        }

        $steps = [];
        $steps[] = $this->resetDatabase($dbName, $pg);

        $keys = $this->databaseEnvKeys($driver, $sitePath, $dbName, $pg);
        EnvironmentFacade::ensureAndWrite($sitePath, $keys, true);
        $steps[] = 'Synced .env database keys';

        $steps[] = $this->runDriverCommand($driver, $sitePath, $host, 'migrate');
        if ($seed) {
            $steps[] = $this->runDriverCommand($driver, $sitePath, $host, 'seed');
        }

        foreach ($steps as $step) {
            Writer::info('✓ ' . $step);
        }

        return $steps;
    }

    private function resolveDriver(string $sitePath, string $site): ValetDriver
    {
        $driver = ValetDriver::assign($sitePath, $site, '/');

        return $driver instanceof ValetDriver ? $driver : new \Valet\Drivers\BasicValetDriver();
    }

    private function ensureDatabase(string $name, bool $force, bool $pg): string
    {
        if ($pg) {
            if (PostgresFacade::isDatabaseExists($name)) {
                if (!$force) {
                    Writer::warn(sprintf('Database [%s] already exists; skipping create', $name));

                    return sprintf('Using existing Postgres database [%s]', $name);
                }
                PostgresFacade::dropDatabase($name);
            }

            return PostgresFacade::createDatabase($name)
                ? sprintf('Created Postgres database [%s]', $name)
                : sprintf('Failed to create Postgres database [%s]', $name);
        }

        if (MysqlFacade::isDatabaseExists($name)) {
            if (!$force) {
                Writer::warn(sprintf('Database [%s] already exists; skipping create', $name));

                return sprintf('Using existing MySQL database [%s]', $name);
            }
            MysqlFacade::dropDatabase($name);
        }

        return MysqlFacade::createDatabase($name)
            ? sprintf('Created MySQL database [%s]', $name)
            : sprintf('Failed to create MySQL database [%s]', $name);
    }

    private function resetDatabase(string $name, bool $pg): string
    {
        if ($pg) {
            if (PostgresFacade::isDatabaseExists($name)) {
                PostgresFacade::dropDatabase($name);
            }
            $created = PostgresFacade::createDatabase($name);

            return $created
                ? sprintf('Reset Postgres database [%s]', $name)
                : sprintf('Failed to reset Postgres database [%s]', $name);
        }

        if (MysqlFacade::isDatabaseExists($name)) {
            MysqlFacade::dropDatabase($name);
        }
        $created = MysqlFacade::createDatabase($name);

        return $created
            ? sprintf('Reset MySQL database [%s]', $name)
            : sprintf('Failed to reset MySQL database [%s]', $name);
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
