<?php

namespace Valet\PackageManagers;

class Pacman extends AbstractPackageManager
{
    public const PHP_FPM_PATTERN_BY_VERSION = [
        '8.4' => 'php-fpm'
    ];

    private const PACKAGES = [
        'redis' => 'redis',
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
    ];

    protected string $phpFpmDefaultPattern = 'php{VERSION_WITHOUT_DOT}-fpm';
    protected bool $phpFpmStripDots = true;
    protected string $phpExtensionDefaultPrefix = 'php{VERSION_WITHOUT_DOT}-';
    protected bool $phpExtensionStripDots = true;

    public function packages(string $package): array
    {
        $query = "pacman -Qqs {$package}";

        return explode(PHP_EOL, $this->cli->run($query));
    }

    protected function installCommand(string $package): string
    {
        return 'pacman --noconfirm --needed -S '.$package;
    }

    protected function managerName(): string
    {
        return 'Pacman';
    }

    protected function binaryName(): string
    {
        return 'pacman';
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
