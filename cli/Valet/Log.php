<?php

namespace Valet;

use ConsoleComponents\Writer;

class Log
{
    public CommandLine $cli;
    public Filesystem $files;
    public Configuration $config;

    /**
     * Create a new Log instance.
     */
    public function __construct(CommandLine $cli, Filesystem $files, Configuration $config)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
    }

    /**
     * Tail the logs for the given service.
     *
     * nginx logs are read from the Valet home log file, while the remaining
     * services are read from their systemd journal. Unknown services fall back
     * to journalctl -u <service> and finally to a common log file path.
     */
    public function tail(string $service, int $lines = 50): void
    {
        $map = $this->serviceLogMap();

        if ($service === 'nginx') {
            $this->tailLogFile($map['nginx'], $service, $lines);

            return;
        }

        if ($service === 'app') {
            $this->tailAppLog($lines);

            return;
        }

        if (array_key_exists($service, $map)) {
            $this->tailJournal($map[$service], $service, $lines);

            return;
        }

        // Default: try journalctl, then fall back to a common log file.
        $output = $this->journalCtl($service, $lines);
        if (trim($output) !== '') {
            echo $output . PHP_EOL;

            return;
        }

        $fallback = '/var/log/' . $service . '.log';
        if ($this->files->exists($fallback)) {
            $this->tailLogFile($fallback, $service, $lines);

            return;
        }

        Writer::warn("No logs found for $service");
    }

    /**
     * Aggregate logs from multiple services with prefixes.
     *
     * @param string[] $services
     */
    public function aggregate(array $services, int $lines = 50, bool $follow = false, ?string $grep = null): void
    {
        $services = array_values(array_filter(array_map('trim', $services), fn ($s) => $s !== ''));
        if ($services === []) {
            $services = ['nginx', 'php'];
        }

        if ($follow) {
            $this->follow($services, $grep);

            return;
        }

        $chunks = $this->collect($services, $lines, $grep);
        if ($chunks === []) {
            Writer::warn('No log lines found for: ' . implode(', ', $services));

            return;
        }

        echo implode(PHP_EOL, $chunks) . PHP_EOL;
    }

    /**
     * Collect prefixed log lines for the given services (non-follow).
     *
     * @param string[] $services
     * @return string[]
     */
    public function collect(array $services, int $lines = 50, ?string $grep = null): array
    {
        $output = [];

        foreach ($services as $service) {
            foreach ($this->linesFor($service, $lines) as $line) {
                if ($grep !== null && $grep !== '' && !str_contains($line, $grep)) {
                    continue;
                }
                $output[] = sprintf('[%s] %s', $service, $line);
            }
        }

        return $output;
    }

    /**
     * Open the Mailpit web UI for the configured Valet domain.
     */
    public function openMail(): void
    {
        $domain = $this->config->get('domain', 'test');
        $url = 'https://mails.' . $domain;

        $this->cli->quietly('xdg-open ' . escapeshellarg($url));
    }

    /**
     * Map a service name to its log source.
     *
     * nginx maps to a file path, everything else maps to a systemd unit name
     * that is passed to journalctl.
     *
     * @return array<string,string>
     */
    private function serviceLogMap(): array
    {
        return [
            'nginx'   => VALET_HOME_PATH . '/Log/nginx-error.log',
            'php'     => 'php*-fpm',
            'mysql'   => 'mysql',
            'mariadb' => 'mariadb',
            'mailpit' => 'mailpit',
            'redis'   => 'redis',
        ];
    }

    /**
     * @return string[]
     */
    private function linesFor(string $service, int $lines): array
    {
        $raw = $this->rawLines($service, $lines);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\r\n|\n|\r/', rtrim($raw, "\n")) ?: [];

        return array_values(array_filter($parts, fn ($line) => $line !== ''));
    }

    private function rawLines(string $service, int $lines): string
    {
        $map = $this->serviceLogMap();

        if ($service === 'nginx') {
            $path = $map['nginx'];
            if (!$this->files->exists($path)) {
                return '';
            }

            return $this->tailFile($path, $lines);
        }

        if ($service === 'app') {
            $path = $this->appLogPath();
            if ($path === null || !$this->files->exists($path)) {
                return '';
            }

            return $this->tailFile($path, $lines);
        }

        if (array_key_exists($service, $map)) {
            return $this->journalCtl($map[$service], $lines);
        }

        $output = $this->journalCtl($service, $lines);
        if (trim($output) !== '') {
            return $output;
        }

        $fallback = '/var/log/' . $service . '.log';
        if ($this->files->exists($fallback)) {
            return $this->tailFile($fallback, $lines);
        }

        return '';
    }

    private function appLogPath(): ?string
    {
        $cwd = rtrim((string) getcwd(), '/');
        $laravel = $cwd . '/storage/logs/laravel.log';
        if ($this->files->exists($laravel)) {
            return $laravel;
        }

        return null;
    }

    private function tailAppLog(int $lines): void
    {
        $path = $this->appLogPath();
        if ($path === null) {
            Writer::warn('No app logs found (looked for storage/logs/laravel.log)');

            return;
        }

        $this->tailLogFile($path, 'app', $lines);
    }

    /**
     * Stream multiple log sources until interrupted.
     *
     * @param string[] $services
     */
    private function follow(array $services, ?string $grep = null): void
    {
        $parts = [];
        $map = $this->serviceLogMap();

        foreach ($services as $service) {
            $prefix = sprintf('sed -u "s/^/[%s] /"', $service);
            if ($grep !== null && $grep !== '') {
                $prefix = sprintf('grep --line-buffered -F %s | %s', escapeshellarg($grep), $prefix);
            }

            if ($service === 'nginx') {
                $path = $map['nginx'];
                $parts[] = sprintf(
                    '(tail -n 0 -F %s 2>/dev/null | %s)',
                    escapeshellarg($path),
                    $prefix
                );
                continue;
            }

            if ($service === 'app') {
                $path = $this->appLogPath();
                if ($path === null) {
                    continue;
                }
                $parts[] = sprintf(
                    '(tail -n 0 -F %s 2>/dev/null | %s)',
                    escapeshellarg($path),
                    $prefix
                );
                continue;
            }

            $unit = $map[$service] ?? $service;
            $parts[] = sprintf(
                '(journalctl -f --no-pager -u %s 2>/dev/null | %s)',
                escapeshellarg($unit),
                $prefix
            );
        }

        if ($parts === []) {
            Writer::warn('No followable log sources for: ' . implode(', ', $services));

            return;
        }

        Writer::info('Following logs (Ctrl+C to stop): ' . implode(', ', $services));
        $this->cli->passthru(implode(' & ', $parts) . '; wait');
    }

    /**
     * Tail the last $lines lines of the given log file.
     */
    private function tailFile(string $path, int $lines): string
    {
        $contents = $this->files->get($path);

        if ($contents === '') {
            return '';
        }

        $linesArray = preg_split('/\n/', rtrim($contents, "\n")) ?: [];
        $linesArray = array_slice($linesArray, -$lines);

        return implode("\n", $linesArray);
    }

    /**
     * Read the last $lines entries of the given systemd unit from journalctl.
     */
    private function journalCtl(string $service, int $lines): string
    {
        $failed = false;

        $output = $this->cli->run(
            "journalctl --no-pager -n $lines -u " . escapeshellarg($service),
            function () use (&$failed) {
                $failed = true;
            }
        );

        return $failed ? '' : $output;
    }

    /**
     * Tail a file-based log and output it (or warn when nothing is available).
     */
    private function tailLogFile(string $path, string $service, int $lines): void
    {
        if (!$this->files->exists($path)) {
            Writer::warn("No logs found for $service");

            return;
        }

        $output = $this->tailFile($path, $lines);

        if (trim($output) === '') {
            Writer::warn("No logs found for $service");

            return;
        }

        echo $output . PHP_EOL;
    }

    /**
     * Tail a journalctl-based log and output it (or warn when nothing is available).
     */
    private function tailJournal(string $unit, string $service, int $lines): void
    {
        $output = $this->journalCtl($unit, $lines);

        if (trim($output) === '') {
            Writer::warn("No logs found for $service");

            return;
        }

        echo $output . PHP_EOL;
    }
}
