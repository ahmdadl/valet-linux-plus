<?php

namespace Valet;

/**
 * HTTP front controller for the dashboard's JSON API.
 *
 * server.php hands every request for a dashboard host to this class. It owns
 * three things the HTML renderer must not: the CSRF cookie, the JSON routes
 * under /api, and the decision about what a request is allowed to do.
 *
 * Nothing here mutates anything itself. Every change goes through
 * DashboardApi, which re-checks the loopback address, the CSRF token, the
 * origin and the confirmation value before it calls anything, so a mistake in
 * the routing layer cannot become a privileged action.
 */
class DashboardServer
{
    public function __construct(
        private Dashboard $dashboard,
        private DashboardApi $api,
        private DashboardJob $jobs,
        private DashboardPrivilege $privilege,
        private Filesystem $files
    ) {
    }

    /**
     * Route the current request.
     *
     * @return bool True when the request was an API request and has been
     *              answered (output sent, script about to exit).
     */
    public function handle(string $method, string $path): bool
    {
        $path = '/'.ltrim($path, '/');

        if (str_starts_with($path, '/api/')) {
            $this->issueCsrfCookie();
            $this->json($this->route($method, $path));

            return true;
        }

        // Hashed build assets are addressed by absolute URL (/assets/…) and are
        // immutable, so they are served with a long cache life. Everything else
        // below a dashboard host is the page itself.
        if (preg_match('#^/assets/[A-Za-z0-9._-]+$#', $path) === 1) {
            if ($this->serveAsset($path)) {
                return true;
            }

            $this->status(404);
            $this->json(['ok' => false, 'message' => 'No such dashboard asset.', 'data' => [], 'job' => null]);

            return true;
        }

        // Anything else under a dashboard host is the page itself. Mutations
        // are POST-only, so a GET that reaches here is always read-only.
        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->status(405);
            $this->json(['ok' => false, 'message' => 'Only GET and POST are accepted here.']);

            return true;
        }

        $this->issueCsrfCookie();
        $this->renderPage();

        return true;
    }

    /**
     * Dispatch one of the JSON endpoints.
     *
     * @return array<string, mixed>
     */
    private function route(string $method, string $path): array
    {
        if ($path === '/api/data') {
            return $this->requireMethod($method, 'GET') ?? $this->payload();
        }

        if ($path === '/api/catalog') {
            return $this->requireMethod($method, 'GET') ?? [
                'ok' => true,
                'message' => '',
                'data' => [
                    'tiers' => [DashboardApi::TIER_READ, DashboardApi::TIER_USER, DashboardApi::TIER_ROOT],
                    'actions' => $this->api->catalog(),
                ],
                'job' => null,
            ];
        }

        if ($path === '/api/jobs') {
            return $this->requireMethod($method, 'GET') ?? [
                'ok' => true,
                'message' => '',
                'data' => ['jobs' => $this->jobs->recent()],
                'job' => null,
            ];
        }

        if (preg_match('#^/api/jobs/([a-f0-9]{32})$#', $path, $matches) === 1) {
            return $this->requireMethod($method, 'GET') ?? $this->job($matches[1]);
        }

        if (preg_match('#^/api/actions/([a-z][a-z0-9]*(?:\.[a-z0-9]+)*)$#', $path, $matches) === 1) {
            return $this->requireMethod($method, 'POST') ?? $this->action($matches[1]);
        }

        $this->status(404);

        return ['ok' => false, 'message' => 'Unknown dashboard endpoint.', 'data' => [], 'job' => null];
    }

    /**
     * Reject the wrong verb for an endpoint without falling through to the page.
     *
     * @return array<string, mixed>|null
     */
    private function requireMethod(string $method, string $expected): ?array
    {
        if (strtoupper($method) === $expected) {
            return null;
        }

        $this->status(405);
        $this->header('Allow: '.$expected);

        return ['ok' => false, 'message' => 'This endpoint only accepts '.$expected.'.', 'data' => [], 'job' => null];
    }

    /**
     * The read payload: everything the page needs for its first paint.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'ok' => true,
            'message' => '',
            'data' => [
                'dashboard' => $this->dashboard->data(),
                'privileges' => $this->privilege->status(),
                'csrf' => $this->csrfToken(),
                'php_versions' => $this->phpVersions(),
                'php_isolation' => $this->isolationPhpVersions(),
            ],
            'job' => null,
        ];
    }

    /**
     * Run a mutating or read action requested over JSON.
     *
     * @return array<string, mixed>
     */
    private function action(string $slug): array
    {
        $request = DashboardRequest::captureAction();

        if ($request === null || $request->slug !== $slug) {
            $this->status(400);

            return ['ok' => false, 'message' => 'Malformed dashboard request.', 'data' => [], 'job' => null];
        }

        try {
            return $this->api->dispatch($request);
        } catch (\Valet\Exceptions\DashboardActionException $e) {
            $this->status(400);

            return ['ok' => false, 'message' => $e->getMessage(), 'data' => [], 'job' => null];
        } catch (\Throwable $e) {
            $this->status(500);

            return ['ok' => false, 'message' => $e->getMessage(), 'data' => [], 'job' => null];
        }
    }

    /**
     * Report on one background job, with the tail of its log.
     *
     * @return array<string, mixed>
     */
    private function job(string $id): array
    {
        $job = $this->jobs->find($id);

        if ($job === null) {
            $this->status(404);

            return ['ok' => false, 'message' => 'Unknown job.', 'data' => [], 'job' => null];
        }

        $job['log'] = $this->jobs->log($id);

        return ['ok' => true, 'message' => '', 'data' => ['job' => $job], 'job' => $id];
    }

    /**
     * The PHP versions the dashboard may switch between.
     *
     * @return array<int, string>
     */
    private function phpVersions(): array
    {
        try {
            $phpFpm = $this->phpFpm();

            return $phpFpm !== null ? $phpFpm->installedPhpVersions() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The PhpFpm instance, or null when the container cannot build one.
     */
    private function phpFpm(): ?PhpFpm
    {
        $phpFpm = resolve(PhpFpm::class);

        return $phpFpm instanceof PhpFpm ? $phpFpm : null;
    }

    /**
     * The PHP versions a site may be isolated onto.
     *
     * A wider range than phpVersions(): isolation pins a version per site, so
     * it may be an older one that is no longer worth switching the whole
     * machine to.
     *
     * @return array<int, string>
     */
    private function isolationPhpVersions(): array
    {
        try {
            $phpFpm = $this->phpFpm();

            if ($phpFpm !== null) {
                $installed = $phpFpm->installedPhpVersions();

                if ($installed !== []) {
                    return $installed;
                }
            }

            return PhpFpm::isolationSupportedPhpVersions();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Hand the CSRF token to the page.
     *
     * The cookie is deliberately readable by JavaScript: the double-submit
     * pattern needs the browser to echo the value back in a header, which a
     * cross-origin page can never do. SameSite=Strict is what stops the cookie
     * from riding along on someone else's request in the first place.
     */
    private function issueCsrfCookie(): void
    {
        if (isset($_COOKIE[DashboardRequest::csrfCookieName()])) {
            return;
        }

        $token = bin2hex(random_bytes(32));

        // Nothing can set a cookie once output has started, which is also the
        // case under the test runner; the page still gets its token from the
        // payload in that case.
        if (!headers_sent()) {
            setcookie(DashboardRequest::csrfCookieName(), $token, [
                'expires' => 0,
                'path' => '/',
                'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
                'httponly' => false,
                'samesite' => 'Strict',
            ]);
        }

        $_COOKIE[DashboardRequest::csrfCookieName()] = $token;
    }

    /**
     * The CSRF token in play for this request.
     */
    private function csrfToken(): string
    {
        $token = $_COOKIE[DashboardRequest::csrfCookieName()] ?? '';

        return is_string($token) ? $token : '';
    }

    /**
     * Emit the dashboard page, degrading to raw JSON if the template is gone.
     *
     * The React build in cli/templates/dashboard-dist is served first: it is a
     * plain static bundle, so an installed package needs no Node at runtime.
     * The vanilla template remains as the fallback, which is what makes the
     * upgrade safe on a machine that has never run npm.
     */
    private function renderPage(): void
    {
        $this->header('Content-Type: text/html; charset=utf-8');
        $this->header('Cache-Control: no-store');
        $this->header('Referrer-Policy: same-origin');
        $this->header('X-Content-Type-Options: nosniff');

        if ($this->serveBuiltApp()) {
            return;
        }

        try {
            echo $this->dashboard->render();

            return;
        } catch (\Throwable $e) {
            // Fall through to the template on disk, then to plain JSON.
        }

        $fallback = VALET_ROOT_PATH.'/cli/templates/dashboard.html';

        if ($this->files->exists($fallback)) {
            try {
                echo $this->dashboard->renderTemplate((string) $this->files->get($fallback));

                return;
            } catch (\Throwable $e) {
                // Fall through.
            }
        }

        echo json_encode([
            'ok' => true,
            'data' => ['dashboard' => $this->dashboard->data(), 'privileges' => $this->privilege->status()],
        ]);
    }

    /**
     * Where the built React dashboard lives.
     *
     * It is committed to the repository so an installed composer package serves
     * a real SPA without Node being present on the machine.
     */
    private function builtAppPath(): string
    {
        return VALET_ROOT_PATH.'/cli/templates/dashboard-dist';
    }

    /**
     * Serve the built dashboard's index.html.
     *
     * Called for every client-side route as well as for "/", so a reload on
     * /sites/example.test returns the app rather than a 404.
     */
    private function serveBuiltApp(): bool
    {
        $index = $this->builtAppPath().'/index.html';

        if (!$this->files->exists($index)) {
            return false;
        }

        $html = $this->files->get($index);

        if (!is_string($html) || $html === '') {
            return false;
        }

        // The token the page needs for its first mutation is already in the
        // cookie; the app reads it from there at runtime.
        echo $html;

        return true;
    }

    /**
     * Serve one hashed asset from the built dashboard.
     *
     * The path is resolved with realpath and then checked against the build
     * directory, so a crafted request cannot read anything outside it.
     */
    private function serveAsset(string $path): bool
    {
        $root = realpath($this->builtAppPath());

        if ($root === false) {
            return false;
        }

        $target = realpath($root.'/'.ltrim($path, '/'));

        if ($target === false || !is_file($target)) {
            return false;
        }

        if (!str_starts_with($target, $root.DIRECTORY_SEPARATOR)) {
            return false;
        }

        $this->header('Content-Type: '.$this->mimeFor($target));
        $this->header('Cache-Control: public, max-age=31536000, immutable');
        $this->header('X-Content-Type-Options: nosniff');

        readfile($target);

        return true;
    }

    /**
     * A content type for the handful of files a Vite build emits.
     */
    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'map' => 'application/json; charset=utf-8',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): void
    {
        $this->header('Content-Type: application/json; charset=utf-8');
        $this->header('Cache-Control: no-store');
        $this->header('X-Content-Type-Options: nosniff');

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function header(string $line): void
    {
        if (PHP_SAPI !== 'cli') {
            header($line);
        }
    }

    private function status(int $code): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($code);
        }
    }
}
