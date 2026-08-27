<?php

namespace Valet;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Valet\Contracts\ServiceManager;
use Valet\Facades\PhpFpm as PhpFpmFacade;

class Dashboard
{
    private Configuration $config;
    private Filesystem $files;
    private SiteLink $siteLink;
    private SiteProxy $siteProxy;
    private SiteSecure $siteSecure;
    private SiteIsolate $siteIsolate;
    private Nginx $nginx;
    private PhpFpm $phpFpm;

    /**
     * Create a new Dashboard instance.
     *
     * Dependencies are injected so the collector can be exercised in isolation
     * (tests). Cross-service calls that are not part of the constructor
     * signature are resolved from the container on demand (best-effort).
     */
    public function __construct(
        Configuration $config,
        Filesystem $files,
        SiteLink $siteLink,
        SiteProxy $siteProxy,
        SiteSecure $siteSecure,
        SiteIsolate $siteIsolate,
        Nginx $nginx,
        PhpFpm $phpFpm
    ) {
        $this->config = $config;
        $this->files = $files;
        $this->siteLink = $siteLink;
        $this->siteProxy = $siteProxy;
        $this->siteSecure = $siteSecure;
        $this->siteIsolate = $siteIsolate;
        $this->nginx = $nginx;
        $this->phpFpm = $phpFpm;
    }

    /**
     * Build the full dashboard payload.
     *
     * Every individual gather is wrapped in a try/catch so a single failing
     * source can never break the whole dashboard. Failures degrade to safe
     * defaults rather than throwing.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $domain = $this->safeDomain();

        $sites = $this->gatherSites($domain);

        return [
            'domain'        => $domain,
            'port'          => $this->safePort('port', 80),
            'https_port'    => $this->safePort('https_port', 443),
            'php_version'   => $this->safePhpVersion(),
            'php_versions'  => $this->safePhpVersions(),
            'paths'         => $this->safePaths(),
            'sites'         => $sites,
            'counts'        => $this->buildCounts($sites),
            'services'      => $this->gatherServices(),
            'nginx_sites'   => $this->gatherNginxSites(),
            'valet_version' => $this->gatherValetVersion(),
        ];
    }

    /**
     * Render the dashboard.
     *
     * If the designer-provided template exists we inject the JSON payload into
     * the {{VALET_DATA_JSON}} placeholder (and a few convenience placeholders).
     * When the template is missing we simply return the JSON-encoded payload so
     * the endpoint is still usable.
     */
    public function render(): string
    {
        $data = $this->data();
        $json = json_encode($data) ?: '{}';

        $templatePath = VALET_ROOT_PATH . '/cli/templates/dashboard.html';

        if ($this->files->exists($templatePath)) {
            $template = $this->files->get($templatePath);

            if (is_string($template)) {
                /** @var array<int, array<string, mixed>> $sites */
                $sites = $data['sites'];

                $template = str_replace('{{VALET_DATA_JSON}}', $json, $template);
                $template = str_replace('{{VALET_SITES_ROWS}}', $this->renderSitesRows($sites), $template);
                return str_replace(
                    'window.__VALET_DATA__',
                    'window.__VALET_DATA__ = ' . $json,
                    $template
                );
            }
        }

        return $json;
    }

    /**
     * Gather all sites (parked, linked and proxied) as a unified, alphabetically
     * sorted list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gatherSites(string $domain): array
    {
        $securedSites = $this->safeSecured();
        $isolatedMap = $this->safeIsolatedMap($domain);

        $sites = [];

        // Parked sites: directories inside the configured "paths".
        foreach ($this->safePaths() as $path) {
            if ($path === $this->sitesPath()) {
                continue;
            }

            try {
                $entries = $this->files->scandir($path);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($entries as $name) {
                $name = (string) $name;

                if (!$this->files->isDir($path . '/' . $name)) {
                    continue;
                }

                $secured = $securedSites->contains($name . '.' . $domain);
                $sites[$name] = $this->buildSiteEntry(
                    $name,
                    $path . '/' . $name,
                    $secured,
                    $isolatedMap->get($name),
                    null,
                    'parked'
                );
            }
        }

        // Linked sites.
        try {
            foreach ($this->siteLink->links() as $name => $link) {
                $name = (string) $name;
                if (isset($sites[$name])) {
                    continue;
                }
                $linkArr = (array) $link;
                $secured = $this->isSecuredFlag($linkArr['secured'] ?? '✕');
                $sites[$name] = $this->buildSiteEntry(
                    $name,
                    (string) ($linkArr['path'] ?? ''),
                    $secured,
                    $isolatedMap->get($name),
                    null,
                    'linked'
                );
            }
        } catch (\Throwable $e) {
            // Ignore link enumeration failures.
        }

        // Proxied sites.
        try {
            foreach ($this->siteProxy->proxies() as $site => $proxy) {
                $name = str_replace('.' . $domain, '', (string) $site);
                if (isset($sites[$name])) {
                    continue;
                }
                $proxyArr = (array) $proxy;
                $secured = $this->isSecuredFlag($proxyArr['secured'] ?? '✕');
                $sites[$name] = $this->buildSiteEntry(
                    $name,
                    (string) ($proxyArr['path'] ?? ''),
                    $secured,
                    $isolatedMap->get($name),
                    $proxyArr['path'] ?? null,
                    'proxy'
                );
            }
        } catch (\Throwable $e) {
            // Ignore proxy enumeration failures.
        }

        ksort($sites, SORT_STRING);

        return array_values($sites);
    }

    /**
     * Build a single normalized site entry.
     *
     * @param mixed $isolated
     * @param mixed $proxy
     * @return array<string, mixed>
     */
    private function buildSiteEntry(
        string $name,
        string $path,
        bool $secured,
        $isolated,
        $proxy,
        string $type
    ): array {
        return [
            'name'     => $name,
            'url'      => $this->buildUrl($name, $secured),
            'path'     => $path,
            'secured'  => $secured,
            'isolated' => $isolated,
            'proxy'    => $proxy,
            'type'     => $type,
        ];
    }

    /**
     * Normalize a "secured" marker (SiteLink/SiteProxy use ✓ / ✕) to bool.
     *
     * @param mixed $flag
     */
    private function isSecuredFlag($flag): bool
    {
        return $flag === '✓' || $flag === true || $flag === 1;
    }

    /**
     * Build the canonical site URL from its name + secured state.
     */
    private function buildUrl(string $name, bool $secured): string
    {
        $domain = $this->safeDomain();
        $scheme = $secured ? 'https' : 'http';
        $port = $secured
            ? $this->safePort('https_port', 443)
            : $this->safePort('port', 80);

        $portSuffix = ($secured && $port === 443) || (!$secured && $port === 80)
            ? ''
            : ':' . $port;

        return sprintf('%s://%s.%s%s', $scheme, $name, $domain, $portSuffix);
    }

    /**
     * Build the dashboard counts from the unified site list.
     *
     * @param array<int, array<string, mixed>> $sites
     * @return array<string, int>
     */
    private function buildCounts(array $sites): array
    {
        $counts = [
            'parked'   => 0,
            'linked'   => 0,
            'proxied'  => 0,
            'secured'  => 0,
            'isolated' => 0,
            'total'    => 0,
        ];

        foreach ($sites as $site) {
            $counts['total']++;

            if (($site['type'] ?? '') === 'parked') {
                $counts['parked']++;
            } elseif (($site['type'] ?? '') === 'linked') {
                $counts['linked']++;
            } elseif (($site['type'] ?? '') === 'proxy') {
                $counts['proxied']++;
            }

            if (!empty($site['secured'])) {
                $counts['secured']++;
            }

            if (!empty($site['isolated'])) {
                $counts['isolated']++;
            }
        }

        return $counts;
    }

    /**
     * Best-effort, non-destructive service status collection.
     *
     * We never start/stop anything. If the service manager is unavailable or a
     * command fails we degrade to installed=false / status='unknown'.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gatherServices(): array
    {
        $managerAvailable = false;
        try {
            /** @var ServiceManager $serviceManager */
            $serviceManager = resolve(ServiceManager::class);
            $managerAvailable = $serviceManager->isAvailable();
        } catch (\Throwable $e) {
            $managerAvailable = false;
        }

        $cli = null;
        if ($managerAvailable) {
            try {
                $cli = resolve(CommandLine::class);
            } catch (\Throwable $e) {
                $cli = null;
            }
        }

        return collect($this->serviceList())->map(function (string $name) use ($cli) {
            if ($cli === null) {
                return [
                    'name'      => $name,
                    'installed' => false,
                    'status'    => 'unknown',
                ];
            }

            /** @var CommandLine $cli */
            return [
                'name'      => $name,
                'installed' => $this->serviceInstalled($cli, $name),
                'status'    => $this->serviceStatus($cli, $name),
            ];
        })->values()->all();
    }

    /**
     * The list of services to report on. The PHP-FPM service name is derived
     * from the currently configured PHP version.
     *
     * @return array<int, string>
     */
    private function serviceList(): array
    {
        $phpVersion = $this->safePhpVersion();
        $phpService = 'php' . str_replace('.', '', $phpVersion) . '-fpm';

        $services = [
            'nginx',
            $phpService,
            'mailpit',
            'mysql',
            'redis',
            'postgres',
        ];

        // Append user-defined custom services (best-effort, never fatal).
        try {
            $custom = \Valet\Facades\ServiceRegistry::customServices();
            foreach (array_keys($custom) as $name) {
                $services[] = (string) $name;
            }
        } catch (\Throwable $e) {
            // Ignore custom service enumeration failures.
        }

        return $services;
    }

    /**
     * Count of sites that have an explicit Nginx configuration. Uses the
     * injected Nginx dependency so the collector stays consistent with the
     * rest of the services.
     */
    private function gatherNginxSites(): int
    {
        try {
            return $this->nginx->configuredSites()->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Determine whether a service unit is installed (best-effort).
     */
    private function serviceInstalled(CommandLine $cli, string $name): bool
    {
        try {
            $output = trim($cli->run("systemctl is-enabled {$name} 2>/dev/null"));

            if ($output === '' || str_contains($output, 'Failed to') || str_contains($output, 'No such')) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Determine the active state of a service (best-effort).
     */
    private function serviceStatus(CommandLine $cli, string $name): string
    {
        try {
            $output = trim($cli->run("systemctl is-active {$name} 2>/dev/null"));

            if ($output === 'active' || $output === 'running') {
                return 'running';
            }

            if ($output === 'unknown') {
                return 'unknown';
            }

            return 'stopped';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * Read the Valet version from composer.json, falling back to "unknown".
     */
    private function gatherValetVersion(): string
    {
        try {
            $composer = $this->files->get(VALET_ROOT_PATH . '/composer.json');

            if (!is_string($composer)) {
                return 'unknown';
            }

            $decoded = json_decode($composer, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded) && isset($decoded['version']) && is_string($decoded['version'])) {
                return $decoded['version'];
            }
        } catch (\Throwable $e) {
            // Fall through to the hardcoded fallback.
        }

        return 'unknown';
    }

    /**
     * @return Collection<int, string>
     */
    private function safeSecured(): Collection
    {
        try {
            return $this->siteSecure->secured();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Map of isolated site name (without TLD) => isolated PHP version.
     *
     * @return Collection<string, mixed>
     */
    private function safeIsolatedMap(string $domain): Collection
    {
        try {
            return $this->siteIsolate->isolatedDirectories()->mapWithKeys(function ($item, $key) use ($domain) {
                $name = str_replace('.' . $domain, '', (string) $key);
                $itemArr = (array) $item;

                return [$name => $itemArr['version'] ?? null];
            });
        } catch (\Throwable $e) {
            return collect();
        }
    }

    private function safeDomain(): string
    {
        try {
            $value = $this->config->get('domain', 'test');

            return is_string($value) ? $value : 'test';
        } catch (\Throwable $e) {
            return 'test';
        }
    }

    /**
     * Read a port value from configuration, falling back to a default.
     */
    private function safePort(string $key, int $default): int
    {
        try {
            $value = $this->config->get($key, $default);

            return is_numeric($value) ? (int) $value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * @return array<int, string>
     */
    private function safePaths(): array
    {
        try {
            $paths = $this->config->get('paths', []);

            return is_array($paths) ? $paths : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function safePhpVersion(): string
    {
        try {
            return $this->phpFpm->getCurrentVersion();
        } catch (\Throwable $e) {
            return PhpFpmFacade::normalizePhpVersion(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?: 'unknown';
        }
    }

    /**
     * @return array<int, string>
     */
    private function safePhpVersions(): array
    {
        return PhpFpm::SUPPORTED_PHP_VERSIONS;
    }

    /**
     * Render simple HTML table rows for the {{VALET_SITES_ROWS}} placeholder.
     *
     * @param array<int, array<string, mixed>> $sites
     */
    private function renderSitesRows(array $sites): string
    {
        $rows = '';
        foreach ($sites as $site) {
            /** @var array<string, mixed> $site */
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars($this->asString($site['name'] ?? ''), ENT_QUOTES),
                htmlspecialchars($this->asString($site['url'] ?? ''), ENT_QUOTES),
                htmlspecialchars($this->asString($site['type'] ?? ''), ENT_QUOTES),
                !empty($site['secured']) ? 'yes' : 'no',
                htmlspecialchars($this->asString($site['isolated'] ?? ''), ENT_QUOTES),
                htmlspecialchars($this->asString($site['proxy'] ?? ''), ENT_QUOTES)
            );
        }

        return $rows;
    }

    /**
     * Coerce a mixed value to a string without triggering a cast error.
     */
    private function asString(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Get the path to the linked Valet sites (mirrors the Paths trait helper so
     * we don't need to pull the trait into this collector).
     */
    private function sitesPath(): string
    {
        return VALET_HOME_PATH . '/Sites';
    }
}
