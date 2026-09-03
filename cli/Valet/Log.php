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
