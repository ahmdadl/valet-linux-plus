<?php

namespace Valet;

use PDO;
use PDOException;

class Health
{
    public const SUPPORTED_SERVICES = ['nginx', 'php', 'mysql', 'postgres', 'redis', 'mailpit'];

    public function __construct(
        public CommandLine $cli,
        public Configuration $config,
        public Filesystem $files
    ) {
    }

    /**
     * Check a single service.
     *
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    public function check(string $service): array
    {
        $service = strtolower($service);

        return match ($service) {
            'nginx' => $this->checkNginx(),
            'php' => $this->checkPhp(),
            'mysql' => $this->checkMysql(),
            'postgres', 'postgresql', 'pgsql' => $this->checkPostgres(),
            'redis' => $this->checkRedis(),
            'mailpit' => $this->checkMailpit(),
            default => $this->checkCustom($service),
        };
    }

    /**
     * Check all known services.
     *
     * @return array<int, array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}>
     */
    public function checkAll(): array
    {
        $results = [];
        foreach (self::SUPPORTED_SERVICES as $service) {
            $results[] = $this->check($service);
        }

        // Also check custom services that have healthCheck URL
        try {
            $custom = $this->config->get('services', []);
            if (is_array($custom)) {
                foreach ($custom as $name => $definition) {
                    if (is_array($definition) && !empty($definition['healthCheck'])) {
                        $results[] = $this->checkCustom((string) $name);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $results;
    }

    /**
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    public function checkNginx(): array
    {
        $start = microtime(true);

        // Prefer nginx -t as lightweight check, fallback to socket connect
        try {
            $output = $this->cli->run('nginx -t 2>&1', function () {
            });
            $latency = (microtime(true) - $start) * 1000;
            if (str_contains(strtolower($output), 'test is successful') || str_contains(strtolower($output), 'syntax is ok')) {
                return $this->result('nginx', true, $latency, 'nginx -t successful');
            }
            // If nginx -t output doesn't contain success, try HTTP check
        } catch (\Throwable $e) {
            // fall through to HTTP check
        }

        // HTTP check
        $start = microtime(true);
        $port = (int) $this->config->get('port', 80);
        $healthy = false;
        $message = 'Connection refused';
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
        $latency = (microtime(true) - $start) * 1000;
        if ($socket) {
            fclose($socket);
            $healthy = true;
            $message = 'OK';
        } else {
            $message = $errstr ?: 'Connection refused';
        }

        return $this->result('nginx', $healthy, $latency, $message);
    }

    /**
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    public function checkPhp(): array
    {
        $start = microtime(true);
        $socketPath = VALET_HOME_PATH . '/valet.sock';
        try {
            $configuredSocket = $this->config->get('socket', $socketPath);
            if (is_string($configuredSocket) && $configuredSocket !== '') {
                $socketPath = $configuredSocket;
            }
        } catch (\Throwable $e) {
            // use default
        }

        $exists = $this->files->exists($socketPath);
        $latency = (microtime(true) - $start) * 1000;

        if ($exists) {
            return $this->result('php', true, $latency, 'Socket exists: ' . $socketPath);
        }

        // Fallback: check php -v
        try {
            $start = microtime(true);
            $output = $this->cli->run('php -v 2>&1');
            $latency = (microtime(true) - $start) * 1000;
            if (str_contains($output, 'PHP')) {
                return $this->result('php', true, $latency, 'php -v successful');
            }
        } catch (\Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;
            return $this->result('php', false, $latency, $e->getMessage());
        }

        return $this->result('php', false, $latency, 'Socket not found: ' . $socketPath);
    }

    /**
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    public function checkMysql(): array
    {
        $start = microtime(true);
        try {
            $host = $this->config->get('database.mysql_host', '127.0.0.1');
            $port = (int) $this->config->get('database.mysql_port', 3306);
            $user = $this->config->get('database.mysql_user', 'root');
            $dsn = sprintf('mysql:host=%s;port=%d', $host, $port);
            $pdo = new PDO($dsn, $user, null, [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;

            return $this->result('mysql', true, $latency, 'Connected');
        } catch (PDOException $e) {
            $latency = (microtime(true) - $start) * 1000;

            return $this->result('mysql', false, $latency, $e->getMessage());
        } catch (\Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return $this->result('mysql', false, $latency, $e->getMessage());
        }
    }

    /**
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    public function checkPostgres(): array
    {
        $start = microtime(true);
        try {
            $host = $this->config->get('database.pgsql_host', '127.0.0.1');
            $port = (int) $this->config->get('database.pgsql_port', 5432);
            $user = $this->config->get('database.pgsql_user', 'postgres');
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', $host, $port);
            $pdo = new PDO($dsn, $user, null, [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;

            return $this->result('postgres', true, $latency, 'Connected');
        } catch (PDOException $e) {
            $latency = (microtime(true) - $start) * 1000;

            return $this->result('postgres', false, $latency, $e->getMessage());
        } catch (\Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return $this->result('postgres', false, $latency, $e->getMessage());
        }
    }

    /**
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    public function checkRedis(): array
    {
        $start = microtime(true);
        try {
            $output = trim($this->cli->run('redis-cli ping 2>&1'));
            $latency = (microtime(true) - $start) * 1000;
            if (strtolower($output) === 'pong') {
                return $this->result('redis', true, $latency, 'PONG');
            }

            return $this->result('redis', false, $latency, $output !== '' ? $output : 'No response');
        } catch (\Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            // Fallback: check if redis extension can connect
            if (extension_loaded('redis')) {
                try {
                    $redis = new \Redis();
                    $connected = @$redis->connect('127.0.0.1', 6379, 1);
                    $latency = (microtime(true) - $start) * 1000;
                    if ($connected && $redis->ping() === true) {
                        return $this->result('redis', true, $latency, 'PONG via extension');
                    }
                } catch (\Throwable $inner) {
                    $latency = (microtime(true) - $start) * 1000;

                    return $this->result('redis', false, $latency, $inner->getMessage());
                }
            }

            return $this->result('redis', false, $latency, $e->getMessage());
        }
    }

    /**
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    public function checkMailpit(): array
    {
        $start = microtime(true);
        $host = '127.0.0.1';
        $port = 8025;
        try {
            $configuredPort = $this->config->get('mailpit_port', 8025);
            if (is_numeric($configuredPort)) {
                $port = (int) $configuredPort;
            }
        } catch (\Throwable $e) {
            // use default
        }

        $url = sprintf('http://%s:%d/api/v1/health', $host, $port);
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $content = @file_get_contents($url, false, $ctx);
        $latency = (microtime(true) - $start) * 1000;

        if ($content !== false) {
            return $this->result('mailpit', true, $latency, 'OK');
        }

        // Fallback to socket check
        $start2 = microtime(true);
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        $latency2 = (microtime(true) - $start2) * 1000;
        if ($socket) {
            fclose($socket);
            return $this->result('mailpit', true, $latency2, 'Port open');
        }

        return $this->result('mailpit', false, $latency, $errstr ?: 'Connection refused');
    }

    /**
     * Check a custom service via healthCheck URL.
     *
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    public function checkCustom(string $name): array
    {
        $start = microtime(true);
        $healthCheck = null;
        try {
            $services = $this->config->get('services', []);
            if (is_array($services) && isset($services[$name]) && is_array($services[$name])) {
                $healthCheck = $services[$name]['healthCheck'] ?? null;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        if (is_string($healthCheck) && $healthCheck !== '') {
            $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $content = @file_get_contents($healthCheck, false, $ctx);
            $latency = (microtime(true) - $start) * 1000;
            if ($content !== false) {
                return $this->result($name, true, $latency, 'OK');
            }

            return $this->result($name, false, $latency, 'Health check URL unreachable');
        }

        // No healthCheck URL, try generic service active check via ServiceManager
        try {
            $sm = resolve(\Valet\Contracts\ServiceManager::class);
            $active = $sm->isActive($name);
            $latency = (microtime(true) - $start) * 1000;

            return $this->result($name, $active, $latency, $active ? 'active' : 'inactive');
        } catch (\Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return $this->result($name, false, $latency, $e->getMessage());
        }
    }

    /**
     * @return array{service: string, healthy: bool, latency_ms: float, message: string, timestamp: string}
     */
    private function result(string $service, bool $healthy, float $latencyMs, string $message): array
    {
        return [
            'service' => $service,
            'healthy' => $healthy,
            'latency_ms' => round($latencyMs, 2),
            'message' => $message,
            'timestamp' => gmdate('c'),
        ];
    }
}
