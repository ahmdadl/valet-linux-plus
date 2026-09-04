<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\DatabaseSetup;
use Valet\Filesystem;
use Valet\Tests\TestCase;

use function Valet\swap;

class DatabaseSetupTest extends TestCase
{
    private MockInterface $cli;
    private MockInterface $files;
    private MockInterface $config;
    private DatabaseSetup $setup;
    private string $fixturePath;
    private string $originalCwd;
    private string $dbName;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = (string) getcwd();
        $this->fixturePath = sys_get_temp_dir() . '/valet-dbsetup-' . uniqid('', true);
        mkdir($this->fixturePath . '/public', 0777, true);
        file_put_contents($this->fixturePath . '/artisan', "#!/usr/bin/env php\n");
        file_put_contents($this->fixturePath . '/public/index.php', "<?php\n");
        chdir($this->fixturePath);

        $site = basename($this->fixturePath);
        $this->dbName = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $site) ?? $site);

        $this->cli = Mockery::mock(CommandLine::class);
        $this->files = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);

        /** @var CommandLine $cli */
        $cli = $this->cli;
        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;

        $this->setup = new DatabaseSetup($cli, $files, $config);

        $projectContext = Mockery::mock();
        $projectContext->shouldReceive('fromCwd')->andReturn([
            'site' => $site,
            'url' => $site . '.test',
            'driver' => 'Valet\\Drivers\\LaravelValetDriver',
            'framework' => 'laravel',
        ])->byDefault();
        swap('Valet\ProjectContext', $projectContext);

        $this->config->shouldReceive('get')->with('mysql', [])->andReturn([
            'user' => 'valet',
            'password' => 'secret',
            'host' => '127.0.0.1',
            'port' => '3306',
        ])->byDefault();
        $this->config->shouldReceive('get')->with('pgsql', [])->andReturn([])->byDefault();

        $siteIsolate = Mockery::mock();
        $siteIsolate->shouldReceive('isolatedPhpVersion')->andReturn(null)->byDefault();
        swap('Valet\SiteIsolate', $siteIsolate);

        $phpFpm = Mockery::mock();
        $phpFpm->shouldReceive('getPhpExecutablePath')->andReturn('/usr/bin/php')->byDefault();
        swap('Valet\PhpFpm', $phpFpm);
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
    public function it_sets_up_database_env_and_migrate(): void
    {
        $mysql = Mockery::mock();
        $mysql->shouldReceive('isDatabaseExists')->with($this->dbName)->andReturn(false);
        $mysql->shouldReceive('createDatabase')->with($this->dbName)->andReturn(true);
        swap('Valet\Mysql', $mysql);

        $environment = Mockery::mock();
        $environment->shouldReceive('ensureAndWrite')->once()->andReturn([
            'path' => $this->fixturePath . '/.env',
            'created' => true,
            'updated' => true,
        ]);
        swap('Valet\Environment', $environment);

        $this->cli->shouldReceive('runAsUser')
            ->once()
            ->withArgs(function (string $command) {
                return str_contains($command, 'artisan') && str_contains($command, 'migrate');
            })
            ->andReturn('');

        \ConsoleComponents\Writer::fake();
        $steps = $this->setup->setup();

        $this->assertStringContainsString('Created MySQL database', $steps[0]);
        $this->assertStringContainsString('Created', $steps[1]);
        $this->assertContains('Ran migrate', $steps);
    }

    /**
     * @test
     */
    public function it_refreshes_database_with_seed(): void
    {
        $mysql = Mockery::mock();
        $mysql->shouldReceive('isDatabaseExists')->with($this->dbName)->andReturn(true);
        $mysql->shouldReceive('dropDatabase')->with($this->dbName)->andReturn(true);
        $mysql->shouldReceive('createDatabase')->with($this->dbName)->andReturn(true);
        swap('Valet\Mysql', $mysql);

        $environment = Mockery::mock();
        $environment->shouldReceive('ensureAndWrite')->once()->andReturn([
            'path' => $this->fixturePath . '/.env',
            'created' => false,
            'updated' => true,
        ]);
        swap('Valet\Environment', $environment);

        $this->cli->shouldReceive('runAsUser')
            ->twice()
            ->andReturn('');

        \ConsoleComponents\Writer::fake();
        $steps = $this->setup->refresh(seed: true, yes: true);

        $this->assertStringContainsString('Reset MySQL database', $steps[0]);
        $this->assertContains('Synced .env database keys', $steps);
        $this->assertContains('Ran migrate', $steps);
        $this->assertContains('Ran seed', $steps);
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
