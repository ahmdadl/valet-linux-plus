<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\SiteIsolate;
use Valet\Facades\SiteLink;
use Valet\Facades\SiteProxy;
use Valet\Facades\SiteSecure;

class Diagnose
{
    public CommandLine $cli;
    public Filesystem $files;
    public Configuration $config;

    /**
     * Create a new Diagnose instance.
     */
    public function __construct(CommandLine $cli, Filesystem $files, Configuration $config)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
    }

    /**
     * Gather diagnostic information about the current Valet installation.
     *
     * Every gather step is wrapped in a try/catch so the command never throws
     * and never performs any destructive (sudo/start/stop) action.
     *
     * @return array<string, mixed>
     */
    public function gather(): array
    {
        return [
            'os' => $this->gatherOs(),
            'package_manager' => $this->gatherPackageManager(),
            'service_manager' => $this->gatherServiceManager(),
            'php' => $this->gatherPhp(),
            'nginx' => $this->gatherNginx(),
            'dns' => $this->gatherDns(),
            'services' => $this->gatherServices(),
            'paths' => $this->gatherPaths(),
            'valet_version' => $this->gatherValetVersion(),
        ];
    }

    /**
     * Run the diagnostic and output the result.
     */
    public function run(bool $json = false): void
    {
        $data = $this->gather();

        if ($json) {
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            Writer::info(is_string($encoded) ? $encoded : '');

            return;
        }

        Writer::info('Valet Diagnose');
        Writer::info('');

        $this->renderSection('OS', (array) $data['os']);
        $this->renderSection('Package & Service Managers', [
            'Package Manager' => $data['package_manager'],
            'Service Manager' => $data['service_manager'],
        ]);
        $this->renderSection('PHP', (array) $data['php']);
        $this->renderSection('Nginx', (array) $data['nginx']);
        $this->renderSection('DNS', (array) $data['dns']);
        $this->renderSection('Services', (array) $data['services']);
        $this->renderSection('Paths', (array) $data['paths']);
        $this->renderSection('Valet', [
            'Version' => $data['valet_version'],
        ]);
    }

    /**
     * Render a single diagnostic section as a table.
     *
     * @param array<int|string, mixed> $rows
     */
    private function renderSection(string $title, array $rows): void
    {
        Writer::info($title);

        $tableRows = [];
        foreach ($rows as $check => $value) {
            $tableRows[] = [
                'Check' => $check,
                'Value' => $this->stringify($value),
            ];
        }

        Writer::table(['Check', 'Value'], $tableRows);
        Writer::info('');
    }

    /**
     * Convert any scalar/array value into a printable string.
     * @param mixed $value
     */
    private function stringify($value): string
    {
        if (is_array($value)) {
            return implode(', ', $value);
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function gatherOs(): array
    {
        $os = 'unknown';
        try {
            $output = trim($this->cli->run('cat /etc/os-release 2>/dev/null'));
            if ($output !== '') {
                $os = $output;
            } else {
                $os = trim($this->cli->run('uname -a 2>/dev/null')) ?: 'unknown';
            }
        } catch (\Throwable $e) {
            $os = 'unknown';
        }

        $kernel = 'unknown';
        try {
            $kernel = trim($this->cli->run('uname -r 2>/dev/null')) ?: 'unknown';
        } catch (\Throwable $e) {
            $kernel = 'unknown';
        }

        return [
            'OS' => $os,
            'Kernel' => $kernel,
        ];
    }

    private function gatherPackageManager(): string
    {
        try {
            $manager = resolve(PackageManager::class);
            if (! is_object($manager)) {
                return 'unknown';
            }

            return get_class($manager);
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    private function gatherServiceManager(): string
    {
        try {
            $manager = resolve(ServiceManager::class);
            if (! is_object($manager)) {
                return 'unknown';
            }

            return get_class($manager);
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherPhp(): array
    {
        $configured = 'unknown';
        try {
            $configured = $this->config->get('php_version') ?: PHP_VERSION;
        } catch (\Throwable $e) {
            $configured = PHP_VERSION;
        }

        $supported = PhpFpm::SUPPORTED_PHP_VERSIONS;

        $isolatedCount = 0;
        try {
            $isolatedCount = SiteIsolate::isolatedDirectories()->count();
        } catch (\Throwable $e) {
            $isolatedCount = 0;
        }

        return [
            'PHP Version' => PHP_VERSION,
            'Configured PHP' => $configured,
            'Supported PHP' => $supported,
            'Isolated Sites' => $isolatedCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherNginx(): array
    {
        $configCount = 0;
        try {
            $configCount = count($this->files->scandir(VALET_HOME_PATH . '/Nginx'));
        } catch (\Throwable $e) {
            $configCount = 0;
        }

        $nginxTest = 'unknown';
        try {
            $nginxTest = trim($this->cli->run('nginx -t 2>&1')) ?: 'unknown';
        } catch (\Throwable $e) {
            $nginxTest = 'unknown';
        }

        return [
            'Config Count' => $configCount,
            'nginx -t' => $nginxTest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherDns(): array
    {
        $domain = 'unknown';
        try {
            $domain = $this->config->get('domain') ?: 'unknown';
        } catch (\Throwable $e) {
            $domain = 'unknown';
        }

        $dnsmasq = 'unknown';
        try {
            $dnsmasq = trim($this->cli->run('systemctl is-active dnsmasq 2>/dev/null')) ?: 'unknown';
        } catch (\Throwable $e) {
            $dnsmasq = 'unknown';
        }

        return [
            'Domain' => $domain,
            'DnsMasq' => $dnsmasq,
        ];
    }

    /**
     * Non-destructive service status check. We never start/stop anything,
     * only query the current active state.
     *
     * @return array<string, string>
     */
    private function gatherServices(): array
    {
        $services = ['nginx', 'php', 'mailpit', 'dnsmasq', 'mysql', 'redis'];
        $statuses = [];

        foreach ($services as $service) {
            try {
                $status = trim($this->cli->run("systemctl is-active {$service} 2>/dev/null")) ?: 'unknown';
                $statuses[$service] = $status;
            } catch (\Throwable $e) {
                $statuses[$service] = 'unknown';
            }
        }

        return $statuses;
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherPaths(): array
    {
        $paths = [];
        try {
            $paths = $this->config->get('paths', []);
        } catch (\Throwable $e) {
            $paths = [];
        }

        $links = 0;
        try {
            $links = SiteLink::links()->count();
        } catch (\Throwable $e) {
            $links = 0;
        }

        $proxies = 0;
        try {
            $proxies = SiteProxy::proxies()->count();
        } catch (\Throwable $e) {
            $proxies = 0;
        }

        $secured = 0;
        try {
            $secured = SiteSecure::secured()->count();
        } catch (\Throwable $e) {
            $secured = 0;
        }

        return [
            'Configured Paths' => count(is_array($paths) ? $paths : []),
            'Links' => $links,
            'Proxies' => $proxies,
            'Secured' => $secured,
        ];
    }

    private function gatherValetVersion(): string
    {
        try {
            $composer = $this->files->get(VALET_ROOT_PATH . '/composer.json');
            $decoded = json_decode($composer, true);
            if (is_array($decoded) && isset($decoded['version'])) {
                return (string) $decoded['version'];
            }
        } catch (\Throwable $e) {
            // fall through to hardcoded fallback
        }

        return 'unknown';
    }
}
