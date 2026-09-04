<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Facades\Configuration as ConfigurationFacade;
use Valet\Facades\Nginx as NginxFacade;
use Valet\Facades\SiteLink as SiteLinkFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

/**
 * Always-on Adminer database GUI at https://database.valet.&lt;domain&gt;.
 *
 * Layout under ~/.config/valet/database/:
 *   adminer.php          — downloaded Adminer core (managed)
 *   index.php            — plugin bootstrap (managed)
 *   plugins/plugin.php   — AdminerPlugin base class (managed)
 *   plugins/enabled.php  — user list of enabled plugins (created once)
 *   plugins/*.php        — drop plugin files here
 */
class Adminer
{
    public const SITE = 'database.valet';

    public const SITE_LEGACY = 'adminer';

    private const ADMINER_URL = 'https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1.php';

    private const PLUGIN_BASE_URL = 'https://raw.githubusercontent.com/vrana/adminer/v4.8.1/plugins/plugin.php';

    public function __construct(
        public Filesystem $files,
        public Configuration $config,
        public CommandLine $cli
    ) {
    }

    /**
     * Install / refresh Adminer so database.valet.&lt;domain&gt; always works.
     */
    public function install(): void
    {
        $this->ensureFiles();
        $this->linkAndSecure();
        Writer::info(sprintf('Adminer ready at %s', $this->url()));
        Writer::info(sprintf('Plugins directory: %s', $this->pluginsPath()));
        Writer::info(sprintf('Enable plugins in: %s', $this->enabledPluginsFile()));
    }

    /**
     * Ensure files exist without re-printing install banners (safe to call often).
     */
    public function ensureInstalled(): void
    {
        $this->ensureFiles();
        if (!$this->isLinked()) {
            $this->linkAndSecure();
        }
    }

    public function uninstall(): void
    {
        $domain = $this->domain();
        try {
            SiteSecureFacade::unsecure(self::SITE . '.' . $domain);
        } catch (\Throwable $e) {
        }
        try {
            SiteLinkFacade::unlink(self::SITE);
        } catch (\Throwable $e) {
        }
        $this->removeLegacyLink($domain);
        // Keep ~/.config/valet/database (plugins / user config).
    }

    public function path(): string
    {
        return VALET_HOME_PATH . '/database';
    }

    public function pluginsPath(): string
    {
        return $this->path() . '/plugins';
    }

    public function enabledPluginsFile(): string
    {
        return $this->pluginsPath() . '/enabled.php';
    }

    public function url(): string
    {
        return 'https://' . self::SITE . '.' . $this->domain();
    }

    /**
     * Print status, URL, and customization paths.
     */
    public function status(bool $open = false, bool $pathOnly = false): void
    {
        $this->ensureInstalled();

        if ($pathOnly) {
            Writer::info($this->pluginsPath());

            return;
        }

        Writer::info(sprintf('URL:      %s', $this->url()));
        Writer::info(sprintf('Home:     %s', $this->path()));
        Writer::info(sprintf('Plugins:  %s', $this->pluginsPath()));
        Writer::info(sprintf('Enable:   %s', $this->enabledPluginsFile()));
        Writer::info('Drop .php plugin files into the plugins directory, then list them in enabled.php.');
        Writer::info('Plugin docs: https://www.adminer.org/en/plugins/');

        if ($open) {
            $this->cli->quietly('xdg-open ' . escapeshellarg($this->url()));
        }
    }

    private function ensureFiles(): void
    {
        $root = $this->path();
        $plugins = $this->pluginsPath();
        $this->files->ensureDirExists($root, user());
        $this->files->ensureDirExists($plugins, user());

        $adminerPhp = $root . '/adminer.php';
        $legacy = VALET_HOME_PATH . '/addons/adminer/index.php';
        if (!$this->files->exists($adminerPhp) && $this->files->exists($legacy)) {
            $this->files->putAsUser($adminerPhp, $this->files->get($legacy));
        }
        if (!$this->files->exists($adminerPhp)) {
            $this->download(self::ADMINER_URL, $adminerPhp, 'Adminer');
        }

        $pluginBase = $plugins . '/plugin.php';
        if (!$this->files->exists($pluginBase)) {
            $this->download(self::PLUGIN_BASE_URL, $pluginBase, 'Adminer plugin base');
        }

        // Always refresh Valet-managed bootstrap.
        $this->files->putAsUser($root . '/index.php', $this->indexStub());

        if (!$this->files->exists($this->enabledPluginsFile())) {
            $this->files->putAsUser($this->enabledPluginsFile(), $this->enabledStub());
        }

        $readme = $plugins . '/README.txt';
        if (!$this->files->exists($readme)) {
            $this->files->putAsUser($readme, $this->pluginsReadme());
        }
    }

    private function linkAndSecure(): void
    {
        $domain = $this->domain();
        $this->removeLegacyLink($domain);

        // Also migrate away from addons/adminer Sites link if any.
        $legacyAddonLink = VALET_HOME_PATH . '/Sites/' . self::SITE_LEGACY;
        if ($this->files->exists($legacyAddonLink) || is_link($legacyAddonLink)) {
            try {
                SiteSecureFacade::unsecure(self::SITE_LEGACY . '.' . $domain);
            } catch (\Throwable $e) {
            }
            try {
                SiteLinkFacade::unlink(self::SITE_LEGACY);
            } catch (\Throwable $e) {
            }
        }

        SiteLinkFacade::link($this->path(), self::SITE);
        $host = self::SITE . '.' . $domain;
        try {
            SiteSecureFacade::secure($host);
        } catch (\Throwable $e) {
            Writer::warn('Could not secure Adminer: ' . $e->getMessage());
        }
        NginxFacade::restart();
    }

    private function isLinked(): bool
    {
        $link = VALET_HOME_PATH . '/Sites/' . self::SITE;

        return $this->files->exists($link) || is_link($link);
    }

    private function removeLegacyLink(string $domain): void
    {
        $legacy = VALET_HOME_PATH . '/Sites/' . self::SITE_LEGACY;
        if (!$this->files->exists($legacy) && !is_link($legacy)) {
            return;
        }
        try {
            SiteSecureFacade::unsecure(self::SITE_LEGACY . '.' . $domain);
        } catch (\Throwable $e) {
        }
        try {
            SiteLinkFacade::unlink(self::SITE_LEGACY);
        } catch (\Throwable $e) {
        }
    }

    private function download(string $url, string $destination, string $label): void
    {
        Writer::info(sprintf('Downloading %s…', $label));
        $tmp = tempnam(sys_get_temp_dir(), 'valet-adminer-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temp file for download');
        }

        $this->cli->run(sprintf(
            'curl -fsSL %s -o %s',
            escapeshellarg($url),
            escapeshellarg($tmp)
        ), function ($code, $output) use ($label) {
            throw new \RuntimeException(trim((string) $output) ?: sprintf('%s download failed', $label));
        });

        $this->files->putAsUser($destination, $this->files->get($tmp));
        @unlink($tmp);
    }

    private function domain(): string
    {
        $domainRaw = $this->config->get('domain', 'test');

        return is_scalar($domainRaw) ? (string) $domainRaw : 'test';
    }

    private function indexStub(): string
    {
        $stub = VALET_ROOT_PATH . '/cli/stubs/adminer/index.php';
        if ($this->files->exists($stub)) {
            return $this->files->get($stub);
        }

        // Fallback if stub missing.
        return <<<'PHP'
<?php
function adminer_object() {
    include_once __DIR__ . '/plugins/plugin.php';
    foreach (glob(__DIR__ . '/plugins/*.php') ?: [] as $filename) {
        $base = basename($filename);
        if ($base === 'plugin.php' || $base === 'enabled.php') {
            continue;
        }
        include_once $filename;
    }
    $plugins = [];
    $enabled = __DIR__ . '/plugins/enabled.php';
    if (is_file($enabled)) {
        $list = include $enabled;
        if (is_array($list)) {
            $plugins = $list;
        }
    }
    return new AdminerPlugin($plugins);
}
include __DIR__ . '/adminer.php';
PHP;
    }

    private function enabledStub(): string
    {
        $stub = VALET_ROOT_PATH . '/cli/stubs/adminer/plugins/enabled.php';
        if ($this->files->exists($stub)) {
            return $this->files->get($stub);
        }

        return <<<'PHP'
<?php
/**
 * Enable Adminer plugins by returning instances below.
 * Place plugin PHP files in this directory, then add them here.
 *
 * @see https://www.adminer.org/en/plugins/
 * @return list<object>
 */
return [
    // new AdminerTablesFilter(),
    // new AdminerDumpJson(),
];
PHP;
    }

    private function pluginsReadme(): string
    {
        return <<<TXT
Valet Adminer plugins
=====================

1. Download a plugin from https://www.adminer.org/en/plugins/
2. Save the .php file into this directory
3. Enable it in enabled.php, e.g.:

       return [
           new AdminerTablesFilter(),
       ];

4. Reload https://database.valet.<your-domain>

Do not remove plugin.php — Valet manages that file.
TXT;
    }
}
