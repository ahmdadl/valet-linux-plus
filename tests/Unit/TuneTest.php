<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Tests\TestCase;
use Valet\Tune;

use function Valet\swap;

class TuneTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $config;
    private MockInterface $cli;
    private Tune $tune;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->config = Mockery::mock(Configuration::class);
        $this->cli = Mockery::mock(CommandLine::class);

        $real = new Filesystem();
        $this->files->shouldReceive('exists')->andReturnUsing(fn (string $path) => $real->exists($path))->byDefault();
        $this->files->shouldReceive('isDir')->andReturnUsing(fn (string $path) => $real->isDir($path))->byDefault();
        $this->files->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path))->byDefault();

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;
        /** @var CommandLine $cli */
        $cli = $this->cli;

        $this->tune = new Tune($files, $config, $cli);
    }

    /**
     * @test
     */
    public function it_loads_dev_stub(): void
    {
        $contents = $this->tune->stubContents('dev');
        $this->assertStringContainsString('tune preset: dev', $contents);
        $this->assertStringContainsString('opcache.enable=1', $contents);
        $this->assertStringContainsString('opcache.validate_timestamps=1', $contents);
    }

    /**
     * @test
     */
    public function it_loads_debug_stub_with_opcache_off(): void
    {
        $contents = $this->tune->stubContents('debug');
        $this->assertStringContainsString('opcache.enable=0', $contents);
    }

    /**
     * @test
     */
    public function it_dry_runs_without_writing(): void
    {
        \ConsoleComponents\Writer::fake();

        $phpFpm = Mockery::mock();
        $phpFpm->shouldReceive('normalizePhpVersion')->with('8.3')->andReturn('8.3');
        $phpFpm->shouldReceive('getCurrentVersion')->andReturn('8.3');
        $phpFpm->shouldReceive('restart')->never();
        swap('Valet\PhpFpm', $phpFpm);

        $this->files->shouldReceive('isDir')->andReturn(true);

        $result = $this->tune->run('fast', '8.3', null, true, false);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('fast', $result['preset']);
    }

    /**
     * @test
     */
    public function it_rejects_unknown_preset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tune->run('turbo');
    }
}
