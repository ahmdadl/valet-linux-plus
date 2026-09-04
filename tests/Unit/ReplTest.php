<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Repl;
use Valet\Tests\TestCase;

class ReplTest extends TestCase
{
    private MockInterface $cli;
    private MockInterface $files;
    private MockInterface $config;
    private Repl $repl;
    private string $fixturePath;
    private string $originalCwd;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = (string) getcwd();
        $this->fixturePath = sys_get_temp_dir() . '/valet-repl-' . uniqid('', true);
        mkdir($this->fixturePath, 0777, true);
        chdir($this->fixturePath);

        $this->cli = Mockery::mock(CommandLine::class);
        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->config = Mockery::mock(Configuration::class);

        $real = new Filesystem();
        $this->files->shouldReceive('exists')->andReturnUsing(fn (string $path) => $real->exists($path))->byDefault();
        $this->files->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path))->byDefault();

        /** @var CommandLine $cli */
        $cli = $this->cli;
        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;

        $this->repl = new Repl($cli, $files, $config);
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
    public function it_resolves_laravel_tinker_via_driver(): void
    {
        file_put_contents($this->fixturePath . '/artisan', "#!/usr/bin/env php\n");
        mkdir($this->fixturePath . '/public', 0777, true);
        file_put_contents($this->fixturePath . '/public/index.php', "<?php\n");
        file_put_contents($this->fixturePath . '/composer.json', json_encode([
            'require' => ['laravel/framework' => '^11.0'],
            'require-dev' => ['laravel/tinker' => '^2.0'],
        ]));

        $this->assertSame(['artisan', 'tinker'], $this->repl->resolveArgv($this->fixturePath));
    }

    /**
     * @test
     */
    public function it_resolves_symfony_console_with_psysh_when_present(): void
    {
        mkdir($this->fixturePath . '/bin', 0777, true);
        mkdir($this->fixturePath . '/vendor/bin', 0777, true);
        file_put_contents($this->fixturePath . '/bin/console', "#!/usr/bin/env php\n");
        file_put_contents($this->fixturePath . '/vendor/bin/psysh', "#!/usr/bin/env php\n");

        $this->assertSame(['bin/console', 'psysh'], $this->repl->resolveArgv($this->fixturePath));
    }

    /**
     * @test
     */
    public function it_falls_back_to_php_interactive_shell(): void
    {
        $this->assertSame(['-a'], $this->repl->resolveArgv($this->fixturePath));
    }

    /**
     * @test
     */
    public function it_uses_vendor_psysh_when_available(): void
    {
        mkdir($this->fixturePath . '/vendor/bin', 0777, true);
        file_put_contents($this->fixturePath . '/vendor/bin/psysh', "#!/usr/bin/env php\n");

        $this->assertSame(['vendor/bin/psysh'], $this->repl->resolveArgv($this->fixturePath));
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
