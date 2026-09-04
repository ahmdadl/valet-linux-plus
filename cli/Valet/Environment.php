<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\ProjectContext as ProjectContextFacade;
use Valet\Facades\SiteIsolate as SiteIsolateFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

class Environment
{
    public function __construct(
        public Configuration $config,
        public Filesystem $files
    ) {
    }

    /**
     * Gather environment variables for the current project.
     *
     * Project `.env` values win when present; Valet config fills the gaps.
     *
     * @return array<string, string>
     */
    public function gather(): array
    {
        $context = ProjectContextFacade::fromCwd();
        $site = (string) $context['site'];
        $host = (string) $context['url'];
        $domainRaw = $this->config->get('domain', 'test');
        $domain = is_scalar($domainRaw) ? (string) $domainRaw : 'test';

        $secured = false;
        try {
            $secured = SiteSecureFacade::secured()->contains($host);
        } catch (\Throwable $e) {
            $secured = false;
        }

        $scheme = $secured ? 'https' : 'http';
        $siteUrl = sprintf('%s://%s', $scheme, $host);

        $phpVersion = $this->resolvePhpVersion($host, $site);

        $mysql = $this->mysqlDefaults($site);
        $defaults = [
            'SITE_URL' => $siteUrl,
            'PHP_VERSION' => $phpVersion,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $mysql['host'],
            'DB_PORT' => $mysql['port'],
            'DB_DATABASE' => $mysql['database'],
            'DB_USERNAME' => $mysql['username'],
            'DB_PASSWORD' => $mysql['password'],
            'REDIS_URL' => 'redis://127.0.0.1:6379',
            'MAIL_URL' => sprintf('https://mails.%s', $domain),
        ];

        $fromEnv = $this->readDotEnv();

        return array_merge($defaults, $fromEnv);
    }

    /**
     * Print a MySQL (or Postgres) connection URL.
     */
    public function databaseUrl(bool $postgres = false): string
    {
        $vars = $this->gather();

        if ($postgres || ($vars['DB_CONNECTION'] ?? '') === 'pgsql') {
            $user = rawurlencode($vars['DB_USERNAME'] ?? 'valet');
            $pass = rawurlencode($vars['DB_PASSWORD'] ?? '');
            $host = $vars['DB_HOST'] ?? '127.0.0.1';
            $port = $vars['DB_PORT'] ?? '5432';
            $database = $vars['DB_DATABASE'] ?? '';

            return sprintf('pgsql://%s:%s@%s:%s/%s', $user, $pass, $host, $port, $database);
        }

        $user = rawurlencode($vars['DB_USERNAME'] ?? 'valet');
        $pass = rawurlencode($vars['DB_PASSWORD'] ?? '');
        $host = $vars['DB_HOST'] ?? '127.0.0.1';
        $port = $vars['DB_PORT'] ?? '3306';
        $database = $vars['DB_DATABASE'] ?? '';

        return sprintf('mysql://%s:%s@%s:%s/%s', $user, $pass, $host, $port, $database);
    }

    /**
     * Render gathered env vars for the requested format.
     *
     * @param array<string, string> $vars
     */
    public function export(array $vars, string $format = 'dotenv'): string
    {
        $format = strtolower($format);

        return match ($format) {
            'shell' => $this->toShell($vars),
            'json' => (string) json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            default => $this->toDotenv($vars),
        };
    }

    /**
     * Run the env command and write output.
     */
    public function run(bool $json = false, ?string $export = null, bool $printDbUrl = false): void
    {
        if ($printDbUrl) {
            Writer::info($this->databaseUrl());

            return;
        }

        $vars = $this->gather();

        if ($json || $export === 'json') {
            Writer::info((string) json_encode(
                JsonSchema::envelope('env', $vars),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return;
        }

        if ($export !== null) {
            Writer::info($this->export($vars, $export));

            return;
        }

        Writer::info($this->toDotenv($vars));
    }

    /**
     * @return array<string, string>
     */
    private function mysqlDefaults(string $site): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('mysql', []);

        return [
            'host' => is_scalar($config['host'] ?? null) ? (string) $config['host'] : '127.0.0.1',
            'port' => is_scalar($config['port'] ?? null) ? (string) $config['port'] : '3306',
            'database' => $this->sanitizeDatabaseName($site),
            'username' => is_scalar($config['user'] ?? null) ? (string) $config['user'] : 'valet',
            'password' => is_scalar($config['password'] ?? null) ? (string) $config['password'] : '',
        ];
    }

    private function sanitizeDatabaseName(string $site): string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $site) ?? $site);

        return trim($name, '_') ?: 'valet';
    }

    private function resolvePhpVersion(string $host, string $site): string
    {
        try {
            $isolated = SiteIsolateFacade::isolatedPhpVersion($host);
            if (is_string($isolated) && $isolated !== '') {
                return PhpFpmFacade::normalizePhpVersion($isolated);
            }
        } catch (\Throwable $e) {
            // fall through
        }

        try {
            $configured = $this->config->get('php_version');
            if (is_string($configured) && $configured !== '') {
                return $configured;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        try {
            return PhpFpmFacade::getCurrentVersion();
        } catch (\Throwable $e) {
            return PHP_VERSION;
        }
    }

    /**
     * Ensure `.env` exists (copy from `.env.example` when available) and upsert keys.
     *
     * @param array<string, string> $keys
     * @return array{path: string, created: bool, updated: bool}
     */
    public function ensureAndWrite(string $sitePath, array $keys, bool $force = false): array
    {
        $envPath = rtrim($sitePath, '/') . '/.env';
        $examplePath = rtrim($sitePath, '/') . '/.env.example';
        $created = false;

        if (!$this->files->exists($envPath)) {
            if ($this->files->exists($examplePath)) {
                $this->files->putAsUser($envPath, $this->files->get($examplePath));
            } else {
                $this->files->putAsUser($envPath, '');
            }
            $created = true;
        } elseif (!$force && $keys === []) {
            return ['path' => $envPath, 'created' => false, 'updated' => false];
        }

        $updated = $this->upsertDotEnvFile($envPath, $keys, $force || $created);

        return ['path' => $envPath, 'created' => $created, 'updated' => $updated];
    }

    /**
     * Upsert key/value pairs in a dotenv file.
     *
     * @param array<string, string> $keys
     */
    public function upsertDotEnvFile(string $envPath, array $keys, bool $overwrite = true): bool
    {
        if ($keys === []) {
            return false;
        }

        $contents = $this->files->exists($envPath) ? $this->files->get($envPath) : '';
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $seen = [];
        $changed = false;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$key] = explode('=', $trimmed, 2);
            $key = trim($key);
            if (!array_key_exists($key, $keys)) {
                continue;
            }

            $seen[$key] = true;
            $newLine = $key . '=' . $keys[$key];
            if ($overwrite && $lines[$index] !== $newLine) {
                $lines[$index] = $newLine;
                $changed = true;
            }
        }

        foreach ($keys as $key => $value) {
            if (isset($seen[$key])) {
                continue;
            }
            $lines[] = $key . '=' . $value;
            $changed = true;
        }

        if ($changed) {
            $this->files->putAsUser($envPath, implode(PHP_EOL, $lines) . PHP_EOL);
        }

        return $changed;
    }

    /**
     * @return array<string, string>
     */
    private function readDotEnv(): array
    {
        $path = ProjectContextFacade::envPath();
        if ($path === false || !$this->files->exists($path)) {
            return [];
        }

        $contents = $this->files->get($path);
        $vars = [];

        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }

            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $vars[$key] = $value;
        }

        return $vars;
    }

    /**
     * @param array<string, string> $vars
     */
    private function toDotenv(array $vars): string
    {
        $lines = [];
        foreach ($vars as $key => $value) {
            $lines[] = sprintf('%s=%s', $key, $value);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string, string> $vars
     */
    private function toShell(array $vars): string
    {
        $lines = [];
        foreach ($vars as $key => $value) {
            $lines[] = sprintf('export %s=%s', $key, escapeshellarg($value));
        }

        return implode(PHP_EOL, $lines);
    }
}
