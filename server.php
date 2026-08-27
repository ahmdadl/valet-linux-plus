<?php

/**
 * Bootstrap Valet for dashboard: autoloader + helpers + container.
 *
 * server.php is executed directly by php-fpm (via nginx fastcgi) without
 * going through cli/app.php, so the Illuminate container is not set up yet.
 * Without it the dashboard route falls through to the raw HTML fallback and
 * the frontend shows zeros. We bootstrap here best-effort so the dashboard
 * can render real data; failures are swallowed so normal site serving never
 * breaks.
 */
// Define user paths first so helpers.php (loaded via composer) respects them.
if (!defined('VALET_HOME_PATH')) {
    $homeDir = null;
    if (function_exists('posix_getpwuid')) {
        $owner = @fileowner(__FILE__);
        if (is_int($owner)) {
            $info = @posix_getpwuid($owner);
            if (is_array($info) && isset($info['dir']) && is_string($info['dir'])) {
                $homeDir = $info['dir'];
            }
        }
    }
    if (!$homeDir) {
        $homeDir = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root');
    }
    define('VALET_HOME_PATH', rtrim($homeDir, '/') . '/.config/valet');
}
if (!defined('VALET_STATIC_PREFIX')) {
    define('VALET_STATIC_PREFIX', '41c270e4-5535-4daa-b23e-c269744c2f45');
}
if (!defined('VALET_ROOT_PATH')) {
    define('VALET_ROOT_PATH', realpath(__DIR__));
}
if (!defined('VALET_SERVER_PATH')) {
    define('VALET_SERVER_PATH', realpath(__DIR__ . '/server.php'));
}

if (!class_exists(\Illuminate\Container\Container::class, false)) {
    $autoloadCandidates = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ];
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
    if ($home) {
        $autoloadCandidates[] = rtrim($home, '/') . '/.composer/vendor/autoload.php';
        $autoloadCandidates[] = rtrim($home, '/') . '/.config/composer/vendor/autoload.php';
    }
    foreach ($autoloadCandidates as $candidate) {
        if ($candidate && file_exists($candidate)) {
            require_once $candidate;
            break;
        }
    }
}

// Ensure helper functions (resolve, etc.) are available even when autoload
// failed to load via composer files (e.g. isolated PHP).
if (!function_exists('Valet\resolve')) {
    $helpersPath = __DIR__ . '/cli/includes/helpers.php';
    if (file_exists($helpersPath)) {
        require_once $helpersPath;
    }
}

// Set up the container for Dashboard resolution when served via php-fpm.
// Container::getInstance() auto-creates an instance via ??=, so we must check
// for the actual bindings rather than instance existence.
if (class_exists(\Illuminate\Container\Container::class)) {
    try {
        $container = \Illuminate\Container\Container::getInstance();
        if (!$container->bound(\Valet\Contracts\PackageManager::class) || !$container->bound(\Valet\Contracts\ServiceManager::class)) {
            try {
                $valetInstance = $container->make(\Valet\Valet::class);
                $valetInstance->environmentSetup();
            } catch (\Throwable $e) {
                // environmentSetup may fail (e.g. no package manager) — ignore.
            }
        }
    } catch (\Throwable $e) {
        // Container setup failed — dashboard will degrade to fallback.
    }
}

require_once __DIR__ . '/cli/includes/require-drivers.php';
require_once __DIR__ . '/cli/Valet/Server.php';

use Valet\Drivers\ValetDriver;
use Valet\Server;

/**
 * Load the Valet configuration.
 */
$valetConfig = json_decode(@file_get_contents(VALET_HOME_PATH.'/config.json'), true);
if (!is_array($valetConfig)) {
    $valetConfig = [];
}


/**
 * If the HTTP_HOST is an IP address, check the start of the REQUEST_URI for a
 * valid hostname, extract and use it as the effective HTTP_HOST in place
 * of the IP. It enables the use of Valet in a local network.
 */
/**
 * Valet dashboard — served at valet.<domain> and dashboard.<domain>.
 * This is the only reserved subdomain pair (alongside mails.<domain>).
 * Must run before site resolution so it never falls through to show404().
 */
$__valetDashboardHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
$__valetDashboardHost = preg_replace('/^www\./', '', $__valetDashboardHost);
$__valetDashboardDomain = $valetConfig['domain'] ?? 'test';
$__valetDashboardHosts = ['valet.' . $__valetDashboardDomain, 'dashboard.' . $__valetDashboardDomain];
if (in_array($__valetDashboardHost, $__valetDashboardHosts, true)) {
    try {
        if (class_exists(\Illuminate\Container\Container::class) && \Illuminate\Container\Container::getInstance()) {
            $dashboard = \Illuminate\Container\Container::getInstance()->make(\Valet\Dashboard::class);
            echo $dashboard->render();
            exit;
        }
    } catch (Throwable $e) {
    }
    try {
        if (class_exists(\Valet\Facades\Dashboard::class)) {
            echo \Valet\Facades\Dashboard::render();
            exit;
        }
    } catch (Throwable $e) {
    }
    $fallback = @file_get_contents(__DIR__ . '/cli/templates/dashboard.html');
    if ($fallback !== false) {
        echo $fallback;
        exit;
    }
}
unset($__valetDashboardHost, $__valetDashboardDomain, $__valetDashboardHosts, $fallback);

if (Server::hostIsIpAddress($_SERVER['HTTP_HOST'])) {
    $uriForIpAddressExtraction = ltrim($_SERVER['REQUEST_URI'], '/');

    if ($host = Server::valetSiteFromIpAddressUri($uriForIpAddressExtraction, $valetConfig['tld'])) {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['REQUEST_URI'] = str_replace($host, '', $uriForIpAddressExtraction);
    }
}

$server = new Server($valetConfig);

/**
 * Parse the URI and site / host for the incoming request.
 */
$uri = Server::uriFromRequestUri($_SERVER['REQUEST_URI']);
$siteName = $server->siteNameFromHttpHost($_SERVER['HTTP_HOST']);
$valetSitePath = $server->sitePath($siteName);

if ($valetSitePath === null && is_null($valetSitePath = $server->defaultSitePath())) {
    Server::show404();
}

$valetSitePath = realpath($valetSitePath);

/**
 * Find the appropriate Valet driver for the request.
 */
$valetDriver = ValetDriver::assign($valetSitePath, $siteName, $uri);

if (! $valetDriver) {
    Server::show404();
}

/**
 * ngrok uses the X-Original-Host to store the forwarded hostname.
 */
if (isset($_SERVER['HTTP_X_ORIGINAL_HOST']) && ! isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    $_SERVER['HTTP_X_FORWARDED_HOST'] = $_SERVER['HTTP_X_ORIGINAL_HOST'];
}

/**
 * Attempt to load server environment variables.
 */
$valetDriver->loadServerEnvironmentVariables($valetSitePath, $siteName);

/**
 * Allow driver to mutate incoming URL.
 */
$uri = $valetDriver->mutateUri($uri);

/**
 * Determine if the incoming request is for a static file.
 */
$isPhpFile = pathinfo($uri, PATHINFO_EXTENSION) === 'php';

if ($uri !== '/' && ! $isPhpFile && $staticFilePath = $valetDriver->isStaticFile($valetSitePath, $siteName, $uri)) {
    $valetDriver->serveStaticFile($staticFilePath, $valetSitePath, $siteName, $uri);
    return;
}

/**
 * Allow for drivers to take preloading actions (e.g. setting server variables).
 */
$valetDriver->beforeLoading($valetSitePath, $siteName, $uri);

/**
 * Attempt to dispatch to a front controller.
 */
$frontControllerPath = $valetDriver->frontControllerPath($valetSitePath, $siteName, $uri);

if (! $frontControllerPath) {
    if (isset($valetConfig['directory-listing']) && $valetConfig['directory-listing'] == 'on') {
        Server::showDirectoryListing($valetSitePath, $uri);
    }

    Server::show404();
}

chdir(dirname($frontControllerPath));

require_once $frontControllerPath;
