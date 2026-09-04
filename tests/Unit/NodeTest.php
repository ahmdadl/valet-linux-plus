<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Filesystem;
use Valet\Node;
use Valet\Tests\TestCase;

class NodeTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $cli;
    private Node $node;
    private string $fixturePath;
    private string $originalCwd;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = (string) getcwd();
        $this->fixturePath = sys_get_temp_dir() . '/valet-node-' . uniqid('', true);
        mkdir($this->fixturePath, 0777, true);
        chdir($this->fixturePath);

        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->cli = Mockery::mock(CommandLine::class);

        $real = new Filesystem();
        $this->files->shouldReceive('exists')->andReturnUsing(fn (string $path) => $real->exists($path))->byDefault();
        $this->files->shouldReceive('isDir')->andReturnUsing(fn (string $path) => $real->isDir($path))->byDefault();
        $this->files->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path))->byDefault();

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var CommandLine $cli */
        $cli = $this->cli;

        $this->node = new Node($files, $cli);
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
    public function it_reads_nvmrc(): void
    {
        file_put_contents($this->fixturePath . '/.nvmrc', "20.11.0\n");

        $this->assertSame('20.11.0', $this->node->detectProjectVersion());
    }

    /**
     * @test
     */
    public function it_reads_node_version_file_and_strips_v_prefix(): void
    {
        file_put_contents($this->fixturePath . '/.node-version', "v18.19.0\n");

        $this->assertSame('18.19.0', $this->node->detectProjectVersion());
    }

    /**
     * @test
     */
    public function it_requires_version_or_nvmrc_for_install(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->node->install(null);
    }

    /**
     * @test
     */
    public function it_fails_install_without_nvm(): void
    {
        $this->files->shouldReceive('isDir')->andReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nvm not found');
        $this->node->install('20');
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
