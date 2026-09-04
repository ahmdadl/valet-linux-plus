<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Snapshot;
use Valet\Tests\TestCase;

use function Valet\swap;

class SnapshotTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $config;
    private MockInterface $cli;
    private Snapshot $snapshot;
    private string $fixturePath;
    private string $originalCwd;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = (string) getcwd();
        $this->fixturePath = sys_get_temp_dir() . '/valet-snap-proj-' . uniqid('', true);
        mkdir($this->fixturePath . '/.valet', 0777, true);
        chdir($this->fixturePath);

        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->config = Mockery::mock(Configuration::class);
        $this->cli = Mockery::mock(CommandLine::class);

        $real = new Filesystem();
        $this->files->shouldReceive('exists')->andReturnUsing(fn (string $path) => $real->exists($path))->byDefault();
        $this->files->shouldReceive('isDir')->andReturnUsing(fn (string $path) => $real->isDir($path))->byDefault();
        $this->files->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path))->byDefault();
        $this->files->shouldReceive('putAsUser')->andReturnUsing(function (string $path, string $contents) use ($real) {
            return $real->put($path, $contents);
        })->byDefault();
        $this->files->shouldReceive('ensureDirExists')->andReturnUsing(function (string $path) use ($real) {
            $real->ensureDirExists($path);
        })->byDefault();
        $this->files->shouldReceive('scandir')->andReturnUsing(fn (string $path) => $real->scandir($path))->byDefault();
        $this->files->shouldReceive('remove')->andReturnUsing(function ($path) use ($real) {
            $real->remove($path);
        })->byDefault();

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;
        /** @var CommandLine $cli */
        $cli = $this->cli;

        $this->snapshot = new Snapshot($files, $config, $cli);

        $projectContext = Mockery::mock();
        $projectContext->shouldReceive('fromCwd')->andReturn([
            'site' => 'my-app',
            'url' => 'my-app.test',
            'driver' => false,
            'framework' => 'laravel',
        ])->byDefault();
        $projectContext->shouldReceive('envPath')->andReturn(false)->byDefault();
        swap('Valet\ProjectContext', $projectContext);

        \ConsoleComponents\Writer::fake();
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
    public function it_creates_and_lists_snapshot(): void
    {
        file_put_contents($this->fixturePath . '/.valet/profile.json', json_encode([
            'php' => '8.3',
            'secure' => true,
        ], JSON_THROW_ON_ERROR));

        $dir = $this->snapshot->create('before-migrate', false, 'pre migration');
        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir . '/manifest.json');
        $this->assertFileExists($dir . '/profile.json');

        $rows = $this->snapshot->list();
        $this->assertCount(1, $rows);
        $this->assertSame('before-migrate', $rows[0]['name']);
        $this->assertSame('pre migration', $rows[0]['notes']);
        $this->assertFalse($rows[0]['includes_db']);
    }

    /**
     * @test
     */
    public function it_redacts_secrets_in_env(): void
    {
        file_put_contents($this->fixturePath . '/.env', "APP_NAME=Demo\nDB_PASSWORD=secret\nAPI_TOKEN=abc\n");
        $projectContext = Mockery::mock();
        $projectContext->shouldReceive('fromCwd')->andReturn([
            'site' => 'my-app',
            'url' => 'my-app.test',
            'driver' => false,
            'framework' => 'laravel',
        ]);
        $projectContext->shouldReceive('envPath')->andReturn($this->fixturePath . '/.env');
        swap('Valet\ProjectContext', $projectContext);

        $dir = $this->snapshot->create('env-check');
        $redacted = file_get_contents($dir . '/env.redacted');
        $this->assertIsString($redacted);
        $this->assertStringContainsString('APP_NAME=Demo', $redacted);
        $this->assertStringContainsString('DB_PASSWORD=********', $redacted);
        $this->assertStringContainsString('API_TOKEN=********', $redacted);
        $this->assertStringNotContainsString('secret', $redacted);
    }

    /**
     * @test
     */
    public function it_deletes_snapshot(): void
    {
        $dir = $this->snapshot->create('temp');
        $this->assertDirectoryExists($dir);
        $this->snapshot->delete('temp');
        $this->assertDirectoryDoesNotExist($dir);
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
