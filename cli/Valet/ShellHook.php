<?php

namespace Valet;

use InvalidArgumentException;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\Site as SiteFacade;
use Valet\Facades\SiteIsolate as SiteIsolateFacade;

class ShellHook
{
    public const CACHE_FILE = 'shell-hook.cache.json';

    public function __construct(
        public Filesystem $files,
        public Configuration $config,
        public CommandLine $cli
    ) {
    }

    /**
     * Absolute path to the shell-hook cache file.
     */
    public function cachePath(): string
    {
        return VALET_HOME_PATH . '/' . self::CACHE_FILE;
    }

    /**
     * Invalidate the path → PHP binary cache (call after isolate/use).
     */
    public function invalidateCache(): void
    {
        $path = $this->cachePath();
        if ($this->files->exists($path)) {
            $this->files->unlink($path);
        }
    }

    /**
     * Resolve the PHP binary for a filesystem path, using a warm cache when possible.
     */
    public function phpBinaryForPath(string $path): ?string
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }

        $cache = $this->readCache();
        $stamp = $this->cacheStamp();
        $paths = is_array($cache['paths'] ?? null) ? $cache['paths'] : [];
        if (($cache['stamp'] ?? null) === $stamp && array_key_exists($path, $paths)) {
            $cached = (string) $paths[$path];

            return $cached !== '' ? $cached : null;
        }

        $binary = $this->resolvePhpBinary($path);
        $cache['stamp'] = $stamp;
        $cache['paths'] = $paths;
        $cache['paths'][$path] = $binary ?? '';
        $this->writeCache($cache);

        return $binary;
    }

    /**
     * Emit shell-specific hook script to stdout.
     */
    public function emit(?string $shell = null): string
    {
        $shell = $this->detectShell($shell);
        $valet = $this->valetBinary();

        return match ($shell) {
            'bash' => $this->bashScript($valet),
            'fish' => $this->fishScript($valet),
            default => $this->zshScript($valet),
        };
    }

    public function run(?string $shell = null): void
    {
        echo $this->emit($shell);
    }

    /**
     * Print absolute PHP binary for CWD (or empty if none).
     */
    public function printPhpBin(): void
    {
        $bin = $this->phpBinaryForPath(rtrim((string) getcwd(), '/'));
        if ($bin !== null) {
            echo $bin;
        }
    }

    private function resolvePhpBinary(string $path): ?string
    {
        $siteName = basename($path);
        $tld = $this->config->get('domain', 'test');
        $tld = is_scalar($tld) ? (string) $tld : 'test';
        $host = $siteName . '.' . $tld;

        $inValet = false;
        try {
            $sitePath = SiteFacade::path($siteName);
            if (is_string($sitePath) && $sitePath !== '' && rtrim($sitePath, '/') === $path) {
                $inValet = true;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        if (!$inValet) {
            // Parked path: CWD basename under a parked directory.
            $paths = $this->config->get('paths', []);
            if (is_array($paths)) {
                foreach ($paths as $parked) {
                    if (!is_string($parked) || $parked === '') {
                        continue;
                    }
                    if (str_starts_with($path . '/', rtrim($parked, '/') . '/')) {
                        $inValet = true;
                        break;
                    }
                    if (rtrim($parked, '/') === $path) {
                        $inValet = true;
                        break;
                    }
                }
            }
        }

        if (!$inValet) {
            return null;
        }

        $version = null;
        try {
            $version = SiteIsolateFacade::isolatedPhpVersion($host);
        } catch (\Throwable $e) {
            $version = null;
        }

        $php = PhpFpmFacade::getPhpExecutablePath(is_string($version) ? $version : null);

        return is_string($php) && $php !== '' ? $php : null;
    }

    /**
     * @return array{stamp?: string, paths?: array<string, string>}
     */
    private function readCache(): array
    {
        $path = $this->cachePath();
        if (!$this->files->exists($path)) {
            return ['stamp' => '', 'paths' => []];
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : ['stamp' => '', 'paths' => []];
    }

    /**
     * @param array{stamp?: string, paths?: array<string, string>} $cache
     */
    private function writeCache(array $cache): void
    {
        $this->files->ensureDirExists(VALET_HOME_PATH, user());
        $this->files->putAsUser(
            $this->cachePath(),
            (string) json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function cacheStamp(): string
    {
        $phpRaw = $this->config->get('php_version', '');
        $php = is_scalar($phpRaw) ? (string) $phpRaw : '';
        $bins = $this->config->get('php_bin', []);
        $binsHash = is_array($bins) ? md5((string) json_encode($bins)) : '';

        return $php . ':' . $binsHash;
    }

    private function detectShell(?string $shell): string
    {
        if ($shell !== null && $shell !== '') {
            $shell = strtolower($shell);
            if (!in_array($shell, ['zsh', 'bash', 'fish'], true)) {
                throw new InvalidArgumentException('Unsupported shell. Use zsh, bash, or fish.');
            }

            return $shell;
        }

        $env = getenv('SHELL') ?: '';
        if (str_contains($env, 'fish')) {
            return 'fish';
        }
        if (str_contains($env, 'bash')) {
            return 'bash';
        }

        return 'zsh';
    }

    private function valetBinary(): string
    {
        $which = trim($this->cli->run('command -v valet 2>/dev/null || true'));
        if ($which !== '') {
            return $which;
        }

        return VALET_ROOT_PATH . '/valet';
    }

    private function zshScript(string $valet): string
    {
        $valet = escapeshellarg($valet);

        return <<<ZSH
# Valet Linux+ shell hook — eval "\$(valet shell-hook)"
_valet_prev_path="\$PATH"
_valet_prev_php="\${VALET_PHP-}"
_valet_active=0

_valet_hook() {
  local bin site_php
  bin=\$({$valet} env --php-bin 2>/dev/null)
  if [ -n "\$bin" ] && [ -x "\$bin" ]; then
    site_php=\$(dirname "\$bin")
    if [ "\$_valet_active" -eq 0 ]; then
      _valet_prev_path="\$PATH"
      _valet_prev_php="\${VALET_PHP-}"
    fi
    export VALET_PHP="\$bin"
    export VALET_SITE="\$(basename "\$PWD")"
    export PATH="\$site_php:\$_valet_prev_path"
    _valet_active=1
  elif [ "\$_valet_active" -eq 1 ]; then
    export PATH="\$_valet_prev_path"
    if [ -n "\$_valet_prev_php" ]; then
      export VALET_PHP="\$_valet_prev_php"
    else
      unset VALET_PHP
    fi
    unset VALET_SITE
    _valet_active=0
  fi
}

autoload -Uz add-zsh-hook 2>/dev/null || true
add-zsh-hook chpwd _valet_hook 2>/dev/null || true
_valet_hook
ZSH;
    }

    private function bashScript(string $valet): string
    {
        $valet = escapeshellarg($valet);

        return <<<BASH
# Valet Linux+ shell hook — eval "\$(valet shell-hook --shell=bash)"
_valet_prev_path="\$PATH"
_valet_prev_php="\${VALET_PHP-}"
_valet_active=0

_valet_hook() {
  local bin site_php
  bin=\$({$valet} env --php-bin 2>/dev/null)
  if [ -n "\$bin" ] && [ -x "\$bin" ]; then
    site_php=\$(dirname "\$bin")
    if [ "\$_valet_active" -eq 0 ]; then
      _valet_prev_path="\$PATH"
      _valet_prev_php="\${VALET_PHP-}"
    fi
    export VALET_PHP="\$bin"
    export VALET_SITE="\$(basename "\$PWD")"
    export PATH="\$site_php:\$_valet_prev_path"
    _valet_active=1
  elif [ "\$_valet_active" -eq 1 ]; then
    export PATH="\$_valet_prev_path"
    if [ -n "\$_valet_prev_php" ]; then
      export VALET_PHP="\$_valet_prev_php"
    else
      unset VALET_PHP
    fi
    unset VALET_SITE
    _valet_active=0
  fi
}

if [[ ";\${PROMPT_COMMAND:-};" != *";_valet_hook;"* ]]; then
  PROMPT_COMMAND="_valet_hook\${PROMPT_COMMAND:+; \$PROMPT_COMMAND}"
fi
_valet_hook
BASH;
    }

    private function fishScript(string $valet): string
    {
        $valet = escapeshellarg($valet);

        return <<<FISH
# Valet Linux+ shell hook — valet shell-hook --shell=fish | source
set -g _valet_prev_path \$PATH
set -g _valet_active 0

function _valet_hook --on-variable PWD
  set -l bin ({$valet} env --php-bin 2>/dev/null)
  if test -n "\$bin"; and test -x "\$bin"
    set -l site_php (dirname "\$bin")
    if test \$_valet_active -eq 0
      set -g _valet_prev_path \$PATH
    end
    set -gx VALET_PHP "\$bin"
    set -gx VALET_SITE (basename \$PWD)
    set -gx PATH \$site_php \$_valet_prev_path
    set -g _valet_active 1
  else if test \$_valet_active -eq 1
    set -gx PATH \$_valet_prev_path
    set -e VALET_PHP
    set -e VALET_SITE
    set -g _valet_active 0
  end
end

_valet_hook
FISH;
    }
}
