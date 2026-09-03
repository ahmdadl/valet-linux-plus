<?php

namespace Valet\ServiceManagers;

use DomainException;

class Systemd extends AbstractServiceManager
{
    protected function startService(string $service): void
    {
        $this->cli->quietly('sudo systemctl start '.escapeshellarg($service));
    }

    protected function stopService(string $service): void
    {
        $this->cli->quietly('sudo systemctl stop '.escapeshellarg($service));
    }

    protected function restartService(string $service): void
    {
        $this->cli->quietly('sudo systemctl restart '.escapeshellarg($service));
    }

    protected function statusCommand(string $service): string
    {
        return 'systemctl status '.escapeshellarg($service).' | grep "Active:"';
    }

    protected function isEnabledCommand(string $service): string
    {
        return \sprintf('systemctl is-enabled %s', escapeshellarg($service));
    }

    protected function enableService(string $service): void
    {
        if ($this->disabled($service)) {
            $this->cli->quietly('sudo systemctl enable '.escapeshellarg($service));
        }
    }

    protected function disableService(string $service): void
    {
        if (!$this->disabled($service)) {
            $this->cli->quietly('sudo systemctl disable '.escapeshellarg($service));
        }
    }

    protected function binaryName(): string
    {
        return 'systemctl';
    }

    protected function valetDnsServicePath(): string
    {
        return '/etc/systemd/system/valet-dns.service';
    }

    /**
     * Determine real service name.
     * @throws DomainException
     */
    protected function resolveRealService(string $service): string
    {
        if (strpos($this->cli->run("systemctl status ".escapeshellarg($service)." | grep Loaded"), 'Loaded: loaded') !== false) {
            return $service;
        }

        throw new DomainException('Unable to determine service name.');
    }

    public function isSystemd(): bool
    {
        return true;
    }
}
