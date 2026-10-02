<?php

declare(strict_types=1);

namespace Valet;

use ConsoleComponents\Writer;
use Illuminate\Support\Collection;

class SiteSleep
{
    private Configuration $config;
    private Filesystem $files;
    private SiteLink $siteLink;
    private Nginx $nginx;
    private PhpFpm $phpFpm;
    private SiteIsolate $siteIsolate;
    private SiteSecure $siteSecure;

    public function __construct(
        Configuration $config,
        Filesystem $files,
        SiteLink $siteLink,
        Nginx $nginx,
        PhpFpm $phpFpm,
        SiteIsolate $siteIsolate,
        SiteSecure $siteSecure
    ) {
        $this->config = $config;
        $this->files = $files;
        $this->siteLink = $siteLink;
        $this->nginx = $nginx;
        $this->phpFpm = $phpFpm;
        $this->siteIsolate = $siteIsolate;
        $this->siteSecure = $siteSecure;
    }

    /**
     * Put a site (or all sites) to sleep.
     */
    public function sleep(?string $site = null, bool $all = false, bool $withServices = false): void
    {
        $sites = $this->resolveSites($site, $all);
        $sitesConfig = $this->config->get('sites', []);
        if (!is_array($sitesConfig)) {
            $sitesConfig = [];
        }

        foreach ($sites as $siteName) {
            $url = $this->config->parseDomain($siteName);
            $normalized = $this->normalizeSiteName($url);

            $version = $this->siteIsolate->isolatedPhpVersion($url);
            $canStopFpm = false;

            if ($version !== null) {
                // Check if this version is used by only this site
                $sitesUsingVersion = $this->countSitesUsingPhpVersion($version);
                if ($sitesUsingVersion === 1) {
                    $canStopFpm = true;
                }
            }

            if ($canStopFpm && $withServices) {
                $this->phpFpm->stopIfUnused($version);
            } elseif ($version !== null && !$canStopFpm && $withServices) {
                Writer::warn(sprintf(
                    'PHP-FPM version [%s] is shared by multiple sites; not stopping. Only asleep flag set.',
                    $version
                ));
            } elseif ($version === null && $withServices) {
                Writer::warn('Site is not isolated; only asleep flag set (global pool not stopped).');
            }

            $sitesConfig[$normalized]['asleep'] = true;
        }

        $this->config->set('sites', $sitesConfig);
    }

    /**
     * Wake a site (or all sites) from sleep.
     */
    public function wake(?string $site = null, bool $all = false): void
    {
        $sites = $this->resolveSites($site, $all);
        $sitesConfig = $this->config->get('sites', []);
        if (!is_array($sitesConfig)) {
            $sitesConfig = [];
        }

        foreach ($sites as $siteName) {
            $url = $this->config->parseDomain($siteName);
            $normalized = $this->normalizeSiteName($url);

            $version = $this->siteIsolate->isolatedPhpVersion($url);
            if ($version !== null) {
                try {
                    $this->phpFpm->restart($version);
                } catch (\Throwable $e) {
                    Writer::warn(sprintf('Failed to restart PHP-FPM [%s]: %s', $version, $e->getMessage()));
                }
            }

            if (isset($sitesConfig[$normalized]['asleep'])) {
                $sitesConfig[$normalized]['asleep'] = false;
            }
        }

        $this->config->set('sites', $sitesConfig);
    }

    /**
     * Check if a site is asleep.
     */
    public function isAsleep(string $site): bool
    {
        $url = $this->config->parseDomain($site);
        $normalized = $this->normalizeSiteName($url);
        $sitesConfig = $this->config->get('sites', []);
        if (!is_array($sitesConfig)) {
            return false;
        }

        return isset($sitesConfig[$normalized]['asleep']) && $sitesConfig[$normalized]['asleep'] === true;
    }

    /**
     * Get all asleep sites.
     */
    public function asleepSites(): array
    {
        $sitesConfig = $this->config->get('sites', []);
        if (!is_array($sitesConfig)) {
            return [];
        }

        $asleep = [];
        foreach ($sitesConfig as $site => $data) {
            if (is_array($data) && ($data['asleep'] ?? false) === true) {
                $asleep[] = $site;
            }
        }

        return $asleep;
    }

    /**
     * Get status rows for all sites (for status command).
     *
     * @return array<int, array{site: string, url: string, asleep: bool, isolatedVersion: string|null, poolShared: bool}>
     */
    public function statusRows(): array
    {
        $links = $this->siteLink->links();
        $configuredSites = $this->nginx->configuredSites()->all();
        $sitesConfig = $this->config->get('sites', []);
        if (!is_array($sitesConfig)) {
            $sitesConfig = [];
        }

        $rows = [];

        foreach ($links as $link) {
            $siteName = $this->extractSiteFromUrl($link['url']);
            $url = $this->config->parseDomain($siteName);
            $normalized = $this->normalizeSiteName($url);

            $asleep = isset($sitesConfig[$normalized]['asleep']) && $sitesConfig[$normalized]['asleep'] === true;
            $version = $this->siteIsolate->isolatedPhpVersion($url);

            // Check if pool is shared (multiple sites using same version)
            $poolShared = false;
            if ($version !== null) {
                $sitesUsingVersion = $this->countSitesUsingPhpVersion($version);
                $poolShared = $sitesUsingVersion > 1;
            }

            $rows[] = [
                'site' => $siteName,
                'url' => $link['url'],
                'asleep' => $asleep,
                'isolatedVersion' => $version,
                'poolShared' => $poolShared,
            ];
        }

        return $rows;
    }

    /**
     * Resolve sites to operate on.
     *
     * @return array<int, string>
     */
    private function resolveSites(?string $site, bool $all): array
    {
        if ($all) {
            return array_keys($this->siteLink->links()->all());
        }

        if ($site === null) {
            $contextSite = \Valet\Facades\ProjectContext::site();
            $site = $contextSite ?: basename(getcwd());
        }

        $url = $this->config->parseDomain($site);

        // Verify site exists
        $links = $this->siteLink->links();
        $configuredSites = $this->nginx->configuredSites()->all();

        $exists = false;
        foreach ($links as $link) {
            if ($this->extractSiteFromUrl($link['url']) === $site) {
                $exists = true;
                break;
            }
        }

        if (!$exists && in_array($url, $configuredSites)) {
            $exists = true;
        }

        if (!$exists) {
            throw new \DomainException(sprintf('Site [%s] not found.', $site));
        }

        return [$site];
    }

    /**
     * Count how many sites use a given PHP version.
     * Uses configured sites (Nginx) as the source of truth to avoid double-counting.
     */
    private function countSitesUsingPhpVersion(string $version): int
    {
        $count = 0;
        $configuredSites = $this->nginx->configuredSites()->all();

        foreach ($configuredSites as $configuredSite) {
            $siteVersion = $this->siteIsolate->isolatedPhpVersion($configuredSite);
            if ($siteVersion === $version) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Normalize site name by stripping TLD (similar to SiteIsolate::removeTld).
     */
    private function normalizeSiteName(string $url): string
    {
        $tld = $this->config->get('domain');
        if (str_ends_with($url, '.' . $tld)) {
            return str_replace('.' . $tld, '', $url);
        }
        return $url;
    }

    /**
     * Extract site name from full URL.
     */
    private function extractSiteFromUrl(string $url): string
    {
        // URL format: http(s)://site.domain:port
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? $url;
        $tld = $this->config->get('domain');
        if (str_ends_with($host, '.' . $tld)) {
            return str_replace('.' . $tld, '', $host);
        }
        return $host;
    }
}