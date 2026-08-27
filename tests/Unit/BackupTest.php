<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\Backup;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Mysql;
use Valet\Postgres;
use Valet\Tests\TestCase;

class BackupTest extends TestCase
{
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private Mysql|MockObject $mysql;
    private Postgres|MockObject $postgres;
    private Backup $backup;

    public function setUp(): void
    {
        parent::setUp();

        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->mysql = Mockery::mock(Mysql::class);
        $this->postgres = Mockery::mock(Postgres::class);

        $this->config->shouldReceive('get')->with('domain')->zeroOrMoreTimes()->andReturn('test');

        $this->backup = new Backup(
            $this->commandLine,
            $this->filesystem,
            $this->config,
            $this->mysql,
            $this->postgres
        );
    }

    /**
     * @test
     */
    public function it_creates_backup_archive_with_config_nginx_certs(): void
    {
        Writer::fake();

        $this->filesystem->shouldReceive('ensureDirExists')->zeroOrMoreTimes();
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/config.json')->andReturn(true);
        $this->filesystem->shouldReceive('copy')->once();
        $this->filesystem->shouldReceive('isDir')->zeroOrMoreTimes()->andReturn(true);
        $this->filesystem->shouldReceive('copyDirectory')->twice();
        $this->filesystem->shouldReceive('put')->once();
        $this->filesystem->shouldReceive('remove')->once();

        $this->commandLine->shouldReceive('run')
            ->with(Mockery::pattern('/^tar -czf/'), Mockery::any())
            ->once()
            ->andReturn('');

        $result = $this->backup->backup();

        $this->assertStringStartsWith(Backup::BACKUP_DIR, $result);
        $this->assertStringEndsWith('.tar.gz', $result);

        $output = Writer::output()->fetch();
        $this->assertStringContainsString('Backup created at', $output);
    }

    /**
     * @test
     */
    public function it_includes_db_dumps_when_withDb_true(): void
    {
        Writer::fake();

        $this->filesystem->shouldReceive('ensureDirExists')->zeroOrMoreTimes();
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/config.json')->andReturn(true);
        $this->filesystem->shouldReceive('copy')->zeroOrMoreTimes();
        $this->filesystem->shouldReceive('isDir')->zeroOrMoreTimes()->andReturn(true);
        $this->filesystem->shouldReceive('copyDirectory')->zeroOrMoreTimes();
        $this->filesystem->shouldReceive('unlink')->zeroOrMoreTimes();
        $this->filesystem->shouldReceive('remove')->once();

        $manifestContents = null;
        $this->filesystem->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents) use (&$manifestContents) {
            $manifestContents = $contents;

            return strlen($contents);
        });

        $this->mysql->shouldReceive('getDatabases')->once()->andReturn([['db1'], ['db2']]);
        $this->mysql->shouldReceive('exportDatabase')
            ->with('db1', true)
            ->once()
            ->andReturn(['database' => 'db1', 'filename' => '/tmp/db1-2024.sql']);
        $this->mysql->shouldReceive('exportDatabase')
            ->with('db2', true)
            ->once()
            ->andReturn(['database' => 'db2', 'filename' => '/tmp/db2-2024.sql']);
        $this->filesystem->shouldReceive('exists')->with('/tmp/db1-2024.sql')->andReturn(true);
        $this->filesystem->shouldReceive('exists')->with('/tmp/db2-2024.sql')->andReturn(true);

        $this->postgres->shouldReceive('getDatabases')->once()->andReturn([]);

        $this->commandLine->shouldReceive('run')
            ->with(Mockery::pattern('/^tar -czf/'), Mockery::any())
            ->once()
            ->andReturn('');

        $result = $this->backup->backup(null, true);

        $this->assertStringEndsWith('.tar.gz', $result);

        $manifest = json_decode($manifestContents, true);
        $this->assertTrue($manifest['includes_db']);
        $this->assertContains('db1', $manifest['databases']['mysql']);
        $this->assertContains('db2', $manifest['databases']['mysql']);
    }

    /**
     * @test
     */
    public function it_uses_custom_output_path_when_provided(): void
    {
        Writer::fake();

        $custom = '/tmp/custom-backup.tar.gz';

        $this->filesystem->shouldReceive('ensureDirExists')->zeroOrMoreTimes();
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/config.json')->andReturn(false);
        $this->filesystem->shouldReceive('isDir')->zeroOrMoreTimes()->andReturn(false);
        $this->filesystem->shouldReceive('put')->once();
        $this->filesystem->shouldReceive('remove')->once();

        $this->commandLine->shouldReceive('run')
            ->with(Mockery::pattern('/' . preg_quote($custom, '/') . '/'), Mockery::any())
            ->once()
            ->andReturn('');

        $result = $this->backup->backup($custom, false);

        $this->assertSame($custom, $result);
    }

    /**
     * @test
     */
    public function it_restores_from_archive(): void
    {
        Writer::fake();

        $archive = '/tmp/backup.tar.gz';

        $this->filesystem->shouldReceive('exists')->zeroOrMoreTimes()->andReturnUsing(function ($path) use ($archive) {
            if ($path === $archive) {
                return true;
            }
            if (str_ends_with($path, 'manifest.json')) {
                return false;
            }
            if (str_ends_with($path, 'config.json')) {
                return true;
            }

            return false;
        });

        $this->filesystem->shouldReceive('ensureDirExists')->zeroOrMoreTimes();
        $this->filesystem->shouldReceive('isDir')->zeroOrMoreTimes()->andReturnUsing(function ($path) {
            if (str_ends_with($path, '/Nginx') || str_ends_with($path, '/Certificates')) {
                return true;
            }

            return false;
        });
        $this->filesystem->shouldReceive('scandir')->zeroOrMoreTimes()->andReturnUsing(function ($path) {
            if (str_ends_with($path, '/Nginx')) {
                return ['site1.test'];
            }
            if (str_ends_with($path, '/Certificates')) {
                return ['example.test.crt'];
            }

            return [];
        });
        $this->filesystem->shouldReceive('backup')->with(VALET_HOME_PATH . '/config.json')->once();
        $this->filesystem->shouldReceive('copy')->times(3);
        $this->filesystem->shouldReceive('remove')->once();

        $this->commandLine->shouldReceive('run')
            ->with(Mockery::pattern('/^tar -xzf/'), Mockery::any())
            ->once()
            ->andReturn('');

        $this->backup->restore($archive, true);

        $output = Writer::output()->fetch();
        $this->assertStringContainsString('Restore complete', $output);
    }

    /**
     * @test
     */
    public function it_errors_when_archive_missing(): void
    {
        Writer::fake();

        $archive = '/tmp/does-not-exist.tar.gz';

        $this->filesystem->shouldReceive('exists')->with($archive)->andReturn(false);
        $this->commandLine->shouldReceive('run')->never();
        $this->filesystem->shouldReceive('remove')->never();

        $this->backup->restore($archive, true);

        $output = Writer::output()->fetch();
        $this->assertStringContainsString('not found', $output);
    }
}
