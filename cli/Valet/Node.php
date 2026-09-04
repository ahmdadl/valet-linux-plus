<?php

namespace Valet;

use ConsoleComponents\Writer;
use InvalidArgumentException;
use RuntimeException;

class Node
{
    public function __construct(
        public Filesystem $files,
        public CommandLine $cli
    ) {
    }

    /**
     * Resolve the Node version requested by the project (`.nvmrc` / `.node-version`).
     */
    public function detectProjectVersion(?string $path = null): ?string
    {
        $path = $path !== null ? rtrim($path, '/') : rtrim((string) getcwd(), '/');

        foreach (['.nvmrc', '.node-version'] as $file) {
            $candidate = $path . '/' . $file;
            if (!$this->files->exists($candidate)) {
                continue;
            }

            $raw = trim($this->files->get($candidate));
            if ($raw === '') {
                continue;
            }

            // First non-comment line.
            foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                return ltrim($line, 'vV');
            }
        }

        return null;
    }

    public function nvmDir(): ?string
    {
        $env = getenv('NVM_DIR');
        if (is_string($env) && $env !== '' && $this->files->isDir($env)) {
            return $env;
        }

        $home = $_SERVER['HOME'] ?? (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? null) : null);
        if (!is_string($home) || $home === '') {
            return null;
        }

        $candidate = $home . '/.nvm';
        if ($this->files->isDir($candidate) && $this->files->exists($candidate . '/nvm.sh')) {
            return $candidate;
        }

        return null;
    }

    public function nvmAvailable(): bool
    {
        return $this->nvmDir() !== null;
    }

    /**
     * Currently active Node version (from PATH), or null if missing.
     */
    public function current(): ?string
    {
        $output = trim($this->cli->run('command -v node >/dev/null 2>&1 && node -v 2>/dev/null || true'));
        if ($output === '' || !preg_match('/v?(\d+(?:\.\d+)*)/', $output, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Install a Node version via nvm (does nothing without nvm).
     */
    public function install(?string $version = null): string
    {
        $version = $this->resolveVersion($version);
        $this->requireNvm();

        $this->runNvm(sprintf('nvm install %s', escapeshellarg($version)));

        Writer::info(sprintf('Node %s installed via nvm.', $version));

        return $version;
    }

    /**
     * Activate a Node version in the current shell environment via nvm.
     * Note: this cannot permanently change the parent interactive shell; it verifies install/use works.
     */
    public function use(?string $version = null): string
    {
        $version = $this->resolveVersion($version);
        $this->requireNvm();

        $this->runNvm(sprintf('nvm install %s >/dev/null; nvm use %s', escapeshellarg($version), escapeshellarg($version)));

        Writer::info(sprintf(
            'Node %s selected via nvm. For your shell, run: nvm use %s',
            $version,
            $version
        ));

        return $version;
    }

    /**
     * Apply a profile-requested Node version (best-effort).
     *
     * @return string Human-readable step message
     */
    public function applyVersion(string $version): string
    {
        $version = ltrim(trim($version), 'vV');
        if ($version === '') {
            throw new InvalidArgumentException('Node version cannot be empty');
        }

        if (!$this->nvmAvailable()) {
            Writer::warn(sprintf(
                'Profile requests Node %s but nvm was not found. Install nvm, then `valet node:use %s`.',
                $version,
                $version
            ));

            return sprintf('Noted Node %s (nvm missing)', $version);
        }

        try {
            $this->use($version);

            return sprintf('Applied Node %s via nvm', $version);
        } catch (\Throwable $e) {
            Writer::warn($e->getMessage());

            return sprintf('Failed to apply Node %s', $version);
        }
    }

    public function runCurrent(): void
    {
        $active = $this->current();
        $project = $this->detectProjectVersion();
        $nvm = $this->nvmAvailable();

        Writer::table(
            ['Active', 'Project (.nvmrc)', 'nvm'],
            [[
                $active ?? '—',
                $project ?? '—',
                $nvm ? 'available' : 'missing',
            ]]
        );

        if ($project && $active && version_compare($this->major($active), $this->major($project), '!=')) {
            Writer::warn(sprintf('Active Node (%s) differs from project (.nvmrc %s).', $active, $project));
        }
    }

    private function resolveVersion(?string $version): string
    {
        if (is_string($version) && trim($version) !== '') {
            return ltrim(trim($version), 'vV');
        }

        $fromProject = $this->detectProjectVersion();
        if ($fromProject !== null) {
            return $fromProject;
        }

        throw new InvalidArgumentException('No Node version given and no .nvmrc / .node-version found.');
    }

    private function requireNvm(): void
    {
        if (!$this->nvmAvailable()) {
            throw new RuntimeException(
                'nvm not found. Install https://github.com/nvm-sh/nvm or set NVM_DIR. Valet will not install Node without nvm.'
            );
        }
    }

    private function runNvm(string $inner): void
    {
        $nvmDir = $this->nvmDir();
        if ($nvmDir === null) {
            throw new RuntimeException('nvm not found');
        }

        $script = sprintf(
            'export NVM_DIR=%s; [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"; %s',
            escapeshellarg($nvmDir),
            $inner
        );

        $this->cli->run(
            'bash -lc ' . escapeshellarg($script),
            function ($code, $output) {
                throw new RuntimeException(trim((string) $output) ?: ('nvm command failed with exit ' . $code));
            }
        );
    }

    private function major(string $version): string
    {
        $parts = explode('.', ltrim($version, 'vV'));

        return $parts[0] !== '' ? $parts[0] : $version;
    }
}
