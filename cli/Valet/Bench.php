<?php

namespace Valet;

use ConsoleComponents\Writer;
use RuntimeException;
use Valet\Facades\Configuration as ConfigurationFacade;
use Valet\Facades\ProjectContext as ProjectContextFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

class Bench
{
    public function __construct(
        public CommandLine $cli,
        public Configuration $config,
        public Filesystem $files
    ) {
    }

    /**
     * Benchmark local site latency.
     *
     * @return array<string, mixed>
     */
    public function run(
        ?string $site = null,
        int $requests = 20,
        string $path = '/',
        int $warmup = 2,
        bool $json = false
    ): array {
        $requests = max(1, $requests);
        $warmup = max(0, $warmup);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $host = $this->resolveHost($site);
        $secured = false;
        try {
            $secured = SiteSecureFacade::secured()->contains($host);
        } catch (\Throwable $e) {
            $secured = false;
        }

        $scheme = $secured ? 'https' : 'http';
        $url = sprintf('%s://%s%s', $scheme, $host, $path);
        $httpsPortRaw = $this->config->get('https_port', 443);
        $httpPortRaw = $this->config->get('port', 80);
        $port = $secured
            ? (is_numeric($httpsPortRaw) ? (int) $httpsPortRaw : 443)
            : (is_numeric($httpPortRaw) ? (int) $httpPortRaw : 80);

        $dnsMs = $this->timeDns($host);
        $connectMs = $this->timeConnect($host, $port);
        $tlsMs = $secured ? $this->timeTls($host, $port) : null;

        // Warmup
        for ($i = 0; $i < $warmup; $i++) {
            $this->timedGet($url, $secured);
        }

        $ttfb = [];
        $total = [];
        $failures = 0;
        for ($i = 0; $i < $requests; $i++) {
            $sample = $this->timedGet($url, $secured);
            if ($sample === null) {
                $failures++;
                continue;
            }
            $ttfb[] = $sample['ttfb'];
            $total[] = $sample['total'];
        }

        if ($ttfb === []) {
            $result = [
                'site' => $host,
                'url' => $url,
                'scheme' => $scheme,
                'reachable' => false,
                'dns_ms' => $dnsMs,
                'connect_ms' => $connectMs,
                'tls_ms' => $tlsMs,
                'failures' => $failures,
                'timestamp' => gmdate('c'),
            ];
            if ($json) {
                Writer::info((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                Writer::error(sprintf('Site unreachable: %s', $url));
            }

            throw new RuntimeException(sprintf('Site unreachable: %s', $url));
        }

        $result = [
            'site' => $host,
            'url' => $url,
            'scheme' => $scheme,
            'reachable' => true,
            'dns_ms' => round($dnsMs, 2),
            'connect_ms' => round($connectMs, 2),
            'tls_ms' => $tlsMs !== null ? round($tlsMs, 2) : null,
            'ttfb_ms' => [
                'p50' => round($this->percentile($ttfb, 50), 2),
                'p95' => round($this->percentile($ttfb, 95), 2),
                'avg' => round(array_sum($ttfb) / count($ttfb), 2),
            ],
            'total_ms' => [
                'p50' => round($this->percentile($total, 50), 2),
                'p95' => round($this->percentile($total, 95), 2),
                'avg' => round(array_sum($total) / count($total), 2),
            ],
            'requests' => $requests,
            'warmup' => $warmup,
            'failures' => $failures,
            'timestamp' => gmdate('c'),
        ];

        if ($json) {
            Writer::info((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printHuman($result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printHuman(array $result): void
    {
        $site = is_scalar($result['site'] ?? null) ? (string) $result['site'] : '';
        $scheme = is_scalar($result['scheme'] ?? null) ? (string) $result['scheme'] : 'http';
        $dns = is_numeric($result['dns_ms'] ?? null) ? (float) $result['dns_ms'] : 0.0;
        $connect = is_numeric($result['connect_ms'] ?? null) ? (float) $result['connect_ms'] : 0.0;

        Writer::info(sprintf('Site: %s (%s)', $site, $scheme));
        Writer::info(sprintf('DNS      %6.1f ms', $dns));
        Writer::info(sprintf('Connect  %6.1f ms', $connect));
        if (isset($result['tls_ms']) && is_numeric($result['tls_ms'])) {
            Writer::info(sprintf('TLS      %6.1f ms', (float) $result['tls_ms']));
        }
        /** @var array{p50: float, p95: float} $ttfb */
        $ttfb = is_array($result['ttfb_ms'] ?? null) ? $result['ttfb_ms'] : ['p50' => 0.0, 'p95' => 0.0];
        /** @var array{p50: float} $total */
        $total = is_array($result['total_ms'] ?? null) ? $result['total_ms'] : ['p50' => 0.0];
        Writer::info(sprintf('TTFB     %6.1f ms  (p50)  %6.1f ms (p95)', (float) $ttfb['p50'], (float) $ttfb['p95']));
        Writer::info(sprintf('Total    %6.1f ms  (p50)', (float) $total['p50']));
    }

    private function resolveHost(?string $site): string
    {
        $tld = ConfigurationFacade::get('domain', 'test');
        $tld = is_scalar($tld) ? (string) $tld : 'test';

        if ($site !== null && $site !== '') {
            if (str_contains($site, '.')) {
                return $site;
            }

            return $site . '.' . $tld;
        }

        $context = ProjectContextFacade::fromCwd();

        return (string) $context['url'];
    }

    private function timeDns(string $host): float
    {
        $start = microtime(true);
        $ip = gethostbyname($host);
        $elapsed = (microtime(true) - $start) * 1000;
        if ($ip === $host) {
            // unresolved — still return timing
        }

        return $elapsed;
    }

    private function timeConnect(string $host, int $port): float
    {
        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        $elapsed = (microtime(true) - $start) * 1000;
        if (is_resource($socket)) {
            fclose($socket);
        }

        return $elapsed;
    }

    private function timeTls(string $host, int $port): float
    {
        $start = microtime(true);
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'capture_peer_cert' => false,
            ],
        ]);
        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            2,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $elapsed = (microtime(true) - $start) * 1000;
        if (is_resource($socket)) {
            fclose($socket);
        }

        return $elapsed;
    }

    /**
     * @return array{ttfb: float, total: float}|null
     */
    private function timedGet(string $url, bool $secured): ?array
    {
        $start = microtime(true);
        $ttfb = null;

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT => 'ValetLinux+/bench',
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$ttfb, $start) {
                if ($ttfb === null) {
                    $ttfb = (microtime(true) - $start) * 1000;
                }

                return strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        $total = (microtime(true) - $start) * 1000;
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ok === false || $code === 0) {
            return null;
        }

        return [
            'ttfb' => $ttfb ?? $total,
            'total' => $total,
        ];
    }

    /**
     * @param list<float> $values
     */
    private function percentile(array $values, float $p): float
    {
        sort($values);
        $count = count($values);
        if ($count === 1) {
            return $values[0];
        }
        $rank = ($p / 100) * ($count - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $values[$low];
        }
        $weight = $rank - $low;

        return $values[$low] * (1 - $weight) + $values[$high] * $weight;
    }
}
