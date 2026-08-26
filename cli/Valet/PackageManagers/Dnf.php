<?php

namespace Valet\PackageManagers;

class Dnf extends AbstractPackageManager
{
    public const PHP_FPM_PATTERN_BY_VERSION = [
        '8.3' => 'php-fpm',
    ];

    private const PACKAGES = [
        'redis' => 'redis',
        'mysql' => 'mysql-server',
        'mariadb' => 'mariadb-server',
    ];

    public function packages(string $package): array
    {
        $query = "dnf list installed {$package} | grep {$package} | sed 's_  _\\t_g' | sed 's_\\._\\t_g' | cut -f 1";

        return explode(PHP_EOL, $this->cli->run($query));
    }

    protected function installCommand(string $package): string
    {
        return 'dnf install -y '.$package;
    }

    protected function managerName(): string
    {
        return 'Dnf';
    }

    protected function binaryName(): string
    {
        return 'dnf';
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
