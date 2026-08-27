<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\DnsMasq;
use Valet\Facades\Mailpit;
use Valet\Facades\Mysql;
use Valet\Facades\Nginx;
use Valet\Facades\PhpFpm;
use Valet\Facades\Postgres;
use Valet\Facades\ValetRedis;

/**
 * Service registry responsible for starting, restarting, stopping and
 * reporting the status of the Valet daemon services.
 *
 * This centralises the logic previously duplicated across the start,
 * restart and stop commands in app.php.
 *
 * In addition to the built-in services defined in SERVICE_MAP, the registry
 * supports user-defined "custom" services configured under the `services`
 * key of config.json. Custom services are wired generically through the
 * PackageManager (to detect installation) and ServiceManager (to
 * start/stop/restart/status) so they integrate with the same commands.
 */
class ServiceRegistry
{
    /**
     * Map of short service names to their canonical service key.
     */
    protected const SERVICE_MAP = [
        'nginx' => 'nginx',
        'php' => 'php',
        'mailpit' => 'mailpit',
        'dnsmasq' => 'dnsmasq',
        'mysql' => 'mysql',
        'redis' => 'redis',
        'postgres' => 'postgres',
    ];

    /**
     * Built-in service templates that can be enabled via addService().
     *
     * Each template is a complete service definition keyed by its name.
     */
    protected const BUILTIN_TEMPLATES = [
        'minio' => [
            'name' => 'minio',
            'package' => 'minio',
            'service' => 'minio',
            'port' => 9000,
            'proxyHost' => 'http://127.0.0.1:9000',
            'healthCheck' => 'http://127.0.0.1:9000/minio/health/live',
            'description' => 'MinIO object storage',
        ],
    ];

    /**
     * Order of services acted on when none are explicitly specified.
     *
     * Postgres is included for forward compatibility; it is skipped
     * gracefully when the Postgres class is not available.
     */
    protected const DEFAULT_ORDER = [
        'dnsmasq',
        'php',
        'nginx',
        'mailpit',
        'mysql',
        'redis',
        'postgres',
    ];

    /**
     * Order of services stopped when none are explicitly specified.
     *
     * Dnsmasq is intentionally excluded, matching the original behaviour.
     */
    protected const DEFAULT_STOP_ORDER = [
        'php',
        'nginx',
        'mailpit',
        'mysql',
        'redis',
    ];

    /**
     * Required keys for a custom service definition.
     */
    protected const REQUIRED_DEFINITION_KEYS = ['package', 'service', 'port'];

    private ?Configuration $configuration = null;
    private ?CommandLine $commandLine = null;
    private ?PackageManager $packageManager = null;
    private ?ServiceManager $serviceManager = null;

    /**
     * Create a new service registry instance.
     *
     * All dependencies are optional so the class can still be constructed
     * with `new ServiceRegistry()` (used by legacy tests). When omitted they
     * are lazily resolved from the container, keeping backward compatibility.
     */
    public function __construct(
        ?Configuration $configuration = null,
        ?CommandLine $commandLine = null,
        ?PackageManager $packageManager = null,
        ?ServiceManager $serviceManager = null
    ) {
        $this->configuration = $configuration;
        $this->commandLine = $commandLine;
        $this->packageManager = $packageManager;
        $this->serviceManager = $serviceManager;
    }

    /**
     * Return the built-in service templates.
     *
     * @return array<string, array<string, mixed>>
     */
    public function templates(): array
    {
        return self::BUILTIN_TEMPLATES;
    }

    /**
     * Return the user-defined custom services from config.json.
     *
     * Each entry is normalised so it always contains the full set of keys.
     *
     * @return array<string, array{name: string, package: string, service: string, port: int|null, proxyHost: string|null, healthCheck: string|null, description: string}>
     */
    public function customServices(): array
    {
        /** @var mixed $configured */
        $configured = $this->config()->get('services', []);

        if (!is_array($configured)) {
            return [];
        }

        $result = [];
        foreach ($configured as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $result[(string) $name] = $this->normalizeDefinition((string) $name, $definition);
        }

        return $result;
    }

    /**
     * Return every known service name (built-in + custom).
     *
     * @return array<int, string>
     */
    public function allServices(): array
    {
        return array_merge(array_keys(self::SERVICE_MAP), array_keys($this->customServices()));
    }

    /**
     * Determine whether the given name is a known service.
     */
    public function hasService(string $name): bool
    {
        return isset(self::SERVICE_MAP[$name]) || $this->isCustomService($name);
    }

    /**
     * Determine whether the given name is a user-defined custom service.
     */
    public function isCustomService(string $name): bool
    {
        return array_key_exists($name, $this->customServices());
    }

    /**
     * Return the definition for a custom service, or null for built-in ones.
     *
     * @return array{name: string, package: string, service: string, port: int|null, proxyHost: string|null, healthCheck: string|null, description: string}|null
     */
    public function getServiceDefinition(string $name): ?array
    {
        if ($this->isCustomService($name)) {
            return $this->customServices()[$name];
        }

        return null;
    }

    /**
     * Add (or replace) a custom service definition and persist it to config.
     *
     * @param array<string, mixed> $definition
     */
    public function addService(string $name, array $definition): void
    {
        $definition = $this->normalizeDefinition($name, $definition);

        foreach (self::REQUIRED_DEFINITION_KEYS as $key) {
            if ($definition[$key] === null || $definition[$key] === '') {
                throw new \InvalidArgumentException(
                    sprintf('Service definition for [%s] is missing required key [%s].', $name, $key)
                );
            }
        }

        /** @var array<string, mixed> $services */
        $services = $this->config()->get('services', []);
        $services[$name] = $definition;

        $this->config()->set('services', $services);
    }

    /**
     * Remove a custom service from config.
     *
     * Returns false when the service is not a removable custom service.
     */
    public function removeService(string $name): bool
    {
        if (!$this->isCustomService($name)) {
            return false;
        }

        /** @var array<string, mixed> $services */
        $services = $this->config()->get('services', []);
        unset($services[$name]);

        $this->config()->set('services', $services);

        return true;
    }

    /**
     * Start the Valet services.
     *
     * When no services are provided every known service is started.
     *
     * @param string[] $services
     */
    public function start(array $services): void
    {
        $this->run('restart', $services, 'started');
    }

    /**
     * Restart the Valet services.
     *
     * When no services are provided every known service is restarted.
     *
     * @param string[] $services
     */
    public function restart(array $services): void
    {
        $this->run('restart', $services, 'restarted');
    }

    /**
     * Stop the Valet services.
     *
     * When no services are provided every known service (except dnsmasq)
     * is stopped, matching the original behaviour.
     *
     * @param string[] $services
     */
    public function stop(array $services): void
    {
        $this->run('stop', $services, 'stopped');
    }

    /**
     * Print the status of every service that supports status reporting.
     */
    public function status(): void
    {
        Nginx::status();
        PhpFpm::status();
        Mailpit::status();

        if (class_exists(\Valet\Postgres::class)) {
            Postgres::status();
        }

        foreach ($this->customServices() as $definition) {
            $service = (string) $definition['service'];

            $this->sm()->printStatus($service);
        }
    }

    /**
     * Run the given facade method against the requested services.
     *
     * @param string[] $services
     */
    private function run(string $method, array $services, string $pastTense): void
    {
        $all = empty($services);
        $targets = $all ? $this->defaultOrder($method) : $services;

        foreach ($targets as $service) {
            $this->invoke($service, $method);
        }

        if ($all) {
            Writer::info('Valet services have been ' . $pastTense . '.');
        } else {
            Writer::info('Specified Valet services have been ' . $pastTense . '.');
        }
    }

    /**
     * Determine the default service order for the given method.
     *
     * Custom services are always appended after the built-in ones.
     *
     * @return array<int, string>
     */
    private function defaultOrder(string $method): array
    {
        $order = $method === 'stop' ? self::DEFAULT_STOP_ORDER : self::DEFAULT_ORDER;

        return array_merge($order, array_keys($this->customServices()));
    }

    /**
     * Resolve and invoke the facade method for a single service name.
     */
    private function invoke(string $name, string $method): void
    {
        $key = $this->resolve($name);

        if ($key === null) {
            Writer::warn('Unknown service: ' . $name);

            return;
        }

        if ($this->isCustomService($name)) {
            $this->invokeCustom($name, $method);

            return;
        }

        switch ($key) {
            case 'nginx':
                Nginx::$method();
                break;

            case 'php':
                PhpFpm::$method();
                break;

            case 'mailpit':
                Mailpit::$method();
                break;

            case 'dnsmasq':
                DnsMasq::$method();
                break;

            case 'mysql':
                Mysql::$method();
                break;

            case 'redis':
                ValetRedis::$method();
                break;

            case 'postgres':
                if (class_exists(\Valet\Postgres::class)) {
                    Postgres::$method();
                } else {
                    Writer::warn('Postgres service is not available; skipping.');
                }
                break;

            default:
                Writer::warn('Unknown service: ' . $key);
        }
    }

    /**
     * Handle a custom (config-defined) service generically.
     */
    private function invokeCustom(string $name, string $method): void
    {
        $definition = $this->getServiceDefinition($name);

        if ($definition === null) {
            Writer::warn('Unknown service: ' . $name);

            return;
        }

        $service = (string) $definition['service'];
        $package = (string) $definition['package'];

        if ($method === 'status') {
            $this->sm()->printStatus($service);

            return;
        }

        if (!$this->pm()->installed($package)) {
            Writer::warn(
                sprintf('%s package [%s] is not installed; skipping.', ucfirst($name), $package)
            );

            return;
        }

        switch ($method) {
            case 'start':
                $this->sm()->start($service);
                break;

            case 'stop':
                $this->sm()->stop($service);
                break;

            case 'restart':
                $this->sm()->restart($service);
                break;

            default:
                Writer::warn('Unsupported action [' . $method . '] for service ' . $name);
        }
    }

    /**
     * Map a short service name to its canonical service key.
     *
     * Custom service names resolve to themselves so invoke() can detect and
     * handle them generically.
     */
    private function resolve(string $name): ?string
    {
        if (array_key_exists($name, self::SERVICE_MAP)) {
            return self::SERVICE_MAP[$name];
        }

        if ($this->isCustomService($name)) {
            return $name;
        }

        return null;
    }

    /**
     * Normalise a service definition, filling in default keys.
     *
     * @param array<string, mixed> $definition
     * @return array{name: string, package: string, service: string, port: int|null, proxyHost: string|null, healthCheck: string|null, description: string}
     */
    private function normalizeDefinition(string $name, array $definition): array
    {
        if (empty($definition) && isset(self::BUILTIN_TEMPLATES[$name])) {
            $definition = self::BUILTIN_TEMPLATES[$name];
        }

        $merged = array_merge([
            'name' => $name,
            'package' => $name,
            'service' => $name,
            'port' => null,
            'proxyHost' => null,
            'healthCheck' => null,
            'description' => '',
        ], $definition);

        return [
            'name' => is_string($merged['name']) ? $merged['name'] : $name,
            'package' => is_string($merged['package']) ? $merged['package'] : $name,
            'service' => is_string($merged['service']) ? $merged['service'] : $name,
            'port' => is_int($merged['port']) ? $merged['port'] : null,
            'proxyHost' => is_string($merged['proxyHost']) ? $merged['proxyHost'] : null,
            'healthCheck' => is_string($merged['healthCheck']) ? $merged['healthCheck'] : null,
            'description' => is_string($merged['description']) ? $merged['description'] : '',
        ];
    }

    private function config(): Configuration
    {
        if ($this->configuration === null) {
            /** @var Configuration $config */
            $config = resolve(Configuration::class);
            $this->configuration = $config;
        }

        return $this->configuration;
    }

    private function cli(): CommandLine
    {
        if ($this->commandLine === null) {
            /** @var CommandLine $cli */
            $cli = resolve(CommandLine::class);
            $this->commandLine = $cli;
        }

        return $this->commandLine;
    }

    private function pm(): PackageManager
    {
        if ($this->packageManager === null) {
            /** @var PackageManager $pm */
            $pm = resolve(PackageManager::class);
            $this->packageManager = $pm;
        }

        return $this->packageManager;
    }

    private function sm(): ServiceManager
    {
        if ($this->serviceManager === null) {
            /** @var ServiceManager $sm */
            $sm = resolve(ServiceManager::class);
            $this->serviceManager = $sm;
        }

        return $this->serviceManager;
    }
}
