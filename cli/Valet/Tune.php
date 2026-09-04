<?php

namespace Valet;

use ConsoleComponents\Writer;
use InvalidArgumentException;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\Site as SiteFacade;
use Valet\Facades\SiteIsolate as SiteIsolateFacade;

class Tune
{
    public const PRESETS = ['dev', 'fast', 'debug', 'show'];

    public const DROPIN_NAME = '99-valet-tune.ini';

    public function __construct(
        public Filesystem $files,
        public Configuration $config,
        public CommandLine $cli
    ) {
    }

    /**
     * Apply or show a PHP performance preset.
     *
     * @return array<string, mixed>
     */
    public function run(
        ?string $preset = null,
        ?string $version = null,
        ?string $site = null,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $preset = $preset !== null && $preset !== '' ? strtolower($preset) : 'show';
        if (!in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException(
                'Unknown preset. Use: ' . implode(', ', self::PRESETS)
            );
        }

        $version = $this->resolveVersion($version, $site);

        if ($preset === 'show') {
            return $this->show($version);
        }

        return $this->apply($preset, $version, $dryRun, $force);
    }

    /**
     * Load stub contents for a preset.
     */
    public function stubContents(string $preset): string
    {
        $path = VALET_ROOT_PATH . '/cli/stubs/tune/' . $preset . '.ini';
        if (!$this->files->exists($path)) {
            throw new InvalidArgumentException(sprintf('Missing tune stub [%s]', $path));
        }

        return $this->files->get($path);
    }

    /**
     * Target drop-in path for a PHP version (first existing conf.d).
     */
    public function dropInPath(string $version): string
    {
        $version = PhpFpmFacade::normalizePhpVersion($version);
        $candidates = [
            "/etc/php/{$version}/fpm/conf.d/" . self::DROPIN_NAME,
            "/etc/php{$version}/fpm/conf.d/" . self::DROPIN_NAME,
        ];

        foreach ($candidates as $candidate) {
            $dir = dirname($candidate);
            if ($this->files->isDir($dir)) {
                return $candidate;
            }
        }

        // Prefer Ubuntu layout even if missing (install will fail clearly).
        return $candidates[0];
    }

    /**
     * @return array{preset: string, version: string, path: string, contents: string, managed: bool}
     */
    public function show(string $version): array
    {
        $path = $this->dropInPath($version);
        $contents = $this->files->exists($path) ? $this->files->get($path) : '';
        $managed = str_contains($contents, 'Valet Linux+ tune preset');
        $preset = 'none';
        if (preg_match('/tune preset:\s*(\w+)/', $contents, $m)) {
            $preset = $m[1];
        }

        $stored = $this->config->get('tune', []);
        if (is_array($stored) && isset($stored[$version]) && is_string($stored[$version])) {
            $preset = $stored[$version];
        }

        Writer::info(sprintf('PHP %s tune preset: %s', $version, $preset));
        Writer::info(sprintf('Drop-in: %s', $path));
        if ($contents !== '') {
            Writer::info($contents);
        } else {
            Writer::warn('No Valet tune drop-in present.');
        }

        return [
            'preset' => $preset,
            'version' => $version,
            'path' => $path,
            'contents' => $contents,
            'managed' => $managed,
        ];
    }

    /**
     * @return array{preset: string, version: string, path: string, dry_run: bool}
     */
    public function apply(string $preset, string $version, bool $dryRun = false, bool $force = false): array
    {
        $contents = $this->stubContents($preset);
        $path = $this->dropInPath($version);
        $existing = $this->files->exists($path) ? $this->files->get($path) : '';

        if ($existing !== '' && !str_contains($existing, 'Valet Linux+ tune preset') && !$force) {
            throw new InvalidArgumentException(sprintf(
                'Refusing to overwrite non-Valet drop-in [%s]. Pass --force to replace.',
                $path
            ));
        }

        if ($dryRun) {
            Writer::info(sprintf('Dry-run: would write preset [%s] for PHP %s to %s', $preset, $version, $path));
            Writer::info($contents);

            return [
                'preset' => $preset,
                'version' => $version,
                'path' => $path,
                'dry_run' => true,
            ];
        }

        $this->backup($version, $existing);
        $this->writeDropIn($path, $contents);
        $this->storePreset($version, $preset);
        PhpFpmFacade::restart($version);

        Writer::info(sprintf('Applied tune preset [%s] for PHP %s', $preset, $version));
        Writer::info(sprintf('Wrote %s', $path));

        return [
            'preset' => $preset,
            'version' => $version,
            'path' => $path,
            'dry_run' => false,
        ];
    }

    private function resolveVersion(?string $version, ?string $site): string
    {
        if ($version !== null && $version !== '') {
            return PhpFpmFacade::normalizePhpVersion($version);
        }

        if ($site !== null && $site !== '') {
            $tld = $this->config->get('domain', 'test');
            $tld = is_scalar($tld) ? (string) $tld : 'test';
            $host = str_contains($site, '.') ? $site : $site . '.' . $tld;
            $isolated = SiteIsolateFacade::isolatedPhpVersion($host);
            if (is_string($isolated) && $isolated !== '') {
                return PhpFpmFacade::normalizePhpVersion($isolated);
            }

            // Fall back via site path / .valetphprc
            $siteName = basename(str_replace('.' . $tld, '', $site));
            $rc = SiteFacade::phpRcVersion($siteName);
            if (is_string($rc) && $rc !== '') {
                return $rc;
            }
        }

        return PhpFpmFacade::getCurrentVersion();
    }

    private function backup(string $version, string $existing): void
    {
        if ($existing === '') {
            return;
        }

        $dir = VALET_HOME_PATH . '/Backups/tune';
        $this->files->ensureDirExists($dir, user());
        $name = sprintf('%s-%s.ini', $version, date('YmdHis'));
        $this->files->putAsUser($dir . '/' . $name, $existing);
    }

    private function writeDropIn(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!$this->files->isDir($dir)) {
            throw new InvalidArgumentException(sprintf(
                'PHP-FPM conf.d directory not found [%s]. Is PHP-FPM installed?',
                $dir
            ));
        }

        $tmp = VALET_HOME_PATH . '/tune-tmp-' . uniqid('', true) . '.ini';
        $this->files->ensureDirExists(VALET_HOME_PATH, user());
        $this->files->putAsUser($tmp, $contents);
        $this->cli->run(sprintf(
            'sudo cp %s %s && sudo chmod 644 %s',
            escapeshellarg($tmp),
            escapeshellarg($path),
            escapeshellarg($path)
        ));
        if ($this->files->exists($tmp)) {
            $this->files->unlink($tmp);
        }
    }

    private function storePreset(string $version, string $preset): void
    {
        $tune = $this->config->get('tune', []);
        if (!is_array($tune)) {
            $tune = [];
        }
        $tune[$version] = $preset;
        $this->config->set('tune', $tune);
    }
}
