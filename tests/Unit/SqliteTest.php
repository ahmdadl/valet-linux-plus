<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Sqlite;
use Valet\Tests\TestCase;

use function Valet\swap;

class SqliteTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $config;
    private Sqlite $sqlite;
    private string $fixturePath;
    private string $originalCwd;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = (string) getcwd();
        $this->fixturePath = sys_get_temp_dir() . '/valet-sqlite-' . uniqid('', true);
        mkdir($this->fixturePath, 0777, true);
        chdir($this->fixturePath);

        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->config = Mockery::mock(Configuration::class);

        $real = new Filesystem();
        $this->files->shouldReceive('exists')->andReturnUsing(fn (string $path) => $real->exists($path))->byDefault();
        $this->files->shouldReceive('isDir')->andReturnUsing(fn (string $path) => $real->isDir($path))->byDefault();
        $this->files->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path))->byDefault();
        $this->files->shouldReceive('mkdirAsUser')->andReturnUsing(function (string $path) use ($real) {
            $real->mkdir($path);
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

        $this->sqlite = new Sqlite($files, $config);

        $projectContext = Mockery::mock();
        $projectContext->shouldReceive('fromCwd')->andReturn([
            'site' => basename($this->fixturePath),
            'url' => basename($this->fixturePath) . '.test',
            'driver' => false,
            'framework' => false,
        ])->byDefault();
        swap('Valet\ProjectContext', $projectContext);
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
    public function it_creates_default_sqlite_file(): void
    {
        \ConsoleComponents\Writer::fake();
        $steps = $this->sqlite->create();

        $expected = $this->fixturePath . '/database/database.sqlite';
        $this->assertFileExists($expected);
        $this->assertTrue(collect($steps)->contains(fn ($s) => str_contains((string) $s, $expected)));
    }

    /**
     * @test
     */
    public function it_writes_env_when_requested(): void
    {
        \ConsoleComponents\Writer::fake();

        $env = Mockery::mock();
        $env->shouldReceive('ensureAndWrite')
            ->once()
            ->withArgs(function (string $sitePath, array $keys, bool $force) {
                return $sitePath === $this->fixturePath
                    && ($keys['DB_CONNECTION'] ?? null) === 'sqlite'
                    && str_ends_with((string) ($keys['DB_DATABASE'] ?? ''), '/database/database.sqlite')
                    && $force === true;
            })
            ->andReturn(['path' => $this->fixturePath . '/.env', 'created' => true, 'updated' => false]);
        swap('Valet\Environment', $env);

        $steps = $this->sqlite->create(null, null, true);
        $this->assertTrue(collect($steps)->contains(fn ($s) => str_contains((string) $s, '.env')));
    }

    /**
     * @test
     */
    public function it_resets_sqlite_file(): void
    {
        \ConsoleComponents\Writer::fake();
        $path = $this->fixturePath . '/database/database.sqlite';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'old');

        $steps = $this->sqlite->reset(true);
        $this->assertFileExists($path);
        $this->assertSame('', file_get_contents($path));
        $this->assertSame([sprintf('Recreated SQLite database [%s]', $path)], $steps);
    }

    /**
     * @test
     */
    public function it_resolves_path_from_env(): void
    {
        file_put_contents($this->fixturePath . '/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/tmp/custom.sqlite\n");
        $this->assertSame('/tmp/custom.sqlite', $this->sqlite->resolveDatabasePath($this->fixturePath));
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
