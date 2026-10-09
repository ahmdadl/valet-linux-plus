<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\Dashboard;
use Valet\DashboardApi;
use Valet\DashboardJob;
use Valet\DashboardPrivilege;
use Valet\DashboardServer;
use Valet\Filesystem;
use Valet\Tests\TestCase;

/**
 * How the front controller decides what to serve.
 *
 * The React build in cli/templates/dashboard-dist takes precedence over the
 * legacy template, and the API routes must keep answering whatever the page
 * does, so both halves are pinned here.
 */
class DashboardServerTest extends TestCase
{
    private function server(?Filesystem $files = null): DashboardServer
    {
        return new DashboardServer(
            Mockery::mock(Dashboard::class),
            Mockery::mock(DashboardApi::class),
            Mockery::mock(DashboardJob::class),
            Mockery::mock(DashboardPrivilege::class),
            $files ?? Mockery::mock(Filesystem::class),
        );
    }

    /** Capture the body a request produces, with headers suppressed. */
    private function capture(callable $request): string
    {
        ob_start();

        try {
            $request();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function test_it_serves_the_built_spa_for_client_side_routes(): void
    {
        $files = Mockery::mock(Filesystem::class);
        $files->shouldReceive('exists')
            ->with(VALET_ROOT_PATH.'/cli/templates/dashboard-dist/index.html')
            ->andReturn(true);
        $files->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/templates/dashboard-dist/index.html')
            ->andReturn('<html>spa</html>');

        $html = $this->capture(fn () => $this->server($files)->handle('GET', '/sites/example.test'));

        $this->assertSame('<html>spa</html>', $html);
    }

    public function test_it_falls_back_to_the_legacy_template_when_no_build_exists(): void
    {
        $files = Mockery::mock(Filesystem::class);
        $files->shouldReceive('exists')
            ->with(VALET_ROOT_PATH.'/cli/templates/dashboard-dist/index.html')
            ->andReturn(false);

        $dashboard = Mockery::mock(Dashboard::class);
        $dashboard->shouldReceive('render')->once()->andReturn('<html>legacy</html>');

        $server = new DashboardServer(
            $dashboard,
            Mockery::mock(DashboardApi::class),
            Mockery::mock(DashboardJob::class),
            Mockery::mock(DashboardPrivilege::class),
            $files,
        );

        $html = $this->capture(fn () => $server->handle('GET', '/'));

        $this->assertSame('<html>legacy</html>', $html);
    }

    public function test_it_answers_a_missing_asset_with_404_json(): void
    {
        $files = Mockery::mock(Filesystem::class);
        // No build directory at all: realpath() fails, so nothing is served.
        $body = $this->capture(fn () => $this->server($files)->handle('GET', '/assets/index-abc123.js'));

        $this->assertStringContainsString('No such dashboard asset', $body);
    }

    public function test_it_still_routes_the_api_to_json(): void
    {
        $api = Mockery::mock(DashboardApi::class);
        $api->shouldReceive('catalog')->once()->andReturn([]);

        $server = new DashboardServer(
            Mockery::mock(Dashboard::class),
            $api,
            Mockery::mock(DashboardJob::class),
            Mockery::mock(DashboardPrivilege::class),
            Mockery::mock(Filesystem::class),
        );

        $body = $this->capture(fn () => $server->handle('GET', '/api/catalog'));

        $this->assertJson($body);
        $this->assertStringContainsString('"tiers"', $body);
    }

    public function test_a_post_to_a_client_side_route_is_rejected(): void
    {
        $body = $this->capture(fn () => $this->server()->handle('POST', '/sites'));

        $this->assertStringContainsString('Only GET and POST are accepted here', $body);
    }

    public function test_an_empty_filesystem_object_is_accepted(): void
    {
        // The server must not depend on any concrete Filesystem behaviour to
        // answer an unknown API path.
        $body = $this->capture(fn () => $this->server()->handle('GET', '/api/unknown'));

        $this->assertStringContainsString('Unknown dashboard endpoint', $body);
    }
}
