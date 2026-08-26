<?php

namespace Valet\PackageManagers;

class Yum extends AbstractPackageManager
{
    public const PHP_FPM_PATTERN_BY_VERSION = [];

    private const PACKAGES = [
        'redis' => 'redis',
        'mysql' => 'mysql-server',
        'mariadb' => 'mariadb-server',
    ];

    public string $redisPackageName = 'redis';

    protected string $caCertificatesPath = '/usr/share/pki/ca-trust-source';

    public function packages(string $package): array
    {
        $query = "yum list installed {$package} | grep {$package} | sed 's_  _\\t_g' | sed 's_\\._\\t_g' | cut -f 1";

        return explode(PHP_EOL, $this->cli->run($query));
    }

    protected function installCommand(string $package): string
    {
        return 'yum install -y '.$package;
    }

    protected function managerName(): string
    {
        return 'Yum';
    }

    protected function binaryName(): string
    {
        return 'yum';
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
