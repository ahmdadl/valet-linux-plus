<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\Addon;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Filesystem;
use Valet\Tests\TestCase;

use function Valet\swap;

class AddonTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $config;
    private MockInterface $cli;
    private MockInterface $pm;
    private Addon $addon;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->cli = Mockery::mock(CommandLine::class);
        $this->pm = Mockery::mock(PackageManager::class);

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;
        /** @var CommandLine $cli */
        $cli = $this->cli;
        /** @var PackageManager $pm */
        $pm = $this->pm;

        $this->addon = new Addon($files, $config, $cli, $pm);
    }

    /**
     * @test
     */
    public function it_lists_catalog_addons(): void
    {
        $this->config->shouldReceive('get')->with('addons', [])->andReturn(['minio' => true]);
        $this->files->shouldReceive('exists')->andReturn(false);

        $registry = Mockery::mock();
        $registry->shouldReceive('isCustomService')->andReturn(false);
        swap('Valet\ServiceRegistry', $registry);

        $rows = $this->addon->list();
        $names = array_column($rows, 'name');

        $this->assertContains('minio', $names);
        $this->assertContains('meilisearch', $names);
        $this->assertContains('adminer', $names);
        $this->assertContains('mailpit', $names);

        $minio = collect($rows)->firstWhere('name', 'minio');
        $this->assertTrue($minio['enabled']);
        $mailpit = collect($rows)->firstWhere('name', 'mailpit');
        $this->assertTrue($mailpit['enabled']);
    }

    /**
     * @test
     */
    public function it_enables_meilisearch_from_template(): void
    {
        $this->config->shouldReceive('get')->with('addons', [])->andReturn([]);
        $this->config->shouldReceive('get')->with('domain', 'test')->andReturn('test');
        $this->config->shouldReceive('set')->once()->with('addons', ['meilisearch' => true]);

        $registry = Mockery::mock();
        $registry->shouldReceive('templates')->andReturn([
            'meilisearch' => [
                'name' => 'meilisearch',
                'package' => 'meilisearch',
                'service' => 'meilisearch',
                'port' => 7700,
                'proxyHost' => 'http://127.0.0.1:7700',
            ],
        ]);
        $registry->shouldReceive('addService')->once()->with('meilisearch', Mockery::type('array'));
        $registry->shouldReceive('start')->once()->with(['meilisearch']);
        swap('Valet\ServiceRegistry', $registry);

        $this->pm->shouldReceive('installed')->with('meilisearch')->andReturn(true);

        $proxy = Mockery::mock();
        $proxy->shouldReceive('proxyCreate')->once()->with('search.test', 'http://127.0.0.1:7700', true);
        swap('Valet\SiteProxy', $proxy);

        $nginx = Mockery::mock();
        $nginx->shouldReceive('restart')->once();
        swap('Valet\Nginx', $nginx);

        \ConsoleComponents\Writer::fake();
        $this->addon->enable('meilisearch');
    }

    /**
     * @test
     */
    public function it_rejects_unknown_addons(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->addon->enable('nope');
    }
}
