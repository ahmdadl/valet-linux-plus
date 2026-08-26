<?php

namespace Valet\PackageManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

abstract class AbstractPackageManager implements PackageManager
{
    /**
     * The command line instance.
     */
    public CommandLine $cli;

    /**
     * The service manager instance.
     */
    public ServiceManager $serviceManager;

    /**
     * Per-version PHP FPM service name overrides (distro-specific).
     *
     * @var array<string, string>
     */
    protected const PHP_FPM_PATTERN_BY_VERSION = [];

    /**
     * Default PHP FPM service name pattern (uses {VERSION} placeholder).
     */
    protected string $phpFpmDefaultPattern = 'php{VERSION}-fpm';

    /**
     * Whether the PHP FPM pattern uses a dot-stripped version ({VERSION_WITHOUT_DOT}).
     */
    protected bool $phpFpmStripDots = false;

    /**
     * Default PHP extension prefix pattern (uses {VERSION} placeholder).
     */
    protected string $phpExtensionDefaultPrefix = 'php{VERSION}-';

    /**
     * Whether the PHP extension prefix uses a dot-stripped version ({VERSION_WITHOUT_DOT}).
     */
    protected bool $phpExtensionStripDots = false;

    /**
     * Path to the distro `ca-certificates` directory.
     */
    protected string $caCertificatesPath = '/usr/share/ca-certificates';

    /**
     * Create a new package manager instance.
     */
    public function __construct(CommandLine $cli, ServiceManager $serviceManager)
    {
        $this->cli = $cli;
        $this->serviceManager = $serviceManager;
    }

    /**
     * Get array of installed packages. Distro-specific.
     *
     * @return array<int, string>
     */
    abstract public function packages(string $package): array;

    /**
     * Determine if the given package is installed.
     */
    public function installed(string $package): bool
    {
        return in_array($package, $this->packages($package));
    }

    /**
     * Ensure that the given package is installed.
     */
    public function ensureInstalled(string $package): void
    {
        if (!$this->installed($package)) {
            $this->installOrFail($package);
        }
    }

    /**
     * Install the given package and throw an exception on failure.
     */
    public function installOrFail(string $package): void
    {
        Writer::twoColumnDetail($package, 'Installing');

        $this->cli->run($this->installCommand($package), function ($exitCode, $errorOutput) use ($package) {
            Writer::error(\sprintf('%s: %s', $exitCode, $errorOutput));

            throw new DomainException($this->managerName().' was unable to install ['.$package.'].');
        });
    }

    /**
     * Configure package manager on valet install.
     */
    public function setup(): void
    {
        // Nothing to do
    }

    /**
     * Determine if package manager is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $output = $this->cli->run('which '.$this->binaryName(), function () {
                throw new DomainException($this->binaryName().' not available');
            });

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Determine php fpm package name.
     */
    public function getPhpFpmName(string $version): string
    {
        /** @var array<string, string> $patterns */
        $patterns = static::PHP_FPM_PATTERN_BY_VERSION;
        $pattern = !empty($patterns[$version])
            ? $patterns[$version] : $this->phpFpmDefaultPattern;

        if ($this->phpFpmStripDots) {
            $version = (string) preg_replace('~[^\d]~', '', $version);

            return str_replace('{VERSION_WITHOUT_DOT}', $version, $pattern);
        }

        return str_replace('{VERSION}', $version, $pattern);
    }

    /**
     * Get the `ca-certificates` directory.
     */
    public function getCaCertificatesPath(): string
    {
        return $this->caCertificatesPath;
    }

    /**
     * Determine php extension pattern.
     */
    public function getPhpExtensionPrefix(string $version): string
    {
        $pattern = $this->phpExtensionDefaultPrefix;

        if ($this->phpExtensionStripDots) {
            $version = (string) preg_replace('~[^\d]~', '', $version);

            return str_replace('{VERSION_WITHOUT_DOT}', $version, $pattern);
        }

        return str_replace('{VERSION}', $version, $pattern);
    }

    /**
     * Restart network manager in distro.
     */
    public function restartNetworkManager(): void
    {
        $this->serviceManager->restart($this->networkManagerServiceName());
    }

    /**
     * Get package name by service.
     */
    public function packageName(string $name): string
    {
        $map = $this->packagesMap();

        if (isset($map[$name])) {
            return $map[$name];
        }

        throw new \InvalidArgumentException(\sprintf('Package not found by %s', $name));
    }

    /**
     * Build the install command for the given package.
     */
    abstract protected function installCommand(string $package): string;

    /**
     * Human readable manager name used in exception messages.
     */
    abstract protected function managerName(): string;

    /**
     * Binary used to detect availability of this package manager.
     */
    abstract protected function binaryName(): string;

    /**
     * Map of logical service names to distro package names.
     *
     * @return array<string, string>
     */
    abstract protected function packagesMap(): array;

    /**
     * Service name used to restart the network manager.
     */
    abstract protected function networkManagerServiceName(): string;
}
