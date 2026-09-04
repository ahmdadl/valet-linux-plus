<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Valet\CloneProject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Tests\TestCase;

use function Valet\swap;

class CloneProjectTest extends TestCase
{
    private MockInterface $cli;
    private MockInterface $files;
    private MockInterface $config;
    private CloneProject $clone;
    private string $fixturePath;
    private string $originalCwd;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = (string) getcwd();
        $this->fixturePath = sys_get_temp_dir() . '/valet-clone-' . uniqid('', true);
        mkdir($this->fixturePath, 0777, true);
        chdir($this->fixturePath);

        $this->cli = Mockery::mock(CommandLine::class);
        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->config = Mockery::mock(Configuration::class);

        $real = new Filesystem();
        $this->files->shouldReceive('exists')->andReturnUsing(fn (string $path) => $real->exists($path))->byDefault();
        $this->files->shouldReceive('isDir')->andReturnUsing(fn (string $path) => $real->isDir($path))->byDefault();
        $this->files->shouldReceive('scandir')->andReturnUsing(fn (string $path) => $real->scandir($path))->byDefault();

        /** @var CommandLine $cli */
        $cli = $this->cli;
        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;

        $this->clone = new CloneProject($cli, $files, $config);
        $this->config->shouldReceive('get')->with('domain', 'test')->andReturn('test')->byDefault();
    }

    public function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeDir($this->fixturePath);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function it_resolves_github_shorthand_to_ssh_by_default(): void
    {
        $this->assertSame(
            'git@github.com:org/app.git',
            $this->clone->resolveRepositoryUrl('org/app')
        );
    }

    /**
     * @test
     */
    public function it_resolves_github_shorthand_to_https_when_flagged(): void
    {
        $this->assertSame(
            'https://github.com/org/app.git',
            $this->clone->resolveRepositoryUrl('org/app', false, true)
        );
    }

    /**
     * @test
     */
    public function it_resolves_target_from_url_basename(): void
    {
        $target = $this->clone->resolveTargetDirectory('https://github.com/org/my-app.git', null);
        $this->assertSame($this->fixturePath . '/my-app', $target);
    }

    /**
     * @test
     */
    public function it_refuses_non_empty_target_without_force(): void
    {
        $dir = $this->fixturePath . '/existing';
        mkdir($dir);
        file_put_contents($dir . '/file.txt', 'x');

        $this->cli->shouldReceive('run')->with(Mockery::pattern('/command -v git/'))->andReturn('/usr/bin/git');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--force');
        $this->clone->run('https://github.com/org/app.git', 'existing');
    }

    /**
     * @test
     */
    public function it_clones_and_links(): void
    {
        \ConsoleComponents\Writer::fake();

        $target = $this->fixturePath . '/app';
        $this->cli->shouldReceive('run')->with(Mockery::pattern('/command -v git/'))->andReturn('/usr/bin/git');
        $this->cli->shouldReceive('runAsUser')
            ->once()
            ->withArgs(function (string $cmd) use ($target) {
                return str_contains($cmd, 'git clone')
                    && str_contains($cmd, 'https://github.com/org/app.git')
                    && str_contains($cmd, $target);
            })
            ->andReturnUsing(function () use ($target) {
                mkdir($target, 0777, true);

                return '';
            });

        $siteLink = Mockery::mock();
        $siteLink->shouldReceive('link')->once()->with($target, 'app')->andReturn('/valet/Sites/app');
        swap('Valet\SiteLink', $siteLink);

        $steps = $this->clone->run('https://github.com/org/app.git', 'app', null, true);
        $this->assertTrue(collect($steps)->contains(fn ($s) => str_contains((string) $s, 'Cloned')));
        $this->assertTrue(collect($steps)->contains(fn ($s) => str_contains((string) $s, 'Linked')));
    }

    /**
     * @test
     */
    public function it_fails_when_git_missing(): void
    {
        $this->cli->shouldReceive('run')->with(Mockery::pattern('/command -v git/'))->andReturn('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('git is not installed');
        $this->clone->run('https://github.com/org/app.git');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
