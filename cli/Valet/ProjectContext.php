<?php

namespace Valet;

use Valet\Drivers\ValetDriver;
use Valet\Facades\Configuration as ConfigurationFacade;

class ProjectContext
{
    /**
     * Resolve the current project context from CWD.
     *
     * Returns site name, URL, driver class, and framework enum.
     *
     * @return array{site: string, url: string, driver: string|false, framework: string|false}
     */
    public static function fromCwd(): array
    {
        $tld = ConfigurationFacade::get('domain', 'test');
        $cwd = rtrim(getcwd(), '/');
        $basename = basename($cwd);

        // Use ValetDriver to assign the driver for the current path
        $driverInstance = ValetDriver::assign($cwd, $basename, $tld);
        $driver = $driverInstance ? get_class($driverInstance) : false;

        // Use ProjectDetector to detect framework
        $framework = ProjectDetector::detect($cwd);

        // If a parked or linked site is detected, use its site name and URL
        // This logic is now handled by ValetDriver::assign via the detection in driver classes

        return [
            'site' => $basename,
            'url' => $basename . '.' . $tld,
            'driver' => $driver,
            'framework' => $framework,
        ];
    }

    /**
     * Get the .env path for the current project.
     *
     * @return string|false Path to .env file, or false if not found
     */
    public static function envPath(): string|false
    {
        $cwd = rtrim((string)getcwd(), '/');
        $envPath = $cwd . '/.env';

        if (is_file($envPath)) {
            return $envPath;
        }

        // Check in parent directories
        $parent = dirname($cwd);
        while ($parent !== '/') {
            $parentEnv = $parent . '/.env';
            if (is_file($parentEnv)) {
                return $parentEnv;
            }
            $parent = dirname($parent);
        }

        return false;
    }

    /**
     * Get the site path for the current project.
     *
     * @return string|false Path to the site directory, or false
     */
    public static function sitePath(): string|false
    {
        $cwd = rtrim((string)getcwd(), '/');

        // Check for linked sites in Nginx directory
        $linkedSitesPath = VALET_HOME_PATH . '/Nginx';
        if (is_dir($linkedSitesPath)) {
            $linkedSites = @scandir($linkedSitesPath);
            if (is_array($linkedSites)) {
                foreach ($linkedSites as $site) {
                    if ($site !== '.' && $site !== '..' && is_dir($linkedSitesPath . '/' . $site)) {
                        // Remove .test suffix to get site name
                        $siteName = str_replace('.' . ConfigurationFacade::get('domain', 'test'), '', $site);
                        if ($siteName === basename($cwd)) {
                            return $linkedSitesPath . '/' . $site;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get the driver class for the current project.
     *
     * @return string|false Driver class name, or false
     */
    public static function driver(): string|false
    {
        $ctx = self::fromCwd();
        return $ctx['driver'];
    }

    /**
     * Get the framework for the current project.
     *
     * @return string|false Framework name, or false
     */
    public static function framework(): string|false
    {
        $ctx = self::fromCwd();
        return $ctx['framework'];
    }

    /**
     * Get the site URL for the current project.
     *
     * @return string|false Site URL, or false
     */
    public static function url(): string|false
    {
        $ctx = self::fromCwd();
        return $ctx['url'];
    }

    /**
     * Get the site name for the current project.
     *
     * @return string|false Site name, or false
     */
    public static function site(): string|false
    {
        $ctx = self::fromCwd();
        return $ctx['site'];
    }
}