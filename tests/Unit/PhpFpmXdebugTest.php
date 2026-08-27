<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\Site;
use Valet\Tests\TestCase;

class PhpFpmXdebugTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private Site|MockObject $site;
    private Nginx|MockObject $nginx;
    private PhpFpm $phpFpm;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Configuration::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->site = Mockery::mock(Site::class);
        $this->nginx = Mockery::mock(Nginx::class);

        $this->phpFpm = new PhpFpm(
            $this->config,
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->site,
            $this->nginx
        );
    }

    /**
     * @test
     */
    public function itReturnsTrueWhenXdebugIsEnabledViaModules(): void
    {
        Writer::fake();

        $this->commandLine
            ->shouldReceive('run')
            ->andReturnUsing(function (string $command) {
                if (str_contains($command, '-m | grep -i xdebug')) {
                    return 'xdebug';
                }

                return '';
            });

        $this->filesystem->shouldReceive('exists')->andReturnFalse();

        $result = $this->phpFpm->isXdebugEnabled('8.2');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function itReturnsFalseWhenXdebugIsNotEnabled(): void
    {
        Writer::fake();

        $this->commandLine
            ->shouldReceive('run')
            ->andReturnUsing(function (string $command) {
                return '';
            });

        $this->filesystem->shouldReceive('exists')->andReturnFalse();
        $this->filesystem->shouldReceive('get')->andReturn('');

        $result = $this->phpFpm->isXdebugEnabled('8.2');

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function itEnablesXdebugUsingPhpenmod(): void
    {
        Writer::fake();

        $this->commandLine
            ->shouldReceive('run')
            ->andReturnUsing(function (string $command) {
                if (str_contains($command, 'which phpenmod')) {
                    return '/usr/bin/phpenmod';
                }

                // All other checks (modules, conf.d, extension_loaded) report disabled.
                return '';
            });

        // mods-available ini missing -> triggers install attempt.
        $this->filesystem->shouldReceive('exists')->andReturnFalse();

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->andReturn('php8.2-fpm');

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with('php8.2-xdebug');

        $this->commandLine
            ->shouldReceive('run')
            ->with('phpenmod -v 8.2 xdebug', Mockery::type('callable'));

        $this->serviceManager
            ->shouldReceive('restart')
            ->once();

        $result = $this->phpFpm->enableXdebug('8.2');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function itDisablesXdebugUsingPhpdismod(): void
    {
        Writer::fake();

        $this->commandLine
            ->shouldReceive('run')
            ->andReturnUsing(function (string $command) {
                if (str_contains($command, '-m | grep -i xdebug')) {
                    return 'xdebug';
                }

                if (str_contains($command, 'which phpenmod')) {
                    return '/usr/bin/phpenmod';
                }

                return '';
            });

        $this->filesystem->shouldReceive('exists')->andReturnFalse();

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->andReturn('php8.2-fpm');

        $this->commandLine
            ->shouldReceive('run')
            ->with('phpdismod -v 8.2 xdebug', Mockery::type('callable'));

        $this->serviceManager
            ->shouldReceive('restart')
            ->once();

        $result = $this->phpFpm->disableXdebug('8.2');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function itPrintsXdebugStatusTable(): void
    {
        Writer::fake();

        $this->commandLine
            ->shouldReceive('run')
            ->andReturnUsing(function (string $command) {
                if (str_contains($command, '-m | grep -i xdebug')) {
                    return 'xdebug';
                }

                return '';
            });

        $this->filesystem->shouldReceive('exists')->andReturnFalse();

        $this->phpFpm->xdebugStatus('8.2');

        $output = Writer::output()->fetch();
        $this->assertStringContainsString('8.2', $output);
        $this->assertStringContainsString('enabled', $output);
    }
}
