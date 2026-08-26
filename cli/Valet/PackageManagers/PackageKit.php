<?php

namespace Valet\PackageManagers;

class PackageKit extends AbstractPackageManager
{
    public const PHP_FPM_PATTERN_BY_VERSION = [];

    private const PACKAGES = [
        'redis' => 'redis-server',
        'mysql' => 'mysql-server',
        'mariadb' => 'mariadb-server',
    ];

    protected string $caCertificatesPath = '/usr/share/pki/ca-trust-source';

    public function packages(string $package): array
    {
        $query = "pkcon search {$package} | grep '^In' | sed 's/\s\+/ /g' | cut -d' ' -f2 | sed 's/-[0-9].*//'";

        return explode(PHP_EOL, $this->cli->run($query));
    }

    public function restartNetworkManager(): void
    {
        $this->serviceManager->restart(['network-manager']);

        $version = trim($this->cli->run('cat /etc/*release | grep DISTRIB_RELEASE | cut -d\= -f2'));

        if ($version === '17.04') {
            $this->serviceManager->enable('systemd-resolved');
            $this->serviceManager->restart('systemd-resolved');
        }
    }

    protected function installCommand(string $package): string
    {
        return 'pkcon install -y '.$package;
    }

    protected function managerName(): string
    {
        return 'PackageKit';
    }

    protected function binaryName(): string
    {
        return 'pkcon';
    }

    protected function packagesMap(): array
    {
        return self::PACKAGES;
    }

    protected function networkManagerServiceName(): string
    {
        return 'network-manager';
    }
}
