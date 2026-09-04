<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\ProjectContext as ProjectContextFacade;
use Valet\Facades\SiteIsolate as SiteIsolateFacade;

class Cache
{
    public const WEBHOOK_MAX_AGE_DAYS = 7;

    public function __construct(
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $config
    ) {
    }

    /**
     * @return array{
     *   composer: array{path: string|null, bytes: int, human: string},
     *   npm: array{path: string|null, bytes: int, human: string},
     *   pnpm: array{path: string|null, bytes: int, human: string},
     *   yarn: array{path: string|null, bytes: int, human: string},
     *   valet: array{path: string, bytes: int, human: string, entries: array<int, string>}
     * }
     */
    public function status(): array
    {
        $composerPath = $this->composerCacheDir();
        $npmPath = $this->detectToolCache('npm');
        $pnpmPath = $this->detectToolCache('pnpm');
        $yarnPath = $this->detectToolCache('yarn');
        $valetEntries = $this->valetTempPaths();
        $valetBytes = 0;
        foreach ($valetEntries as $entry) {
            $valetBytes += $this->directorySize($entry);
        }

        return [
            'composer' => $this->entry($composerPath),
            'npm' => $this->entry($npmPath),
            'pnpm' => $this->entry($pnpmPath),
            'yarn' => $this->entry($yarnPath),
            'valet' => [
                'path' => VALET_HOME_PATH,
                'bytes' => $valetBytes,
                'human' => $this->humanBytes($valetBytes),
                'entries' => $valetEntries,
            ],
        ];
    }

    public function runStatus(bool $json = false): void
    {
        $status = $this->status();

        if ($json) {
            Writer::info((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $rows = [
            ['composer', $status['composer']['path'] ?? '(not found)', $status['composer']['human']],
            ['npm', $status['npm']['path'] ?? '(not found)', $status['npm']['human']],
            ['pnpm', $status['pnpm']['path'] ?? '(not found)', $status['pnpm']['human']],
            ['yarn', $status['yarn']['path'] ?? '(not found)', $status['yarn']['human']],
            ['valet temps', implode(', ', $status['valet']['entries']) ?: '(none)', $status['valet']['human']],
        ];

        Writer::table(['Cache', 'Path', 'Size'], $rows);
    }

    /**
     * @return array<string, string|null>
     */
    public function paths(): array
    {
        return [
            'composer' => $this->composerCacheDir(),
            'npm' => $this->detectToolCache('npm'),
            'pnpm' => $this->detectToolCache('pnpm'),
            'yarn' => $this->detectToolCache('yarn'),
            'valet_shell_hook' => VALET_HOME_PATH . '/shell-hook.cache.json',
            'valet_tune_backups' => VALET_HOME_PATH . '/Backups/tune',
            'valet_webhooks' => VALET_HOME_PATH . '/webhooks',
        ];
    }

    public function runPath(): void
    {
        foreach ($this->paths() as $name => $path) {
            Writer::info(sprintf('%s: %s', $name, $path ?? '(not found)'));
        }
    }

    /**
     * Clear caches. With no explicit flags, only Valet temps are cleared.
     *
     * @return array<int, string>
     */
    public function clear(bool $composer = false, bool $npm = false, bool $valet = false, bool $yes = false): array
    {
        $clearValet = $valet || (!$composer && !$npm);
        $steps = [];

        if ($composer) {
            if (!$yes && !Writer::confirm('Clear the global Composer cache?')) {
                Writer::warn('Skipped Composer cache');
            } else {
                $path = $this->composerCacheDir();
                if ($path !== null && $this->files->isDir($path)) {
                    $this->cli->run(sprintf('composer clear-cache 2>/dev/null || rm -rf %s/*', escapeshellarg($path)));
                    $steps[] = sprintf('Cleared Composer cache [%s]', $path);
                } else {
                    $steps[] = 'Composer cache path not found';
                }
            }
        }

        if ($npm) {
            if (!$yes && !Writer::confirm('Clear npm/pnpm/yarn caches?')) {
                Writer::warn('Skipped npm family caches');
            } else {
                foreach (['npm', 'pnpm', 'yarn'] as $tool) {
                    $path = $this->detectToolCache($tool);
                    if ($path === null || !$this->files->isDir($path)) {
                        continue;
                    }
                    $this->cli->run(sprintf('rm -rf %s/*', escapeshellarg($path)));
                    $steps[] = sprintf('Cleared %s cache [%s]', $tool, $path);
                }
            }
        }

        if ($clearValet) {
            $steps = array_merge($steps, $this->clearValetTemps());
        }

        if ($steps === []) {
            Writer::warn('Nothing to clear.');
        } else {
            foreach ($steps as $step) {
                Writer::info('✓ ' . $step);
            }
        }

        return $steps;
    }

    public function runClear(bool $composer = false, bool $npm = false, bool $valet = false, bool $yes = false): void
    {
        $this->clear($composer, $npm, $valet, $yes);
    }

    /**
     * @return array<int, string>
     */
    public function doctor(): array
    {
        $tips = [];
        $status = $this->status();

        if ($status['composer']['path'] === null) {
            $tips[] = 'Composer cache directory not detected; ensure composer is on PATH.';
        } elseif ($status['composer']['bytes'] > 5 * 1024 * 1024 * 1024) {
            $tips[] = 'Composer cache is large (>5GB); consider `valet cache:clear --composer`.';
        }

        $free = @disk_free_space(VALET_HOME_PATH);
        if ($free !== false && $free < 2 * 1024 * 1024 * 1024) {
            $tips[] = sprintf('Low disk space on Valet home (%s free).', $this->humanBytes((int) $free));
        }

        $tips[] = 'For slow installs, try: export COMPOSER_PROCESS_TIMEOUT=600';
        $tips[] = 'Default `valet cache:clear` only removes Valet temps; pass --composer / --npm explicitly.';

        if (!$this->files->isDir(VALET_HOME_PATH . '/Backups')) {
            $tips[] = 'Valet Backups directory missing; will be created when tune/backups run.';
        }

        return $tips;
    }

    public function runDoctor(): void
    {
        foreach ($this->doctor() as $tip) {
            Writer::info('• ' . $tip);
        }
    }

    public function composerCacheDir(): ?string
    {
        $php = $this->phpBinary();
        $output = trim($this->cli->run(sprintf(
            '%s $(command -v composer) config --global cache-dir 2>/dev/null || composer config --global cache-dir 2>/dev/null || true',
            escapeshellarg($php)
        )));

        if ($output !== '' && $this->files->isDir($output)) {
            return $output;
        }

        $home = $_SERVER['HOME'] ?? null;
        if (is_string($home) && $home !== '') {
            $candidates = [
                $home . '/.cache/composer/files',
                $home . '/.composer/cache',
            ];
            foreach ($candidates as $candidate) {
                if ($this->files->isDir($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function valetTempPaths(): array
    {
        $paths = [];
        $candidates = [
            VALET_HOME_PATH . '/shell-hook.cache.json',
            VALET_HOME_PATH . '/Backups/tune',
            VALET_HOME_PATH . '/webhooks',
        ];

        foreach ($candidates as $candidate) {
            if ($this->files->exists($candidate) || $this->files->isDir($candidate)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function clearValetTemps(): array
    {
        $steps = [];
        $hook = VALET_HOME_PATH . '/shell-hook.cache.json';
        if ($this->files->exists($hook)) {
            $this->files->unlink($hook);
            $steps[] = 'Removed shell-hook.cache.json';
        }

        $tune = VALET_HOME_PATH . '/Backups/tune';
        if ($this->files->isDir($tune)) {
            $this->cli->run(sprintf('rm -rf %s/*', escapeshellarg($tune)));
            $steps[] = 'Cleared tune backups';
        }

        $webhooks = VALET_HOME_PATH . '/webhooks';
        if ($this->files->isDir($webhooks)) {
            $cutoff = time() - (self::WEBHOOK_MAX_AGE_DAYS * 86400);
            $removed = 0;
            foreach ($this->files->scandir($webhooks) as $file) {
                $full = $webhooks . '/' . $file;
                if (!is_file($full)) {
                    continue;
                }
                $mtime = @filemtime($full);
                if ($mtime !== false && $mtime < $cutoff) {
                    $this->files->unlink($full);
                    $removed++;
                }
            }
            $steps[] = sprintf('Removed %d webhook bodies older than %d days', $removed, self::WEBHOOK_MAX_AGE_DAYS);
        }

        if ($steps === []) {
            $steps[] = 'No Valet temp caches present';
        }

        return $steps;
    }

    private function detectToolCache(string $tool): ?string
    {
        $commands = [
            'npm' => 'npm config get cache 2>/dev/null || true',
            'pnpm' => 'pnpm store path 2>/dev/null || true',
            'yarn' => 'yarn cache dir 2>/dev/null || true',
        ];

        if (!isset($commands[$tool])) {
            return null;
        }

        $output = trim($this->cli->run($commands[$tool]));
        if ($output === '' || str_contains(strtolower($output), 'not found')) {
            return null;
        }

        // pnpm may print "Path: /..." or just a path
        if (preg_match('#(/[^\s]+)$#', $output, $m)) {
            $output = $m[1];
        }

        return $this->files->isDir($output) ? $output : null;
    }

    /**
     * @return array{path: string|null, bytes: int, human: string}
     */
    private function entry(?string $path): array
    {
        $bytes = $path !== null ? $this->directorySize($path) : 0;

        return [
            'path' => $path,
            'bytes' => $bytes,
            'human' => $this->humanBytes($bytes),
        ];
    }

    private function directorySize(string $path): int
    {
        if ($this->files->exists($path) && !$this->files->isDir($path)) {
            $size = @filesize($path);

            return is_int($size) ? $size : 0;
        }

        if (!$this->files->isDir($path)) {
            return 0;
        }

        $output = trim($this->cli->run(sprintf('du -sb %s 2>/dev/null | cut -f1', escapeshellarg($path))));
        if ($output !== '' && ctype_digit($output)) {
            return (int) $output;
        }

        return 0;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) max(0, $bytes);
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $i === 0 ? (int) $value : $value, $units[$i]);
    }

    private function phpBinary(): string
    {
        $host = null;
        try {
            $context = ProjectContextFacade::fromCwd();
            $host = (string) $context['url'];
        } catch (\Throwable $e) {
            $host = null;
        }

        $version = null;
        if (is_string($host) && $host !== '') {
            try {
                $version = SiteIsolateFacade::isolatedPhpVersion($host);
            } catch (\Throwable $e) {
                $version = null;
            }
        }

        $php = PhpFpmFacade::getPhpExecutablePath(is_string($version) ? $version : null);

        return is_string($php) && $php !== '' ? $php : 'php';
    }
}
