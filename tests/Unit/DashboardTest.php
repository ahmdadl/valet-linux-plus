<?php

namespace Valet\Tests\Unit;

use Illuminate\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\ServiceManager;
use Valet\Dashboard;
use Valet\Filesystem;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\SiteIsolate;
use Valet\SiteLink;
use Valet\SiteProxy;
use Valet\SiteSecure;
use Valet\Tests\TestCase;

class DashboardTest extends TestCase
{
    private Configuration|MockObject $config;
    private Filesystem|MockObject $files;
    private SiteLink|MockObject $siteLink;
    private SiteProxy|MockObject $siteProxy;
    private SiteSecure|MockObject $siteSecure;
    private SiteIsolate|MockObject $siteIsolate;
    private Nginx|MockObject $nginx;
    private PhpFpm|MockObject $phpFpm;
    private Dashboard $dashboard;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = \Mockery::mock(Configuration::class);
        $this->files = \Mockery::mock(Filesystem::class);
        $this->siteLink = \Mockery::mock(SiteLink::class);
        $this->siteProxy = \Mockery::mock(SiteProxy::class);
        $this->siteSecure = \Mockery::mock(SiteSecure::class);
        $this->siteIsolate = \Mockery::mock(SiteIsolate::class);
        $this->nginx = \Mockery::mock(Nginx::class);
        $this->phpFpm = \Mockery::mock(PhpFpm::class);

        $this->dashboard = new Dashboard(
            $this->config,
            $this->files,
            $this->siteLink,
            $this->siteProxy,
            $this->siteSecure,
            $this->siteIsolate,
            $this->nginx,
            $this->phpFpm
        );

        // Bind best-effort service dependencies into the container so the
        // collector can resolve them for the services section.
        $cli = \Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->andReturnUsing(function (string $command) {
            if (str_contains($command, 'is-enabled')) {
                if (str_contains($command, 'mysql') || str_contains($command, 'postgres')) {
                    return 'Failed to get unit file state for ' . $command;
                }
                return 'enabled';
            }
            if (str_contains($command, 'is-active')) {
                if (str_contains($command, 'nginx') || str_contains($command, 'php8.3-fpm')) {
                    return 'active';
                }
                return 'inactive';
            }
            return '';
        });
        Container::getInstance()->instance(CommandLine::class, $cli);

        $serviceManager = \Mockery::mock(ServiceManager::class);
        $serviceManager->shouldReceive('isAvailable')->andReturn(true);
        Container::getInstance()->instance(ServiceManager::class, $serviceManager);
    }

    /**
     * @test
     */
    public function itReturnsExpectedKeysAndCounts(): void
    {
        $this->stubDependencies();

        $data = $this->dashboard->data();

        $this->assertArrayHasKey('domain', $data);
        $this->assertArrayHasKey('port', $data);
        $this->assertArrayHasKey('https_port', $data);
        $this->assertArrayHasKey('php_version', $data);
        $this->assertArrayHasKey('php_versions', $data);
        $this->assertArrayHasKey('paths', $data);
        $this->assertArrayHasKey('sites', $data);
        $this->assertArrayHasKey('counts', $data);
        $this->assertArrayHasKey('services', $data);
        $this->assertArrayHasKey('valet_version', $data);

        $this->assertSame('test', $data['domain']);
        $this->assertSame(80, $data['port']);
        $this->assertSame(443, $data['https_port']);
        $this->assertSame('8.3', $data['php_version']);
        $this->assertSame(['8.2', '8.3', '8.4', '8.5', '8.6'], $data['php_versions']);
        $this->assertSame(['/home/user/Code'], $data['paths']);
        $this->assertSame('2.99.0', $data['valet_version']);

        $this->assertSame([
            'parked'   => 2,
            'linked'   => 1,
            'proxied'  => 1,
            'secured'  => 2,
            'isolated' => 1,
            'total'    => 4,
        ], $data['counts']);

        $this->assertCount(6, $data['services']);
        $this->assertSame('nginx', $data['services'][0]['name']);
        $this->assertTrue($data['services'][0]['installed']);
        $this->assertSame('running', $data['services'][0]['status']);
    }

    /**
     * @test
     */
    public function itReturnsSortedSites(): void
    {
        $this->stubDependencies();

        $data = $this->dashboard->data();

        $names = array_column($data['sites'], 'name');
        $sorted = $names;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $names);
        $this->assertSame('linked-site', $data['sites'][0]['name']);
        $this->assertSame('linked', $data['sites'][0]['type']);
        $this->assertTrue($data['sites'][0]['secured']);

        $proxy = collect($data['sites'])->firstWhere('type', 'proxy');
        $this->assertSame('mails', $proxy['name']);
        $this->assertSame('http://127.0.0.1:8025', $proxy['proxy']);

        $isolated = collect($data['sites'])->firstWhere('name', 'my-app');
        $this->assertSame('8.1', $isolated['isolated']);
    }

    /**
     * @test
     */
    public function itRendersJsonWhenTemplateMissing(): void
    {
        $this->stubDependencies();

        $this->files
            ->shouldReceive('exists')
            ->with(VALET_ROOT_PATH . '/cli/templates/dashboard.html')
            ->andReturn(false);

        $output = $this->dashboard->render();

        $this->assertIsString($output);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('sites', $decoded);
    }

    /**
     * @test
     */
    public function itNeverThrowsWhenDependenciesFail(): void
    {
        $this->config->shouldReceive('get')->andThrow(new \RuntimeException('boom'));
        $this->files->shouldReceive('scandir')->andThrow(new \RuntimeException('boom'));
        $this->files->shouldReceive('isDir')->andReturn(false);
        $this->files->shouldReceive('exists')->andReturn(false);
        $this->files->shouldReceive('get')->andReturn('{}');
        $this->siteLink->shouldReceive('links')->andThrow(new \RuntimeException('boom'));
        $this->siteProxy->shouldReceive('proxies')->andThrow(new \RuntimeException('boom'));
        $this->siteSecure->shouldReceive('secured')->andThrow(new \RuntimeException('boom'));
        $this->siteIsolate->shouldReceive('isolatedDirectories')->andThrow(new \RuntimeException('boom'));
        $this->phpFpm->shouldReceive('getCurrentVersion')->andThrow(new \RuntimeException('boom'));

        $data = $this->dashboard->data();

        $this->assertIsArray($data);
        $this->assertSame('test', $data['domain']);
        $this->assertSame([], $data['sites']);
        $this->assertSame([
            'parked'   => 0,
            'linked'   => 0,
            'proxied'  => 0,
            'secured'  => 0,
            'isolated' => 0,
            'total'    => 0,
        ], $data['counts']);
    }

    /**
     * Wire the happy-path dependency stubs used by the data/render tests.
     */
    private function stubDependencies(): void
    {
        $this->config
            ->shouldReceive('get')
            ->andReturnUsing(function (string $key, $default = null) {
                return match ($key) {
                    'domain' => 'test',
                    'port' => 80,
                    'https_port' => 443,
                    'paths' => ['/home/user/Code'],
                    'php_version' => '8.3',
                    default => $default,
                };
            });

        $this->files
            ->shouldReceive('scandir')
            ->with('/home/user/Code')
            ->andReturn(['my-app', 'other']);
        $this->files
            ->shouldReceive('isDir')
            ->andReturnUsing(fn () => true);
        $this->files
            ->shouldReceive('exists')
            ->with(VALET_ROOT_PATH . '/cli/templates/dashboard.html')
            ->andReturn(false);
        $this->files
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH . '/composer.json')
            ->andReturn('{"version":"2.99.0"}');

        $this->siteLink
            ->shouldReceive('links')
            ->andReturn(collect([
                'linked-site' => [
                    'url'     => 'http://linked-site.test',
                    'secured' => '✓',
                    'path'    => '/home/user/.valet/Sites/linked-site',
                ],
            ]));

        $this->siteProxy
            ->shouldReceive('proxies')
            ->andReturn(collect([
                'mails.test' => [
                    'url'     => 'http://mails.test',
                    'secured' => '✕',
                    'path'    => 'http://127.0.0.1:8025',
                ],
            ]));

        $this->siteSecure
            ->shouldReceive('secured')
            ->andReturn(collect(['linked-site.test', 'my-app.test']));

        $this->siteIsolate
            ->shouldReceive('isolatedDirectories')
            ->andReturn(collect([
                'my-app.test' => [
                    'url'     => 'http://my-app.test',
                    'secured'  => '✕',
                    'version' => '8.1',
                ],
            ]));

        $this->phpFpm
            ->shouldReceive('getCurrentVersion')
            ->andReturn('8.3');
    }
}
