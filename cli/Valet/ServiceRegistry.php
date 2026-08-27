<?php

namespace Valet;

use ConsoleComponents\Writer;
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
     * Start the Valet services.
     *
     * When no services are provided every known service is started.
     */
    public function start(array $services): void
    {
        $this->run('restart', $services, 'started');
    }

    /**
     * Restart the Valet services.
     *
     * When no services are provided every known service is restarted.
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
    }

    /**
     * Run the given facade method against the requested services.
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
     */
    private function defaultOrder(string $method): array
    {
        return $method === 'stop' ? self::DEFAULT_STOP_ORDER : self::DEFAULT_ORDER;
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
     * Map a short service name to its canonical service key.
     */
    private function resolve(string $name): ?string
    {
        return self::SERVICE_MAP[$name] ?? null;
    }
}
