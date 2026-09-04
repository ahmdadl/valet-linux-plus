<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\ShellHook;
use Valet\Tests\TestCase;

class ShellHookTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $config;
    private MockInterface $cli;
    private ShellHook $hook;

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
        $this->files->shouldReceive('ensureDirExists')->andReturnUsing(function (string $path) use ($real) {
            $real->ensureDirExists($path);
        })->byDefault();
        $this->files->shouldReceive('putAsUser')->andReturnUsing(function (string $path, string $contents) use ($real) {
            return $real->put($path, $contents);
        })->byDefault();
        $this->files->shouldReceive('unlink')->andReturnUsing(function (string $path) use ($real) {
            $real->unlink($path);
        })->byDefault();

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;
        /** @var CommandLine $cli */
        $cli = $this->cli;

        $this->hook = new ShellHook($files, $config, $cli);

        $this->config->shouldReceive('get')->with('domain', 'test')->andReturn('test')->byDefault();
        $this->config->shouldReceive('get')->with('php_version', '')->andReturn('8.3')->byDefault();
        $this->config->shouldReceive('get')->with('php_bin', [])->andReturn([])->byDefault();
        $this->config->shouldReceive('get')->with('paths', [])->andReturn([])->byDefault();
        $this->cli->shouldReceive('run')->with(Mockery::pattern('/command -v valet/'))->andReturn('/usr/bin/valet')->byDefault();
    }

    /**
     * @test
     */
    public function it_emits_zsh_hook_with_chpwd(): void
    {
        $script = $this->hook->emit('zsh');
        $this->assertStringContainsString('add-zsh-hook chpwd', $script);
        $this->assertStringContainsString('env --php-bin', $script);
        $this->assertStringContainsString('VALET_PHP', $script);
    }

    /**
     * @test
     */
    public function it_emits_bash_hook_with_prompt_command(): void
    {
        $script = $this->hook->emit('bash');
        $this->assertStringContainsString('PROMPT_COMMAND', $script);
        $this->assertStringContainsString('_valet_hook', $script);
    }

    /**
     * @test
     */
    public function it_emits_fish_hook(): void
    {
        $script = $this->hook->emit('fish');
        $this->assertStringContainsString('--on-variable PWD', $script);
        $this->assertStringContainsString('VALET_SITE', $script);
    }

    /**
     * @test
     */
    public function it_invalidates_cache_file(): void
    {
        $path = $this->hook->cachePath();
        (new Filesystem())->ensureDirExists(dirname($path));
        file_put_contents($path, '{"stamp":"x","paths":{}}');

        $this->hook->invalidateCache();
        $this->assertFileDoesNotExist($path);
    }

    /**
     * @test
     */
    public function it_rejects_unknown_shell(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hook->emit('tcsh');
    }
}
