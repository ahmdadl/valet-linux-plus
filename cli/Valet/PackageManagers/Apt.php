<?php

namespace Valet\PackageManagers;

class Apt extends AbstractPackageManager
{
    public const PHP_FPM_PATTERN_BY_VERSION = [];

    private const PACKAGES = [
        'redis' => 'redis-server',
        'mysql' => 'mysql-server',
        'mariadb' => 'mariadb-server',
    ];

    public function packages(string $package): array
    {
        $query = "dpkg -l {$package} | grep '^ii' | sed 's/\s\+/ /g' | cut -d' ' -f2";

        return explode(PHP_EOL, $this->cli->run($query));
    }

    protected function installCommand(string $package): string
    {
        return 'apt-get install -y '.$package;
    }

    protected function managerName(): string
    {
        return 'Apt';
    }

    protected function binaryName(): string
    {
        return 'apt-get';
    }

    protected function packagesMap(): array
    {
        return self::PACKAGES;
    }

    protected function networkManagerServiceName(): string
    {
        return 'NetworkManager';
    }
}
