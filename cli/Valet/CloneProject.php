<?php

namespace Valet;

use ConsoleComponents\Writer;
use RuntimeException;
use Valet\Facades\Init as InitFacade;
use Valet\Facades\Profile as ProfileFacade;
use Valet\Facades\SiteLink as SiteLinkFacade;

class CloneProject
{
    public function __construct(
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $config
    ) {
    }

    /**
     * Clone a repository and optionally bootstrap with Valet.
     *
     * @return array<int, string>
     */
    public function run(
        string $repository,
        ?string $directory = null,
        ?string $branch = null,
        bool $link = false,
        bool $init = false,
        bool $db = false,
        bool $migrate = false,
        bool $secure = false,
        ?string $isolate = null,
        bool $open = false,
        bool $ssh = false,
        bool $https = false,
        bool $force = false
    ): array {
        $this->assertGitAvailable();

        $url = $this->resolveRepositoryUrl($repository, $ssh, $https);
        $target = $this->resolveTargetDirectory($url, $directory);
        $steps = [];

        if ($this->files->exists($target) || $this->files->isDir($target)) {
            $isEmpty = $this->isEmptyDirectory($target);
            if (!$isEmpty && !$force) {
                throw new RuntimeException(sprintf(
                    'Target directory [%s] exists and is not empty. Use --force to proceed.',
                    $target
                ));
            }
            if (!$isEmpty) {
                // --force: remove non-empty target so git clone can proceed.
                $this->cli->run(sprintf('rm -rf %s', escapeshellarg($target)));
                $steps[] = sprintf('Removed existing directory [%s]', $target);
            }
        }

        $cloneCmd = sprintf('git clone %s %s', escapeshellarg($url), escapeshellarg($target));
        if ($branch !== null && $branch !== '') {
            $cloneCmd = sprintf(
                'git clone --branch %s %s %s',
                escapeshellarg($branch),
                escapeshellarg($url),
                escapeshellarg($target)
            );
        }

        $failed = false;
        $this->cli->runAsUser($cloneCmd, function ($code, $output) use (&$failed) {
            $failed = true;
            Writer::error(trim((string) $output) !== '' ? trim((string) $output) : 'git clone failed');
        }, 600);

        if ($failed) {
            throw new RuntimeException('git clone failed');
        }

        $steps[] = sprintf('Cloned %s into %s', $url, $target);

        $previous = getcwd();
        chdir($target);

        try {
            $profilePath = $target . '/' . Profile::PROJECT_RELATIVE_PATH;
            $wantsInit = $init || $db || $migrate || $secure || ($isolate !== null && $isolate !== '');

            if ($this->files->exists($profilePath)) {
                ProfileFacade::runUse('project', true);
                $steps[] = 'Applied project profile';
            } elseif ($wantsInit) {
                InitFacade::run(
                    $db,
                    $migrate,
                    false,
                    $isolate,
                    $secure,
                    $force,
                    false
                );
                $steps[] = 'Ran valet init';
            } elseif ($link) {
                $name = basename($target);
                $linkPath = SiteLinkFacade::link($target, $name);
                $steps[] = sprintf('Linked [%s] at [%s]', $name, $linkPath);
            }

            // Explicit --link after profile/init when requested and not already linked above
            if ($link && ($this->files->exists($profilePath) || $wantsInit)) {
                $name = basename($target);
                $linkPath = SiteLinkFacade::link($target, $name);
                $steps[] = sprintf('Linked [%s] at [%s]', $name, $linkPath);
            }

            if ($open) {
                $tld = $this->config->get('domain', 'test');
                $tld = is_scalar($tld) ? (string) $tld : 'test';
                $urlOpen = sprintf('http://%s.%s/', basename($target), $tld);
                $this->cli->passthru('xdg-open ' . escapeshellarg($urlOpen));
                $steps[] = sprintf('Opened %s', $urlOpen);
            }
        } finally {
            if (is_string($previous) && $previous !== '') {
                chdir($previous);
            }
        }

        foreach ($steps as $step) {
            Writer::info('✓ ' . $step);
        }

        return $steps;
    }

    public function resolveRepositoryUrl(string $repository, bool $ssh = false, bool $https = false): string
    {
        $repository = trim($repository);
        if ($repository === '') {
            throw new RuntimeException('Repository URL is required');
        }

        if (preg_match('#^(https?://|git@|ssh://)#i', $repository)) {
            if ($ssh && preg_match('#^https://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#i', $repository, $m)) {
                return sprintf('git@github.com:%s/%s.git', $m[1], rtrim($m[2], '.git'));
            }
            if ($https && preg_match('#^git@github\.com:([^/]+)/([^/]+?)(?:\.git)?$#i', $repository, $m)) {
                return sprintf('https://github.com/%s/%s.git', $m[1], rtrim($m[2], '.git'));
            }

            return $repository;
        }

        // GitHub shorthand org/repo
        if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $repository, $m)) {
            if ($https && !$ssh) {
                return sprintf('https://github.com/%s/%s.git', $m[1], $m[2]);
            }

            return sprintf('git@github.com:%s/%s.git', $m[1], $m[2]);
        }

        return $repository;
    }

    public function resolveTargetDirectory(string $url, ?string $directory): string
    {
        if ($directory !== null && $directory !== '') {
            if ($directory[0] === '/') {
                return rtrim($directory, '/');
            }

            return rtrim((string) getcwd(), '/') . '/' . trim($directory, '/');
        }

        $basename = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        $basename = preg_replace('/\.git$/', '', $basename) ?: 'project';

        return rtrim((string) getcwd(), '/') . '/' . $basename;
    }

    private function assertGitAvailable(): void
    {
        $which = trim($this->cli->run('command -v git 2>/dev/null || true'));
        if ($which === '') {
            throw new RuntimeException('git is not installed. Install git (e.g. sudo apt install git) and retry.');
        }
    }

    private function isEmptyDirectory(string $path): bool
    {
        if (!$this->files->isDir($path)) {
            return false;
        }

        $items = $this->files->scandir($path);

        return $items === [];
    }
}
