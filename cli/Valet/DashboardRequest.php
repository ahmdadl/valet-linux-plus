<?php

namespace Valet;

/**
 * Immutable snapshot of an incoming dashboard HTTP request.
 *
 * The dashboard is served by `server.php` (php-fpm) and every mutating action
 * has to be authenticated before it is dispatched. Keeping the raw request
 * state in a small value object lets DashboardApi enforce its security rules
 * without touching superglobals, which in turn keeps the rules unit testable.
 */
final class DashboardRequest
{
    /**
     * Loopback addresses that are always considered local.
     *
     * `::ffff:127.0.0.1` is the IPv6-mapped IPv4 form that php-fpm reports when
     * nginx connects over a dual-stack socket.
     */
    private const LOOPBACK = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    /**
     * @var array<string, mixed>
     */
    public readonly array $payload;

    /**
     * Build a request context.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $method,
        public readonly string $slug,
        public readonly string $host,
        public readonly string $remoteAddress,
        public readonly ?string $origin,
        public readonly ?string $referer,
        public readonly ?string $csrfCookie,
        public readonly ?string $csrfHeader,
        array $payload = []
    ) {
        $this->payload = $payload;
    }

    /**
     * Capture the current dashboard request from the PHP superglobals.
     *
     * Returns null when the request does not target the action endpoint, so
     * the caller can fall through to normal page rendering.
     */
    public static function captureAction(): ?self
    {
        $method = self::headerValue($_SERVER, 'REQUEST_METHOD') ?? 'GET';
        $requestUri = self::headerValue($_SERVER, 'REQUEST_URI') ?? '/';
        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        $path = '/' . trim(is_string($parsedPath) ? $parsedPath : '', '/');

        if (!str_starts_with($path, '/api/actions/')) {
            return null;
        }

        $slug = rawurldecode(trim(substr($path, strlen('/api/actions/')), '/'));

        $payload = [];
        $contentType = self::headerValue($_SERVER, 'CONTENT_TYPE') ?? '';

        if (str_contains(strtolower($contentType), 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $payload = $decoded;
            }
        }

        $cookie = self::headerValue($_COOKIE, self::csrfCookieName());

        return new self(
            strtoupper($method),
            $slug,
            strtolower(explode(':', self::headerValue($_SERVER, 'HTTP_HOST') ?? '')[0]),
            self::headerValue($_SERVER, 'REMOTE_ADDR') ?? '',
            self::headerValue($_SERVER, 'HTTP_ORIGIN'),
            self::headerValue($_SERVER, 'HTTP_REFERER'),
            $cookie,
            self::headerValue($_SERVER, 'HTTP_X_VALET_CSRF'),
            $payload
        );
    }

    /**
     * The name of the double-submit CSRF cookie.
     */
    public static function csrfCookieName(): string
    {
        return 'valet_csrf';
    }

    /**
     * Whether the request originated from the local machine.
     */
    public function isLoopback(): bool
    {
        return in_array($this->remoteAddress, self::LOOPBACK, true);
    }

    /**
     * Whether the supplied CSRF header matches the double-submit cookie.
     */
    public function hasValidCsrf(): bool
    {
        $cookie = $this->csrfCookie;
        $header = $this->csrfHeader;

        if ($cookie === null || $header === null) {
            return false;
        }

        // Both values are client-supplied, so require a non-trivial length to
        // rule out a trivially guessable token.
        if (strlen($cookie) < 32 || strlen($header) < 32) {
            return false;
        }

        return hash_equals($cookie, $header);
    }

    /**
     * Whether the browser identified the request as same-origin with us.
     *
     * Modern browsers always send Origin on cross-origin writes. When it is
     * absent we fall back to the Referer. A request with neither is treated as
     * non-browser (curl) and still has to pass the CSRF check.
     *
     * Every header that is present has to agree. A request claiming a foreign
     * Origin but a local Referer is one no browser produces, so the safest
     * reading is that somebody is trying to talk past the check.
     */
    public function hasValidOrigin(): bool
    {
        $candidates = array_filter([$this->origin, $this->referer]);

        if ($candidates === []) {
            return true;
        }

        foreach ($candidates as $candidate) {
            $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));

            if ($host === '' || ($host !== $this->host && $host !== 'www.' . $this->host)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read the confirmation value the client must echo back.
     */
    public function confirmation(): ?string
    {
        $value = $this->payload['confirm'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Parameters with the control field `confirm` removed.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        $params = $this->payload;
        unset($params['confirm']);

        return $params;
    }

    /**
     * Extract a single header, or null when it is absent or empty.
     *
     * Superglobals are typed `mixed` as far as PHPStan is concerned, so this
     * is where they become strings. Returning null rather than an empty string
     * lets the callers treat "absent" and "blank" as the same thing.
     *
     * @param array<array-key, mixed> $source
     */
    private static function headerValue(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
