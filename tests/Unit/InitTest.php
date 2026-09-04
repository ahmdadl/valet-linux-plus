<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Init;
use Valet\Tests\TestCase;

use function Valet\swap;

class InitTest extends TestCase
{
    private MockInterface $cli;
    private MockInterface $files;
    private MockInterface $config;
    private Init $init;
    private string $fixturePath;
    private string $originalCwd;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = (string) getcwd();
        $this->fixturePath = sys_get_temp_dir() . '/valet-init-' . uniqid('', true);
        mkdir($this->fixturePath . '/public', 0777, true);
        file_put_contents($this->fixturePath . '/artisan', "#!/usr/bin/env php\n");
        file_put_contents($this->fixturePath . '/public/index.php', "<?php\n");
        file_put_contents($this->fixturePath . '/composer.json', json_encode([
            'require' => ['laravel/framework' => '^11.0'],
        ]));
        chdir($this->fixturePath);

        $this->cli = Mockery::mock(CommandLine::class);
        $this->files = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);

        /** @var CommandLine $cli */
        $cli = $this->cli;
        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;

        $this->init = new Init($cli, $files, $config);

        $site = basename($this->fixturePath);
        $projectContext = Mockery::mock();
        $projectContext->shouldReceive('fromCwd')->andReturn([
            'site' => $site,
            'url' => $site . '.test',
            'driver' => 'Valet\\Drivers\\LaravelValetDriver',
            'framework' => 'laravel',
        ])->byDefault();
        swap('Valet\ProjectContext', $projectContext);

        $this->config->shouldReceive('get')->with('domain', 'test')->andReturn('test')->byDefault();
        $this->config->shouldReceive('get')->with('mysql', [])->andReturn([
            'user' => 'valet',
            'password' => 'secret',
            'host' => '127.0.0.1',
            'port' => '3306',
        ])->byDefault();
        $this->config->shouldReceive('get')->with('pgsql', [])->andReturn([])->byDefault();
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
    public function it_warns_when_no_flags_are_passed(): void
    {
        $this->files->shouldReceive('exists')->with($this->fixturePath . '/.env.example')->andReturn(false);
        $this->files->shouldReceive('exists')->with($this->fixturePath . '/.env')->andReturn(false);

        \ConsoleComponents\Writer::fake();
        $steps = $this->init->run();

        $this->assertSame([], $steps);
    }

    /**
     * @test
     */
    public function it_creates_database_and_writes_env(): void
    {
        $site = basename($this->fixturePath);
        $dbName = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $site) ?? $site);
        $envPath = $this->fixturePath . '/.env';

        $mysql = Mockery::mock();
        $mysql->shouldReceive('isDatabaseExists')->with($dbName)->andReturn(false);
        $mysql->shouldReceive('createDatabase')->with($dbName)->andReturn(true);
        swap('Valet\Mysql', $mysql);

        $environment = Mockery::mock();
        $environment->shouldReceive('ensureAndWrite')
            ->once()
            ->withArgs(function (string $path, array $keys, bool $force) use ($dbName) {
                return $path === $this->fixturePath
                    && $force === false
                    && ($keys['DB_DATABASE'] ?? null) === $dbName
                    && ($keys['DB_USERNAME'] ?? null) === 'valet';
            })
            ->andReturn(['path' => $envPath, 'created' => true, 'updated' => true]);
        swap('Valet\Environment', $environment);

        $this->files->shouldReceive('exists')->andReturn(false)->byDefault();

        \ConsoleComponents\Writer::fake();
        $steps = $this->init->run(db: true);

        $this->assertNotEmpty($steps);
        $this->assertStringContainsString('Created MySQL database', $steps[0]);
        $this->assertStringContainsString('Created', $steps[1]);
    }

    /**
     * @test
     */
    public function it_skips_existing_database_unless_force(): void
    {
        $site = basename($this->fixturePath);
        $dbName = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $site) ?? $site);

        $mysql = Mockery::mock();
        $mysql->shouldReceive('isDatabaseExists')->with($dbName)->andReturn(true);
        $mysql->shouldNotReceive('createDatabase');
        swap('Valet\Mysql', $mysql);

        $environment = Mockery::mock();
        $environment->shouldReceive('ensureAndWrite')->andReturn([
            'path' => $this->fixturePath . '/.env',
            'created' => false,
            'updated' => false,
        ]);
        swap('Valet\Environment', $environment);

        $this->files->shouldReceive('exists')->andReturn(true)->byDefault();

        \ConsoleComponents\Writer::fake();
        $steps = $this->init->run(db: true);

        $this->assertStringContainsString('Skipped existing MySQL database', $steps[0]);
    }

    /**
     * @test
     */
    public function it_runs_composer_install_when_requested(): void
    {
        $this->files->shouldReceive('exists')->with($this->fixturePath . '/.env.example')->andReturn(false);
        $this->files->shouldReceive('exists')->with($this->fixturePath . '/.env')->andReturn(false);
        $this->files->shouldReceive('exists')->with($this->fixturePath . '/composer.json')->andReturn(true);

        $siteIsolate = Mockery::mock();
        $siteIsolate->shouldReceive('isolatedPhpVersion')->andReturn(null);
        swap('Valet\SiteIsolate', $siteIsolate);

        $phpFpm = Mockery::mock();
        $phpFpm->shouldReceive('getPhpExecutablePath')->with(null)->andReturn('/usr/bin/php');
        swap('Valet\PhpFpm', $phpFpm);

        $this->cli->shouldReceive('run')->with('command -v composer 2>/dev/null')->andReturn('/usr/bin/composer');
        $this->cli->shouldReceive('runAsUser')
            ->once()
            ->withArgs(function (string $command) {
                return str_contains($command, 'cd ' . escapeshellarg($this->fixturePath))
                    && str_contains($command, 'composer')
                    && str_contains($command, 'install');
            })
            ->andReturn('');

        \ConsoleComponents\Writer::fake();
        $steps = $this->init->run(composer: true);

        $this->assertContains('Ran composer install', $steps);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
