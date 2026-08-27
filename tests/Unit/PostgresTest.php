<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\PhpFpm;
use Valet\Postgres;
use Valet\Tests\TestCase;

use function Valet\swap;

class PostgresTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private Postgres $postgres;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('postgres')
            ->zeroOrMoreTimes()
            ->andReturn('postgresql-server');

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('postgresql')
            ->zeroOrMoreTimes()
            ->andReturn('postgresql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('postgresql-server')
            ->zeroOrMoreTimes()
            ->andReturnFalse();

        $this->postgres = new Postgres(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );
    }

    /**
     * @test
     */
    public function itWillInstallSuccessfully(): void
    {
        Writer::fake();
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);

        if (!extension_loaded('pdo_pgsql')) {
            $phpFpm->shouldReceive('getCurrentVersion')->once()->andReturn('8.2');
        } else {
            $phpFpm->shouldReceive('getCurrentVersion')->zeroOrMoreTimes()->andReturn('8.2');
        }

        if (!extension_loaded('pdo_pgsql')) {
            $this->packageManager
                ->shouldReceive('ensureInstalled')
                ->with('php8.2-pgsql')
                ->once();
        }

        $this->packageManager
            ->shouldReceive('installed')
            ->with('postgresql-server')
            ->zeroOrMoreTimes()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('installOrFail')
            ->with('postgresql-server')
            ->once()
            ->andReturnFalse();

        $this->serviceManager
            ->shouldReceive('enable')
            ->with('postgresql-server')
            ->once()
            ->andReturnFalse();

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturn('');

        $this->config
            ->shouldReceive('get')
            ->with('pgsql', [])
            ->once()
            ->andReturn([]);

        $this->config
            ->shouldReceive('set')
            ->with('pgsql', ['user' => 'valet', 'password' => ''])
            ->once()
            ->andReturn([]);

        $this->postgres->install();
    }

    /**
     * @test
     */
    public function itWillStopService(): void
    {
        $this->serviceManager
            ->shouldReceive('stop')
            ->with('postgresql-server')
            ->once()
            ->andReturnTrue();

        $this->postgres->stop();
    }

    /**
     * @test
     */
    public function itWillRestartService(): void
    {
        $this->serviceManager
            ->shouldReceive('restart')
            ->with('postgresql-server')
            ->once()
            ->andReturnTrue();

        $this->postgres->restart();
    }

    /**
     * @test
     */
    public function itWillUninstallService(): void
    {
        $this->serviceManager
            ->shouldReceive('stop')
            ->with('postgresql-server')
            ->once()
            ->andReturnTrue();

        $this->postgres->uninstall();
    }

    /**
     * @test
     */
    public function itCanCreateDatabase(): void
    {
        $pdo = $this->mockPdoConnection();

        $stmt = Mockery::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->andReturn(true);
        $stmt->shouldReceive('fetch')->andReturn(false); // database does not exist yet
        $pdo->shouldReceive('prepare')->andReturn($stmt);
        $pdo->shouldReceive('query')->andReturn(Mockery::mock(\PDOStatement::class)); // CREATE DATABASE succeeds

        $this->assertTrue($this->postgres->createDatabase('mydb'));
    }

    /**
     * @test
     */
    public function itWillNotCreateExistingDatabase(): void
    {
        $pdo = $this->mockPdoConnection();

        $stmt = Mockery::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->andReturn(true);
        $stmt->shouldReceive('fetch')->andReturn(['datname' => 'mydb']); // already exists
        $pdo->shouldReceive('prepare')->andReturn($stmt);

        $this->assertFalse($this->postgres->createDatabase('mydb'));
    }

    /**
     * @test
     */
    public function itCanDropDatabase(): void
    {
        $pdo = $this->mockPdoConnection();

        $stmt = Mockery::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->andReturn(true);
        $stmt->shouldReceive('fetch')->andReturn(['datname' => 'mydb']); // exists
        $pdo->shouldReceive('prepare')->andReturn($stmt);
        $pdo->shouldReceive('query')->andReturn(Mockery::mock(\PDOStatement::class)); // DROP DATABASE succeeds

        $this->assertTrue($this->postgres->dropDatabase('mydb'));
    }

    /**
     * @test
     */
    public function itCanGetDatabases(): void
    {
        $pdo = $this->mockPdoConnection();

        $stmt = Mockery::mock(\PDOStatement::class);
        $stmt->shouldReceive('fetchAll')->with(PDO::FETCH_ASSOC)->andReturn([
            ['datname' => 'postgres'],
            ['datname' => 'mydb'],
            ['datname' => 'template1'],
        ]);
        $pdo->shouldReceive('query')->with('SELECT datname FROM pg_database')->andReturn($stmt);

        $this->assertEquals([['mydb']], $this->postgres->getDatabases());
    }

    /**
     * Inject a mocked PDO connection so database operations don't hit a real server.
     */
    private function mockPdoConnection(): Mockery\MockInterface
    {
        $pdo = Mockery::mock(PDO::class);
        $ref = new \ReflectionProperty(Postgres::class, 'pdoConnection');
        $ref->setAccessible(true);
        $ref->setValue($this->postgres, $pdo);

        return $pdo;
    }
}
