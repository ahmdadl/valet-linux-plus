<?php

namespace Valet;

use ConsoleComponents\Writer;
use DomainException;
use Valet\Drivers\ValetDriver;
use Valet\Facades\Configuration as ConfigurationFacade;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\Site as SiteFacade;
use Valet\Facades\SiteIsolate as SiteIsolateFacade;

class Repl
{
    public function __construct(
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $config
    ) {
    }

    /**
     * Launch a framework REPL (tinker) for the current or named site.
     */
    public function run(?string $site = null): void
    {
        $sitePath = $this->resolveSitePath($site);
        $host = $this->hostForPath($sitePath);
        $php = $this->phpBinary($host);
        $argv = $this->resolveArgv($sitePath);

        if ($argv === null) {
            Writer::error('Unable to resolve a REPL command for this project.');

            return;
        }

        if ($this->isLaravelTinker($argv) && !$this->hasLaravelTinker($sitePath)) {
            Writer::warn('laravel/tinker not found. Install with: composer require laravel/tinker --dev');
        }

        $escaped = array_map('escapeshellarg', $argv);
        $command = sprintf(
            'cd %s && %s %s',
            escapeshellarg($sitePath),
            escapeshellarg($php),
            implode(' ', $escaped)
        );

        $this->cli->passthru($command);
    }

    /**
     * Resolve REPL argv after the PHP binary (without the binary itself).
     *
     * @return list<string>|null
     */
    public function resolveArgv(string $sitePath): ?array
    {
        $siteName = basename($sitePath);
        $driver = ValetDriver::assign($sitePath, $siteName, '/');

        if ($driver instanceof ValetDriver) {
            $fromDriver = $driver->replCommand($sitePath);
            if (is_array($fromDriver) && $fromDriver !== []) {
                return array_values($fromDriver);
            }
        }

        if ($this->files->exists($sitePath . '/artisan')) {
            return ['artisan', 'tinker'];
        }

        if ($this->files->exists($sitePath . '/bin/console')) {
            if ($this->files->exists($sitePath . '/vendor/bin/psysh')
                || $this->composerRequires($sitePath, 'psy/psysh')) {
                return ['bin/console', 'psysh'];
            }

            return ['bin/console'];
        }

        if ($this->files->exists($sitePath . '/vendor/bin/psysh')) {
            return ['vendor/bin/psysh'];
        }

        return ['-a'];
    }

    private function resolveSitePath(?string $site): string
    {
        if ($site === null || $site === '' || $site === '.' || $site === './') {
            return rtrim((string) getcwd(), '/');
        }

        $path = SiteFacade::path($site);
        if ($path === null) {
            throw new DomainException(sprintf('The [%s] site could not be found in Valet\'s site list.', $site));
        }

        return rtrim($path, '/');
    }

    private function hostForPath(string $sitePath): string
    {
        $tld = ConfigurationFacade::get('domain', 'test');
        $tld = is_scalar($tld) ? (string) $tld : 'test';

        return basename($sitePath) . '.' . $tld;
    }

    private function phpBinary(string $host): string
    {
        $version = null;
        try {
            $version = SiteIsolateFacade::isolatedPhpVersion($host);
        } catch (\Throwable $e) {
            $version = null;
        }

        $php = PhpFpmFacade::getPhpExecutablePath(is_string($version) ? $version : null);

        return is_string($php) && $php !== '' ? $php : 'php';
    }

    /**
     * @param list<string> $argv
     */
    private function isLaravelTinker(array $argv): bool
    {
        return ($argv[0] ?? null) === 'artisan' && ($argv[1] ?? null) === 'tinker';
    }

    private function hasLaravelTinker(string $sitePath): bool
    {
        return $this->composerRequires($sitePath, 'laravel/tinker')
            || $this->files->exists($sitePath . '/vendor/laravel/tinker');
    }

    private function composerRequires(string $sitePath, string $package): bool
    {
        $composerPath = $sitePath . '/composer.json';
        if (!$this->files->exists($composerPath)) {
            return false;
        }

        $json = json_decode($this->files->get($composerPath), true);
        if (!is_array($json)) {
            return false;
        }

        foreach (['require', 'require-dev'] as $section) {
            if (isset($json[$section]) && is_array($json[$section]) && isset($json[$section][$package])) {
                return true;
            }
        }

        return false;
    }
}
