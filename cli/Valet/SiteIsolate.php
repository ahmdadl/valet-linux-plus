<?php

namespace Valet;

use ConsoleComponents\Writer;
use DomainException;
use Illuminate\Support\Collection;
use Valet\Contracts\PackageManager;
use Valet\Facades\DevTools as DevToolsFacade;
use Valet\Facades\Nginx as NginxFacade;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Traits\Paths;

class SiteIsolate
{
    use Paths;

    private PackageManager $pm;
    private Configuration $config;
    private Filesystem $files;
    private SiteSecure $siteSecure;
    private Site $site;

    public function __construct(
        PackageManager $pm,
        Configuration $config,
        Filesystem $filesystem,
        SiteSecure $siteSecure,
        Site $site
    ) {
        $this->pm = $pm;
        $this->config = $config;
        $this->files = $filesystem;
        $this->siteSecure = $siteSecure;
        $this->site = $site;
    }

    /**
     * Isolate a given directory to use a specific version of PHP.
     */
    public function isolateDirectory(string $directory, string $version, bool $secure = false): bool
    {
        try {
            $site = $this->site->getSiteUrl($directory);

            $version = PhpFpmFacade::normalizePhpVersion($version);
            $this->validateIsolationVersion($version);

            $fpmName = $this->pm->getPhpFpmName($version);
            if (!$this->pm->installed($fpmName)) {
                PhpFpmFacade::install($version);
            }

            $oldCustomPhpVersion = $this->isolatedPhpVersion($site);

            $this->isolate($site, $version, $secure);

            if ($oldCustomPhpVersion) {
                PhpFpmFacade::stopIfUnused($oldCustomPhpVersion);
            }

            PhpFpmFacade::restart($version);
            NginxFacade::restart();

            $this->addBinFileToConfig($version, $directory);
        } catch (DomainException $exception) {
            Writer::error($exception->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Remove PHP version isolation for a given directory.
     */
    public function unIsolateDirectory(string $directory): void
    {
        $site = $this->site->getSiteUrl($directory);

        $oldCustomPhpVersion = $this->isolatedPhpVersion($site);

        $this->removeIsolation($site);

        if ($oldCustomPhpVersion) {
            PhpFpmFacade::stopIfUnused($oldCustomPhpVersion);
        }
        NginxFacade::restart();

        $this->removeBinFromConfig($directory);
    }

    /**
     * List isolated directories with version.
     */
    public function isolatedDirectories(): Collection
    {
        $securedSites = $this->siteSecure->secured();

        return NginxFacade::configuredSites()->filter(function ($item) {
            return str_contains($this->files->get($this->nginxPath($item)), ISOLATED_PHP_VERSION);
        })->map(function ($site) use ($securedSites) {
            $secured = $securedSites->contains($site);

            $url = \sprintf(
                '%s://%s',
                $secured ? 'https' : 'http',
                $site
            );

            return [
                'url' => $url,
                'secured'   => $secured ? '✓' : '✕',
                'version' => PhpFpmFacade::normalizePhpVersion((string)$this->isolatedPhpVersion($site))
            ];
        });
    }

    /**
     * Extract PHP version of exising nginx config.
     */
    public function isolatedPhpVersion(string $url): ?string
    {
        if (!$this->files->exists($this->nginxPath($url))) {
            return null;
        }

        $siteConf = $this->files->get($this->nginxPath($url));
        if (strpos($siteConf, '# ' . ISOLATED_PHP_VERSION) !== false) {
            // Capture the version without requiring a trailing newline so the
            // marker works regardless of how the config file ends.
            if (preg_match('/^# ISOLATED_PHP_VERSION=([^\s]+)/m', $siteConf, $version)) {
                return $version[1];
            }
        }

        return null;
    }

    /**
     * Create new nginx config or modify existing nginx config to isolate this site
     * to a custom version of PHP.
     */
    private function isolate(string $url, string $phpVersion, bool $secure = false): void
    {
        $stub = $secure ?
            VALET_ROOT_PATH . '/cli/stubs/secure.isolated.valet.conf'
            : VALET_ROOT_PATH . '/cli/stubs/isolated.valet.conf';

        // Isolate specific variables
        $siteConf = strArrayReplace([
            'VALET_FPM_SOCKET_FILE'      => PhpFpmFacade::fpmSocketFile($phpVersion),
            'VALET_ISOLATED_PHP_VERSION' => $phpVersion,
        ], $this->files->get($stub));

        if ($secure) {
            $this->siteSecure->secure($url, $siteConf);
        } else {
            $siteConf = $this->siteSecure->buildUnsecureNginxServer($url, $siteConf);

            $this->files->putAsUser(
                $this->nginxPath($url),
                $siteConf
            );
        }
    }

    /**
     * Regenerate the Nginx config for every isolated site using the current
     * Valet paths.
     *
     * Per-site configs bake in an absolute path to server.php at isolation
     * time. When Valet is relocated (e.g. reinstalled under a new vendor
     * path) those paths go stale and PHP-FPM returns "File not found" for
     * every isolated site. Regenerating keeps them in sync without touching
     * the TLS certificates.
     */
    public function rewriteIsolatedNginxFiles(): void
    {
        foreach (NginxFacade::configuredSites() as $site) {
            if (!is_string($site)) {
                continue;
            }

            $version = $this->isolatedPhpVersion($site);
            if ($version === null) {
                continue;
            }

            $secure = $this->siteSecure->secured()->contains($site);
            $this->writeIsolationConfig($site, $version, $secure);
        }
    }

    /**
     * Write the Nginx config for an isolated site from the current Valet stub,
     * preserving the isolated PHP-FPM socket and (for secure sites) the
     * existing certificates.
     */
    private function writeIsolationConfig(string $url, string $phpVersion, bool $secure = false): void
    {
        $stub = $secure
            ? VALET_ROOT_PATH . '/cli/stubs/secure.isolated.valet.conf'
            : VALET_ROOT_PATH . '/cli/stubs/isolated.valet.conf';

        $siteConf = strArrayReplace([
            'VALET_FPM_SOCKET_FILE'      => PhpFpmFacade::fpmSocketFile($phpVersion),
            'VALET_ISOLATED_PHP_VERSION' => $phpVersion,
            'VALET_HOME_PATH'            => VALET_HOME_PATH,
            'VALET_SERVER_PATH'          => VALET_SERVER_PATH,
            'VALET_STATIC_PREFIX'        => VALET_STATIC_PREFIX,
            'VALET_SITE'                 => $url,
            'VALET_HTTP_PORT'            => $this->config->get('port', 80),
            'VALET_HTTPS_PORT'           => $this->config->get('https_port', 443),
        ], $this->files->get($stub));

        if ($secure) {
            $path = $this->certificatesPath();
            $siteConf = strArrayReplace([
                'VALET_CERT' => $path . '/' . $url . '.crt',
                'VALET_KEY'  => $path . '/' . $url . '.key',
            ], $siteConf);

            $this->files->putAsUser($this->nginxPath($url), $siteConf);

            return;
        }

        $siteConf = $this->siteSecure->buildUnsecureNginxServer($url, $siteConf);
        $this->files->putAsUser($this->nginxPath($url), $siteConf);
    }

    /**
     * Remove PHP Version isolation from a specific site.
     */
    private function removeIsolation(string $siteName): void
    {
        // If a site has an SSL certificate, we need to keep its custom config file, but we can
        // just re-generate it without defining a custom `valet.sock` file
        if ($this->files->exists($this->certificatesPath() . '/' . $siteName . '.crt')) {
            $conf = $this->siteSecure->buildSecureNginxServer($siteName);
            $this->files->putAsUser($this->nginxPath($siteName), $conf);

            return;
        }

        // When site doesn't have SSL, we can remove the custom nginx config file to remove isolation
        $this->files->unlink($this->nginxPath($siteName));
    }

    /**
     * Validate PHP version for isolation process.
     */
    private function validateIsolationVersion(string $version): void
    {
        if (!PhpFpm::isIsolationSupportedVersion($version)) {
            throw new DomainException(
                sprintf(
                    "Invalid version [%s] used. Supported versions are: %s or later (%s)",
                    $version,
                    PhpFpm::MIN_ISOLATION_VERSION,
                    implode(', ', PhpFpm::isolationSupportedPhpVersions())
                )
            );
        }
    }

    private function addBinFileToConfig(string $version, string $directoryName): void
    {
        $directoryName = $this->removeTld($directoryName);
        $binaryFile = DevToolsFacade::getBin('php' . $version, ['/usr/local/bin/php']);
        /** @var array<string, string> $isolatedConfig */
        $isolatedConfig = $this->config->get('isolated_versions', []);

        $isolatedConfig[$directoryName] = $binaryFile;
        $this->config->set('isolated_versions', $isolatedConfig);
    }

    private function removeBinFromConfig(string $directoryName): void
    {
        $directoryName = $this->removeTld($directoryName);
        /** @var array<string, string> $isolatedConfig */
        $isolatedConfig = $this->config->get('isolated_versions', []);
        if (isset($isolatedConfig[$directoryName])) {
            unset($isolatedConfig[$directoryName]);
            $this->config->set('isolated_versions', $isolatedConfig);
        }
    }

    private function removeTld(string $domainName): string
    {
        /** @var string $tld */
        $tld = $this->config->get('domain');
        if (str_ends_with($domainName, \sprintf('.%s', $tld))) {
            $domainName = str_replace(\sprintf('.%s', $tld), '', $domainName);
        }

        return $domainName;
    }
}
