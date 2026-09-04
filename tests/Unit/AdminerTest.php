<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\Adminer;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Tests\TestCase;

use function Valet\swap;

class AdminerTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $config;
    private MockInterface $cli;
    private Adminer $adminer;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->config = Mockery::mock(Configuration::class);
        $this->cli = Mockery::mock(CommandLine::class);

        $real = new Filesystem();
        $this->files->shouldReceive('exists')->andReturnUsing(fn (string $path) => $real->exists($path))->byDefault();
        $this->files->shouldReceive('ensureDirExists')->andReturnUsing(function (string $path) use ($real) {
            $real->ensureDirExists($path);
        })->byDefault();
        $this->files->shouldReceive('putAsUser')->andReturnUsing(function (string $path, string $contents) use ($real) {
            return $real->put($path, $contents);
        })->byDefault();
        $this->files->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path))->byDefault();

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;
        /** @var CommandLine $cli */
        $cli = $this->cli;

        $this->adminer = new Adminer($files, $config, $cli);
        $this->config->shouldReceive('get')->with('domain', 'test')->andReturn('test')->byDefault();
    }

    /**
     * @test
     */
    public function it_exposes_database_valet_url_and_plugin_paths(): void
    {
        $this->assertSame('https://database.valet.test', $this->adminer->url());
        $this->assertStringEndsWith('/database/plugins', $this->adminer->pluginsPath());
        $this->assertStringEndsWith('/plugins/enabled.php', $this->adminer->enabledPluginsFile());
    }

    /**
     * @test
     */
    public function ensure_files_writes_index_and_enabled_stub(): void
    {
        \ConsoleComponents\Writer::fake();

        // Pretend downloads already present by planting files before ensure.
        $root = VALET_HOME_PATH . '/database';
        (new Filesystem())->ensureDirExists($root . '/plugins');
        file_put_contents($root . '/adminer.php', '<?php // core');
        file_put_contents($root . '/plugins/plugin.php', '<?php class AdminerPlugin {}');

        $link = Mockery::mock();
        $link->shouldReceive('link')->once()->with($root, 'database.valet')->andReturn(VALET_HOME_PATH . '/Sites/database.valet');
        $link->shouldReceive('unlink')->byDefault();
        swap('Valet\SiteLink', $link);

        $secure = Mockery::mock();
        $secure->shouldReceive('secure')->once()->with('database.valet.test');
        $secure->shouldReceive('unsecure')->byDefault();
        swap('Valet\SiteSecure', $secure);

        $nginx = Mockery::mock();
        $nginx->shouldReceive('restart')->once();
        swap('Valet\Nginx', $nginx);

        $this->adminer->install();

        $this->assertFileExists($root . '/index.php');
        $this->assertFileExists($root . '/plugins/enabled.php');
        $this->assertStringContainsString('adminer_object', (string) file_get_contents($root . '/index.php'));
        $this->assertStringContainsString('AdminerTablesFilter', (string) file_get_contents($root . '/plugins/enabled.php'));
    }

    /**
     * @test
     */
    public function status_path_only_prints_plugins_dir(): void
    {
        \ConsoleComponents\Writer::fake();

        $root = VALET_HOME_PATH . '/database';
        (new Filesystem())->ensureDirExists($root . '/plugins');
        file_put_contents($root . '/adminer.php', '<?php');
        file_put_contents($root . '/plugins/plugin.php', '<?php');
        // Pretend already linked
        @mkdir(VALET_HOME_PATH . '/Sites', 0777, true);
        @symlink($root, VALET_HOME_PATH . '/Sites/database.valet');

        $this->adminer->status(false, true);

        /** @var \Symfony\Component\Console\Output\BufferedOutput $output */
        $output = \ConsoleComponents\Writer::output();
        $this->assertStringContainsString($this->adminer->pluginsPath(), $output->fetch());
    }
}
