<?php

namespace Valet\ServiceManagers;

use DomainException;

class LinuxService extends AbstractServiceManager
{
    protected function startService(string $service): void
    {
        $this->cli->quietly('sudo service '.escapeshellarg($service).' start');
    }

    protected function stopService(string $service): void
    {
        $this->cli->quietly('sudo service '.escapeshellarg($service).' stop');
    }

    protected function restartService(string $service): void
    {
        $this->cli->quietly('sudo service '.escapeshellarg($service).' restart');
    }

    protected function statusCommand(string $service): string
    {
        return 'service '.escapeshellarg($service).' status';
    }

    protected function isEnabledCommand(string $service): string
    {
        return "systemctl is-enabled ".escapeshellarg($service);
    }

    protected function enableService(string $service): void
    {
        $this->cli->quietly("sudo update-rc.d $service defaults");
    }

    protected function disableService(string $service): void
    {
        $this->cli->quietly("sudo chmod -x /etc/init.d/".escapeshellarg($service));
        $this->cli->quietly("sudo update-rc.d ".escapeshellarg($service)." defaults");
    }

    protected function binaryName(): string
    {
        return 'service';
    }

    protected function valetDnsServicePath(): string
    {
        return '/etc/init.d/valet-dns';
    }

    /**
     * Determine real service name.
     */
    protected function resolveRealService(string $service): string
    {
        if (strpos($this->cli->run('service '.escapeshellarg($service).' status'), 'not-found') === false) {
            return $service;
        }

        throw new DomainException('Unable to determine service name.');
    }

    public function isSystemd(): bool
    {
        return false;
    }
}
