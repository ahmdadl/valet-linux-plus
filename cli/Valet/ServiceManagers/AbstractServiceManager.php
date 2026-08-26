<?php

namespace Valet\ServiceManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;

abstract class AbstractServiceManager implements ServiceManager
{
    protected CommandLine $cli;
    protected Filesystem $files;

    public function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Start the given services.
     * @param array<int, string>|string|null $services Service name
     */
    public function start(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Starting');
            $this->startService($this->resolveRealService($service));
        }
    }

    /**
     * Stop the given services.
     * @param array<int, string>|string|null $services Service name
     */
    public function stop(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Stopping');
            $this->stopService($this->resolveRealService($service));
        }
    }

    /**
     * Restart the given services.
     * @param array<int, string>|string|null $services Service name
     */
    public function restart(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Restarting');
            $this->restartService($this->resolveRealService($service));
        }
    }

    /**
     * Status of the given services.
     */
    public function printStatus(string $service): void
    {
        $status = $this->cli->run($this->statusCommand($this->resolveRealService($service)));
        $running = strpos(trim($status), 'running');

        if ($running) {
            Writer::info(ucfirst($service).' is running...');
        } else {
            Writer::warn(ucfirst($service).' is stopped...');
        }
    }

    /**
     * Check if service is disabled.
     */
    public function disabled(string $service): bool
    {
        $service = $this->resolveRealService($service);

        return !str_contains(trim($this->cli->run($this->isEnabledCommand($service))), 'enabled');
    }

    /**
     * Enable services.
     */
    public function enable(string $service): void
    {
        try {
            $service = $this->resolveRealService($service);
            $this->enableService($service);
            Writer::twoColumnDetail(ucfirst($service), 'Enabled');
        } catch (DomainException $e) {
            Writer::warn(ucfirst($service).' unavailable.');
        }
    }

    /**
     * Disable services.
     */
    public function disable(string $service): void
    {
        try {
            $service = $this->resolveRealService($service);
            $this->disableService($service);
            Writer::twoColumnDetail(ucfirst($service), 'Disabled');
        } catch (DomainException $e) {
            Writer::warn(ucfirst($service).' unavailable.');
        }
    }

    /**
     * Determine if service manager is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $output = $this->cli->run(
                'which '.$this->binaryName(),
                function () {
                    throw new DomainException($this->binaryName().' not available');
                }
            );

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Remove Valet DNS services.
     */
    public function removeValetDns(): void
    {
        $servicePath = $this->valetDnsServicePath();

        if ($this->files->exists($servicePath)) {
            Writer::info('Removing Valet DNS service...');
            $this->disable('valet-dns');
            $this->stop('valet-dns');
            $this->files->remove($servicePath);
        }
    }

    abstract protected function startService(string $service): void;

    abstract protected function stopService(string $service): void;

    abstract protected function restartService(string $service): void;

    abstract protected function statusCommand(string $service): string;

    abstract protected function isEnabledCommand(string $service): string;

    abstract protected function enableService(string $service): void;

    abstract protected function disableService(string $service): void;

    abstract protected function binaryName(): string;

    abstract protected function valetDnsServicePath(): string;

    abstract protected function resolveRealService(string $service): string;

    abstract public function isSystemd(): bool;
}
