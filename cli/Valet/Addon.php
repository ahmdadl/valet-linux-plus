<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Contracts\PackageManager;
use Valet\Facades\Nginx as NginxFacade;
use Valet\Facades\ServiceRegistry as ServiceRegistryFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

class Addon
{
    /**
     * Built-in addon catalog (presets on top of service templates).
     *
     * @var array<string, array{type: 'service'|'php'|'builtin', description: string, template?: string, proxy?: string}>
     */
    public const CATALOG = [
        'meilisearch' => [
            'type' => 'service',
            'template' => 'meilisearch',
            'description' => 'Meilisearch search engine (proxied as search)',
            'proxy' => 'search',
        ],
        'adminer' => [
            'type' => 'builtin',
            'description' => 'Adminer DB GUI at database.valet.<domain> (always-on; plugins under ~/.config/valet/database/plugins)',
        ],
        'mailpit' => [
            'type' => 'builtin',
            'description' => 'Mailpit (built-in Valet mail catcher)',
        ],
    ];

    public function __construct(
        public Filesystem $files,
        public Configuration $config,
        public CommandLine $cli,
        public PackageManager $pm
    ) {
    }

    /**
     * @return array<int, array{name: string, enabled: bool, type: string, description: string}>
     */
    public function list(): array
    {
        $enabled = $this->enabledMap();
        $rows = [];

        foreach (self::CATALOG as $name => $meta) {
            $rows[] = [
                'name' => $name,
                'enabled' => !empty($enabled[$name]) || $this->isEffectivelyEnabled($name),
                'type' => $meta['type'],
                'description' => $meta['description'],
            ];
        }

        return $rows;
    }

    public function enable(string $name): void
    {
        $name = strtolower($name);
        if (!isset(self::CATALOG[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown addon [%s]. Known: %s', $name, implode(', ', array_keys(self::CATALOG))));
        }

        $meta = self::CATALOG[$name];

        match ($meta['type']) {
            'service' => $this->enableServiceAddon($name, $meta),
            'builtin' => $this->enableBuiltin($name),
        };

        $enabled = $this->enabledMap();
        $enabled[$name] = true;
        $this->config->set('addons', $enabled);

        Writer::info(sprintf('Addon [%s] enabled.', $name));
    }

    public function disable(string $name): void
    {
        $name = strtolower($name);
        if (!isset(self::CATALOG[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown addon [%s]', $name));
        }

        $meta = self::CATALOG[$name];

        match ($meta['type']) {
            'service' => $this->disableServiceAddon($name, $meta),
            'builtin' => $this->disableBuiltin($name),
        };

        $enabled = $this->enabledMap();
        unset($enabled[$name]);
        $this->config->set('addons', $enabled);

        Writer::info(sprintf('Addon [%s] disabled (data retained).', $name));
    }

    public function runList(): void
    {
        $rows = array_map(fn (array $row) => [
            $row['name'],
            $row['enabled'] ? 'yes' : 'no',
            $row['type'],
            $row['description'],
        ], $this->list());

        Writer::table(['Addon', 'Enabled', 'Type', 'Description'], $rows);
    }

    /**
     * @return array<string, bool>
     */
    private function enabledMap(): array
    {
        $raw = $this->config->get('addons', []);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $out[$key] = (bool) $value;
            }
        }

        return $out;
    }

    private function isEffectivelyEnabled(string $name): bool
    {
        if ($name === 'mailpit' || $name === 'adminer') {
            return true;
        }
        if (isset(self::CATALOG[$name]['template'])) {
            return ServiceRegistryFacade::isCustomService($name);
        }

        return false;
    }

    /**
     * @param array{type: string, description: string, template?: string, proxy?: string} $meta
     */
    private function enableServiceAddon(string $name, array $meta): void
    {
        $template = $meta['template'] ?? $name;
        $templates = ServiceRegistryFacade::templates();
        if (!isset($templates[$template])) {
            throw new \InvalidArgumentException(sprintf('Missing service template [%s]', $template));
        }

        /** @var array<string, mixed> $definition */
        $definition = $templates[$template];
        ServiceRegistryFacade::addService($name, $definition);

        $packageRaw = $definition['package'] ?? '';
        $package = is_scalar($packageRaw) ? (string) $packageRaw : '';
        if ($package !== '' && !$this->pm->installed($package)) {
            Writer::info(sprintf('Installing package [%s]…', $package));
            try {
                $this->pm->ensureInstalled($package);
            } catch (\Throwable $e) {
                Writer::warn(sprintf('Could not auto-install [%s]: %s. Install it manually, then `valet start %s`.', $package, $e->getMessage(), $name));
            }
        }

        // Fallback for packages not in apt (e.g., minio on Ubuntu 26.04): wire binary + systemd service
        if ($package !== '' && !$this->pm->installed($package)) {
            $this->tryManualInstall($name, $package);
        }
        // Even if binary now exists, ensure systemd service file is present (covers manual binary installs)
        if ($this->pm->installed($package)) {
            $this->ensureServiceFile($name, $definition);
        }

        $proxyName = $meta['proxy'] ?? $name;
        $proxyHost = isset($definition['proxyHost']) && is_string($definition['proxyHost']) ? $definition['proxyHost'] : null;
        if ($proxyHost) {
            $domain = $this->domain();
            try {
                \Valet\Facades\SiteProxy::proxyCreate($proxyName . '.' . $domain, $proxyHost, true);
            } catch (\Throwable $e) {
                Writer::warn('Proxy setup: ' . $e->getMessage());
            }
        }

        try {
            ServiceRegistryFacade::start([$name]);
        } catch (\Throwable $e) {
            Writer::warn('Start: ' . $e->getMessage());
        }

        NginxFacade::restart();
    }

    /**
     * @param array{type: string, description: string, template?: string, proxy?: string} $meta
     */
    private function disableServiceAddon(string $name, array $meta): void
    {
        try {
            ServiceRegistryFacade::stop([$name]);
        } catch (\Throwable $e) {
            // keep going
        }

        $proxyName = $meta['proxy'] ?? $name;
        $url = $proxyName . '.' . $this->domain();
        try {
            SiteSecureFacade::unsecure($url);
        } catch (\Throwable $e) {
            // ignore
        }

        ServiceRegistryFacade::removeService($name);
        NginxFacade::restart();
    }

    private function enableBuiltin(string $name): void
    {
        if ($name === 'mailpit') {
            try {
                \Valet\Facades\Mailpit::start();
            } catch (\Throwable $e) {
                Writer::warn($e->getMessage());
            }
            Writer::info('Mailpit is a built-in Valet service (also `valet mail`).');
        }

        if ($name === 'adminer') {
            \Valet\Facades\Adminer::install();
            Writer::info('Adminer is built-in (also `valet database`).');
        }
    }

    private function disableBuiltin(string $name): void
    {
        if ($name === 'mailpit') {
            Writer::warn('Mailpit is a built-in service; use `valet stop mailpit` instead of disabling the addon.');

            return;
        }

        if ($name === 'adminer') {
            Writer::warn('Adminer is built-in like Mailpit. Use `valet database` for paths; unlink is not recommended.');
            Writer::info(sprintf('Plugins live at: %s', \Valet\Facades\Adminer::pluginsPath()));
        }
    }

    private function tryManualInstall(string $name, string $package): void
    {
        if ($name === 'meilisearch') {
            $this->installMeilisearchManually();
        } else {
            Writer::warn(sprintf('No manual installer for [%s]; install [%s] manually.', $name, $package));
        }
    }

    private function ensureServiceFile(string $name, array $definition): void
    {
        if ($name === 'meilisearch') {
            $this->ensureMeilisearchServiceFile($definition);
        }
    }

    private function installMeilisearchManually(): void
    {
        $binary = '/usr/local/bin/meilisearch';
        if ($this->files->exists($binary) || trim($this->cli->run("command -v meilisearch 2>/dev/null || echo ''")) !== '') {
            Writer::info('Meilisearch binary already present; skipping download.');

            return;
        }

        Writer::info('Downloading Meilisearch binary…');
        $tmp = '/tmp/meilisearch';
        $url = 'https://github.com/meilisearch/meilisearch/releases/latest/download/meilisearch-linux-amd64';

        $hasCurl = trim($this->cli->run('command -v curl 2>/dev/null || echo ""')) !== '';
        $hasWget = trim($this->cli->run('command -v wget 2>/dev/null || echo ""')) !== '';

        try {
            if ($hasCurl) {
                $this->cli->run(sprintf('curl -fL %s -o %s', escapeshellarg($url), escapeshellarg($tmp)), function ($c, $o) {
                    throw new \RuntimeException($o);
                });
            } elseif ($hasWget) {
                $this->cli->run(sprintf('wget -O %s %s', escapeshellarg($tmp), escapeshellarg($url)), function ($c, $o) {
                    throw new \RuntimeException($o);
                });
            } else {
                Writer::warn('Neither curl nor wget found; cannot download Meilisearch.');

                return;
            }
            $this->cli->run(sprintf('sudo install -m 0755 %s %s', escapeshellarg($tmp), escapeshellarg($binary)));
            Writer::info('Meilisearch binary installed to '.$binary);
        } catch (\Throwable $e) {
            Writer::warn('Meilisearch download failed: '.$e->getMessage());
        }
    }

    private function ensureMeilisearchServiceFile(array $definition): void
    {
        $servicePath = '/etc/systemd/system/meilisearch.service';
        if ($this->files->exists($servicePath) || trim($this->cli->run("test -f {$servicePath} && echo yes || echo no")) === 'yes') {
            return;
        }

        Writer::info('Creating systemd service for Meilisearch…');
        $port = $definition['port'] ?? 7700;
        $serviceContent = <<<UNIT
[Unit]
Description=Meilisearch
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
ExecStart=/usr/local/bin/meilisearch --http-addr 127.0.0.1:{$port}
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT;
        try {
            $this->cli->run('sudo useradd -r -s /bin/false meilisearch 2>/dev/null || true');
            $this->cli->run(sprintf('printf %%s %s | sudo tee %s >/dev/null', escapeshellarg($serviceContent), escapeshellarg($servicePath)));
            $this->cli->run('sudo systemctl daemon-reload');
            $created = trim($this->cli->run("test -f {$servicePath} && echo yes || echo no")) === 'yes' || $this->files->exists($servicePath);
            if ($created) {
                Writer::info('Meilisearch systemd service created at '.$servicePath);
            } else {
                Writer::warn('Could not create Meilisearch service file (sudo may have failed).');
                Writer::info('Run manually: echo \''.str_replace("'", "'\\''", $serviceContent).'\' | sudo tee '.$servicePath.' >/dev/null && sudo systemctl daemon-reload');
            }
        } catch (\Throwable $e) {
            Writer::warn('Could not create Meilisearch service: '.$e->getMessage());
        }
    }

    private function domain(): string
    {
        $domainRaw = $this->config->get('domain', 'test');

        return is_scalar($domainRaw) ? (string) $domainRaw : 'test';
    }

    /** @deprecated Use Adminer::path() */
    public function adminerPath(): string
    {
        return \Valet\Facades\Adminer::path();
    }

    public function adminerUrl(): string
    {
        return \Valet\Facades\Adminer::url();
    }
}
