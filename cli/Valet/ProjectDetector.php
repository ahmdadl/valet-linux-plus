<?php

namespace Valet;

use Valet\Drivers\ValetDriver;

class ProjectDetector
{
    /**
     * Detect the framework from the given site path.
     *
     * @return string|false Framework name or false
     */
    public static function detect(string $sitePath): string|false
    {
        if (!is_dir($sitePath)) {
            return false;
        }

        // Check for Laravel
        if (is_file($sitePath . '/artisan') && is_file($sitePath . '/composer.json')) {
            $composer = json_decode(file_get_contents($sitePath . '/composer.json'), true);
            if (is_array($composer) && isset($composer['require']['laravel/framework'])) {
                return 'laravel';
            }
        }

        // Check for Symfony (config/bootstrap.php OR vendor/autoload.php + src/ OR config/packages/)
        if (is_file($sitePath . '/config/bootstrap.php') || is_file($sitePath . '/vendor/autoload.php')) {
            if (is_dir($sitePath . '/src') || is_dir($sitePath . '/config/packages')) {
                return 'symfony';
            }
        }

        // Check for WordPress
        if (is_file($sitePath . '/wp-load.php') || is_file($sitePath . '/wp-config.php')) {
            return 'wordpress';
        }

        // Check for Bedrock
        if (is_file($sitePath . '/web/wp-load.php') && is_file($sitePath . '/composer.json')) {
            $composer = json_decode(file_get_contents($sitePath . '/composer.json'), true);
            if (is_array($composer) && isset($composer['require']['laravel/bedrock'])) {
                return 'bedrock';
            }
        }

        // Check for generic PHP
        if (is_file($sitePath . '/index.php')) {
            return 'plain';
        }

        return false;
    }

    /**
     * Detect the driver class that serves the given site.
     *
     * @return string|false Driver class name or false
     */
    public static function detectDriver(string $sitePath, string $siteName): string|false
    {
        if (!is_dir($sitePath)) {
            return false;
        }

        $driver = ValetDriver::assign($sitePath, $siteName, '/');

        if ($driver !== null) {
            return get_class($driver);
        }

        return false;
    }

    /**
     * Check if the given path is a Laravel project.
     */
    public static function isLaravel(string $sitePath): bool
    {
        return self::detect($sitePath) === 'laravel';
    }

    /**
     * Check if the given path is a Symfony project.
     */
    public static function isSymfony(string $sitePath): bool
    {
        return self::detect($sitePath) === 'symfony';
    }

    /**
     * Check if the given path is a WordPress project.
     */
    public static function isWordPress(string $sitePath): bool
    {
        return self::detect($sitePath) === 'wordpress';
    }

    /**
     * Check if the given path is a Bedrock project.
     */
    public static function isBedrock(string $sitePath): bool
    {
        return self::detect($sitePath) === 'bedrock';
    }

    /**
     * Check if the given path is a plain PHP project.
     */
    public static function isPlain(string $sitePath): bool
    {
        return self::detect($sitePath) === 'plain';
    }
}