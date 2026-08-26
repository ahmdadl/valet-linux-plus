<?php

namespace Valet\ServiceManagers;

use DomainException;

class Systemd extends AbstractServiceManager
{
    protected function startService(string $service): void
    {
        $this->cli->quietly('sudo systemctl start '.$service);
    }

    protected function stopService(string $service): void
    {
        $this->cli->quietly('sudo systemctl stop '.$service);
    }

    protected function restartService(string $service): void
    {
        $this->cli->quietly('sudo systemctl restart '.$service);
    }

    protected function statusCommand(string $service): string
    {
        return 'systemctl status '.$service.' | grep "Active:"';
    }

    protected function isEnabledCommand(string $service): string
    {
        return \sprintf('systemctl is-enabled %s', $service);
    }

    protected function enableService(string $service): void
    {
        if ($this->disabled($service)) {
            $this->cli->quietly('sudo systemctl enable '.$service);
        }
    }

    protected function disableService(string $service): void
    {
        if (!$this->disabled($service)) {
            $this->cli->quietly('sudo systemctl disable '.$service);
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
        if (strpos($this->cli->run("systemctl status $service | grep Loaded"), 'Loaded: loaded') !== false) {
            return $service;
        }

        throw new DomainException('Unable to determine service name.');
    }

    public function isSystemd(): bool
    {
        return true;
    }
}
