<?php

namespace Valet;

use ConsoleComponents\Writer;
use InvalidArgumentException;
use Valet\Facades\Mysql as MysqlFacade;
use Valet\Facades\Postgres as PostgresFacade;
use Valet\Facades\ProjectContext as ProjectContextFacade;
use Valet\Facades\ServiceRegistry as ServiceRegistryFacade;
use Valet\Facades\SiteIsolate as SiteIsolateFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

class Profile
{
    public const PROJECT_RELATIVE_PATH = '.valet/profile.json';

    /**
     * Allowed top-level profile keys and rough types.
     *
     * @var array<string, string>
     */
    private const SCHEMA = [
        'php' => 'string',
        'secure' => 'boolean',
        'database' => 'object',
        'services' => 'array',
        'node' => 'string',
        'isolate' => 'boolean',
    ];

    /**
     * @var array<string, string>
     */
    private const DATABASE_SCHEMA = [
        'driver' => 'string',
        'name' => 'string',
    ];

    public function __construct(
        public Filesystem $files,
        public Configuration $config
    ) {
    }

    /**
     * Absolute path to the project-local profile file.
     */
    public function projectPath(?string $sitePath = null): string
    {
        $root = rtrim($sitePath ?? (string) getcwd(), '/');

        return $root . '/' . self::PROJECT_RELATIVE_PATH;
    }

    /**
     * Directory for named global profile templates.
     */
    public function globalDirectory(): string
    {
        return VALET_HOME_PATH . '/profiles';
    }

    /**
     * Absolute path to a named global profile.
     */
    public function globalPath(string $name): string
    {
        return $this->globalDirectory() . '/' . $this->sanitizeName($name) . '.json';
    }

    /**
     * List project + global profiles.
     *
     * @return array<int, array{name: string, scope: string, path: string}>
     */
    public function listProfiles(): array
    {
        $rows = [];

        $project = $this->projectPath();
        if ($this->files->exists($project)) {
            $rows[] = [
                'name' => '(project)',
                'scope' => 'project',
                'path' => $project,
            ];
        }

        $dir = $this->globalDirectory();
        if ($this->files->isDir($dir)) {
            foreach ($this->files->scandir($dir) as $file) {
                if (!str_ends_with($file, '.json')) {
                    continue;
                }
                $name = basename($file, '.json');
                $rows[] = [
                    'name' => $name,
                    'scope' => 'global',
                    'path' => $dir . '/' . $file,
                ];
            }
        }

        return $rows;
    }

    /**
     * Load a profile by name (global) or the project profile when name is null/"project".
     *
     * @return array<string, mixed>
     */
    public function show(?string $name = null): array
    {
        $path = $this->resolveReadPath($name);
        if (!$this->files->exists($path)) {
            throw new InvalidArgumentException(sprintf('Profile not found at [%s]', $path));
        }

        return $this->readValidated($path);
    }

    /**
     * Save current (or provided) profile data.
     *
     * Without a name → project `.valet/profile.json`.
     * With a name → global `profiles/{name}.json` (and project copy when in a site).
     *
     * @param array<string, mixed>|null $data
     * @return array{path: string, data: array<string, mixed>}
     */
    public function save(?string $name = null, ?array $data = null): array
    {
        $payload = $data ?? $this->captureCurrent();
        $this->validate($payload);

        if ($name === null || $name === '' || $name === 'project') {
            $path = $this->projectPath();
            $this->files->ensureDirExists(dirname($path), user());
            $this->writeJson($path, $payload);

            return ['path' => $path, 'data' => $payload];
        }

        $global = $this->globalPath($name);
        $this->files->ensureDirExists($this->globalDirectory(), user());
        $this->writeJson($global, $payload);

        // Keep the project copy in sync when saving a named template from a site.
        $project = $this->projectPath();
        $this->files->ensureDirExists(dirname($project), user());
        $this->writeJson($project, $payload);

        return ['path' => $global, 'data' => $payload];
    }

    /**
     * Activate a named global profile for the current project.
     *
     * @return array{path: string, data: array<string, mixed>, steps: array<int, string>}
     */
    public function use(string $name, bool $apply = false): array
    {
        $source = $this->globalPath($name);
        if (!$this->files->exists($source)) {
            // Allow using the project profile by explicit name "project".
            if ($name === 'project' && $this->files->exists($this->projectPath())) {
                $data = $this->readValidated($this->projectPath());
                $steps = $apply ? $this->apply($data) : [];

                return ['path' => $this->projectPath(), 'data' => $data, 'steps' => $steps];
            }

            throw new InvalidArgumentException(sprintf('Global profile [%s] not found', $name));
        }

        $data = $this->readValidated($source);
        $project = $this->projectPath();
        $this->files->ensureDirExists(dirname($project), user());
        $this->writeJson($project, $data);

        $steps = $apply ? $this->apply($data) : [];

        return ['path' => $project, 'data' => $data, 'steps' => $steps];
    }

    /**
     * Delete a named global profile, or the project profile when name is null/"project".
     */
    public function delete(?string $name = null): string
    {
        if ($name === null || $name === '' || $name === 'project') {
            $path = $this->projectPath();
            if (!$this->files->exists($path)) {
                throw new InvalidArgumentException('No project profile to delete');
            }
            $this->files->unlink($path);

            return $path;
        }

        $path = $this->globalPath($name);
        if (!$this->files->exists($path)) {
            throw new InvalidArgumentException(sprintf('Global profile [%s] not found', $name));
        }
        $this->files->unlink($path);

        return $path;
    }

    /**
     * Apply a profile to the current project.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    public function apply(array $data): array
    {
        $this->validate($data);
        $context = ProjectContextFacade::fromCwd();
        $site = (string) $context['site'];
        $host = (string) $context['url'];
        $steps = [];

        $secure = !empty($data['secure']);
        $isolate = array_key_exists('isolate', $data) ? (bool) $data['isolate'] : true;
        $php = isset($data['php']) && is_scalar($data['php']) ? (string) $data['php'] : null;

        if ($php && $isolate) {
            $ok = SiteIsolateFacade::isolateDirectory($site, $php, $secure);
            $steps[] = $ok
                ? sprintf('Isolated [%s] to PHP %s', $site, $php)
                : sprintf('Failed to isolate [%s] to PHP %s', $site, $php);
            if ($ok && $secure) {
                $secure = false;
            }
        }

        if ($secure) {
            SiteSecureFacade::secure($host);
            $steps[] = sprintf('Secured %s', $host);
        }

        if (isset($data['database']) && is_array($data['database'])) {
            $steps[] = $this->applyDatabase($data['database'], $site);
        }

        if (isset($data['services']) && is_array($data['services']) && $data['services'] !== []) {
            $services = array_values(array_filter($data['services'], 'is_string'));
            if ($services !== []) {
                ServiceRegistryFacade::start($services);
                $steps[] = 'Started services: ' . implode(', ', $services);
            }
        }

        if (isset($data['node']) && is_scalar($data['node'])) {
            Writer::warn(sprintf('Profile requests Node %s; install/use via nvm (valet node not available yet)', (string) $data['node']));
            $steps[] = sprintf('Noted Node %s (manual)', (string) $data['node']);
        }

        return $steps;
    }

    /**
     * Capture current project settings into a profile document.
     *
     * @return array<string, mixed>
     */
    public function captureCurrent(): array
    {
        $context = ProjectContextFacade::fromCwd();
        $site = (string) $context['site'];
        $host = (string) $context['url'];

        $php = null;
        try {
            $php = SiteIsolateFacade::isolatedPhpVersion($host);
        } catch (\Throwable $e) {
            $php = null;
        }
        if (!is_string($php) || $php === '') {
            $configured = $this->config->get('php_version');
            $php = is_scalar($configured) ? (string) $configured : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        }

        $secured = false;
        try {
            $secured = SiteSecureFacade::secured()->contains($host);
        } catch (\Throwable $e) {
            $secured = false;
        }

        return [
            'php' => $php,
            'secure' => $secured,
            'database' => [
                'driver' => 'mysql',
                'name' => $this->sanitizeDatabaseName($site),
            ],
            'services' => [],
            'isolate' => true,
        ];
    }

    /**
     * Validate profile data against the built-in schema.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, self::SCHEMA)) {
                throw new InvalidArgumentException(sprintf('Unknown profile key [%s]', (string) $key));
            }

            $expected = self::SCHEMA[$key];
            $ok = match ($expected) {
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && !array_is_list($value),
            };

            if (!$ok) {
                throw new InvalidArgumentException(sprintf('Profile key [%s] must be %s', $key, $expected));
            }
        }

        if (isset($data['database']) && is_array($data['database'])) {
            foreach ($data['database'] as $key => $value) {
                if (!is_string($key) || !array_key_exists($key, self::DATABASE_SCHEMA)) {
                    throw new InvalidArgumentException(sprintf('Unknown database key [%s]', (string) $key));
                }
                if (!is_string($value)) {
                    throw new InvalidArgumentException(sprintf('Database key [%s] must be string', $key));
                }
            }
            if (isset($data['database']['driver']) && !in_array($data['database']['driver'], ['mysql', 'pgsql', 'postgres', 'postgresql'], true)) {
                throw new InvalidArgumentException('database.driver must be mysql or pgsql');
            }
        }

        if (isset($data['services']) && is_array($data['services'])) {
            foreach ($data['services'] as $service) {
                if (!is_string($service) || $service === '') {
                    throw new InvalidArgumentException('services must be a list of non-empty strings');
                }
            }
        }
    }

    /**
     * Human-readable schema description.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'fields' => self::SCHEMA,
            'database_fields' => self::DATABASE_SCHEMA,
            'example' => [
                'php' => '8.3',
                'secure' => true,
                'database' => ['driver' => 'mysql', 'name' => 'my_app'],
                'services' => ['redis', 'mailpit'],
                'node' => '20',
                'isolate' => true,
            ],
        ];
    }

    /**
     * CLI entrypoints.
     */
    public function runList(): void
    {
        $rows = $this->listProfiles();
        if ($rows === []) {
            Writer::warn('No profiles found. Save one with `valet profile save [name]`.');

            return;
        }

        Writer::table(['Name', 'Scope', 'Path'], array_map(function (array $row) {
            return [$row['name'], $row['scope'], $row['path']];
        }, $rows));
    }

    public function runShow(?string $name = null): void
    {
        try {
            $data = $this->show($name);
        } catch (InvalidArgumentException $e) {
            Writer::error($e->getMessage());

            return;
        }

        Writer::info((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function runSave(?string $name = null): void
    {
        try {
            $result = $this->save($name);
        } catch (InvalidArgumentException $e) {
            Writer::error($e->getMessage());

            return;
        }

        Writer::info(sprintf('Profile saved to [%s]', $result['path']));
    }

    public function runUse(string $name, bool $apply = false): void
    {
        try {
            $result = $this->use($name, $apply);
        } catch (InvalidArgumentException $e) {
            Writer::error($e->getMessage());

            return;
        }

        Writer::info(sprintf('Using profile [%s] at [%s]', $name, $result['path']));
        foreach ($result['steps'] as $step) {
            Writer::info('✓ ' . $step);
        }
    }

    public function runDelete(?string $name = null): void
    {
        try {
            $path = $this->delete($name);
        } catch (InvalidArgumentException $e) {
            Writer::error($e->getMessage());

            return;
        }

        Writer::info(sprintf('Deleted profile [%s]', $path));
    }

    /**
     * @param array<string, mixed> $database
     */
    private function applyDatabase(array $database, string $site): string
    {
        $driver = isset($database['driver']) && is_string($database['driver']) ? $database['driver'] : 'mysql';
        $name = isset($database['name']) && is_string($database['name']) && $database['name'] !== ''
            ? $database['name']
            : $this->sanitizeDatabaseName($site);

        $pg = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true);

        if ($pg) {
            if (PostgresFacade::isDatabaseExists($name)) {
                return sprintf('Postgres database [%s] already exists', $name);
            }

            return PostgresFacade::createDatabase($name)
                ? sprintf('Created Postgres database [%s]', $name)
                : sprintf('Failed to create Postgres database [%s]', $name);
        }

        if (MysqlFacade::isDatabaseExists($name)) {
            return sprintf('MySQL database [%s] already exists', $name);
        }

        return MysqlFacade::createDatabase($name)
            ? sprintf('Created MySQL database [%s]', $name)
            : sprintf('Failed to create MySQL database [%s]', $name);
    }

    private function resolveReadPath(?string $name): string
    {
        if ($name === null || $name === '' || $name === 'project') {
            return $this->projectPath();
        }

        return $this->globalPath($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function readValidated(string $path): array
    {
        $raw = $this->files->get($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('Invalid JSON in profile [%s]', $path));
        }

        /** @var array<string, mixed> $decoded */
        $this->validate($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Failed to encode profile JSON');
        }

        $this->files->putAsUser($path, $json . PHP_EOL);
    }

    private function sanitizeName(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name) ?? $name;
        $clean = trim($clean, '-');
        if ($clean === '') {
            throw new InvalidArgumentException('Invalid profile name');
        }

        return $clean;
    }

    private function sanitizeDatabaseName(string $site): string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $site) ?? $site);

        return trim($name, '_') ?: 'valet';
    }
}
