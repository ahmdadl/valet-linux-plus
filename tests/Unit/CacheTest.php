<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\Cache;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Tests\TestCase;

class CacheTest extends TestCase
{
    private MockInterface $cli;
    private MockInterface $files;
    private MockInterface $config;
    private Cache $cache;

    public function setUp(): void
    {
        parent::setUp();

        $this->cli = Mockery::mock(CommandLine::class);
        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->config = Mockery::mock(Configuration::class);

        $real = new Filesystem();
        $this->files->shouldReceive('exists')->andReturnUsing(fn (string $path) => $real->exists($path))->byDefault();
        $this->files->shouldReceive('isDir')->andReturnUsing(fn (string $path) => $real->isDir($path))->byDefault();
        $this->files->shouldReceive('unlink')->andReturnUsing(function (string $path) use ($real) {
            $real->unlink($path);
        })->byDefault();
        $this->files->shouldReceive('scandir')->andReturnUsing(fn (string $path) => $real->scandir($path))->byDefault();

        /** @var CommandLine $cli */
        $cli = $this->cli;
        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;

        $this->cache = new Cache($cli, $files, $config);
    }

    /**
     * @test
     */
    public function it_clears_only_valet_temps_by_default(): void
    {
        \ConsoleComponents\Writer::fake();

        $hook = VALET_HOME_PATH . '/shell-hook.cache.json';
        (new Filesystem())->ensureDirExists(VALET_HOME_PATH);
        file_put_contents($hook, '{}');

        $this->cli->shouldReceive('run')->never()->with(Mockery::pattern('/composer clear-cache/'));

        $steps = $this->cache->clear();
        $this->assertFalse(file_exists($hook));
        $this->assertTrue(collect($steps)->contains(fn ($s) => str_contains((string) $s, 'shell-hook')));
    }

    /**
     * @test
     */
    public function doctor_returns_actionable_tips(): void
    {
        $this->cli->shouldReceive('run')->andReturn('')->byDefault();
        $this->files->shouldReceive('isDir')->andReturn(false)->byDefault();

        $tips = $this->cache->doctor();
        $this->assertNotEmpty($tips);
        $this->assertTrue(collect($tips)->contains(fn ($t) => str_contains((string) $t, 'COMPOSER_PROCESS_TIMEOUT')));
    }

    /**
     * @test
     */
    public function paths_include_valet_locations(): void
    {
        $this->cli->shouldReceive('run')->andReturn('')->byDefault();
        $paths = $this->cache->paths();

        $this->assertArrayHasKey('valet_shell_hook', $paths);
        $this->assertStringContainsString('shell-hook.cache.json', (string) $paths['valet_shell_hook']);
    }
}
