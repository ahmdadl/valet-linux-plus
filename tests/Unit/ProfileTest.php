<?php

namespace Valet\Tests\Unit;

use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Profile;
use Valet\Tests\TestCase;

use function Valet\swap;

class ProfileTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $config;
    private Profile $profile;
    private string $fixturePath;
    private string $originalCwd;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = (string) getcwd();
        $this->fixturePath = sys_get_temp_dir() . '/valet-profile-' . uniqid('', true);
        mkdir($this->fixturePath . '/.valet', 0777, true);
        chdir($this->fixturePath);

        $this->files = Mockery::mock(Filesystem::class)->makePartial();
        $this->config = Mockery::mock(Configuration::class);

        // Use a real filesystem for file IO in most tests via partial + real methods.
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
        $this->files->shouldReceive('unlink')->andReturnUsing(function (string $path) use ($real) {
            $real->unlink($path);
        })->byDefault();
        $this->files->shouldReceive('scandir')->andReturnUsing(fn (string $path) => $real->scandir($path))->byDefault();

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var Configuration $config */
        $config = $this->config;

        $this->profile = new Profile($files, $config);

        $this->config->shouldReceive('get')->with('php_version')->andReturn('8.3')->byDefault();

        $projectContext = Mockery::mock();
        $projectContext->shouldReceive('fromCwd')->andReturn([
            'site' => 'my-app',
            'url' => 'my-app.test',
            'driver' => false,
            'framework' => 'laravel',
        ])->byDefault();
        swap('Valet\ProjectContext', $projectContext);

        $siteIsolate = Mockery::mock();
        $siteIsolate->shouldReceive('isolatedPhpVersion')->andReturn('8.3')->byDefault();
        swap('Valet\SiteIsolate', $siteIsolate);

        $siteSecure = Mockery::mock();
        $siteSecure->shouldReceive('secured')->andReturn(collect(['my-app.test']))->byDefault();
        swap('Valet\SiteSecure', $siteSecure);
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
    public function it_validates_profile_schema(): void
    {
        $this->profile->validate([
            'php' => '8.3',
            'secure' => true,
            'database' => ['driver' => 'mysql', 'name' => 'my_app'],
            'services' => ['redis'],
            'isolate' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->profile->validate(['php' => 8]);
    }

    /**
     * @test
     */
    public function it_saves_and_shows_project_profile(): void
    {
        $result = $this->profile->save(null, [
            'php' => '8.3',
            'secure' => true,
            'isolate' => true,
        ]);

        $this->assertFileExists($result['path']);
        $this->assertSame($this->fixturePath . '/.valet/profile.json', $result['path']);

        $shown = $this->profile->show();
        $this->assertSame('8.3', $shown['php']);
        $this->assertTrue($shown['secure']);
    }

    /**
     * @test
     */
    public function it_saves_global_template_and_lists_it(): void
    {
        // Point global dir into the fixture by writing via real VALET_HOME_PATH (test config).
        $result = $this->profile->save('laravel-app', [
            'php' => '8.3',
            'secure' => true,
            'services' => ['redis', 'mailpit'],
            'database' => ['driver' => 'mysql', 'name' => 'laravel_app'],
            'isolate' => true,
        ]);

        $this->assertStringContainsString('/profiles/laravel-app.json', $result['path']);
        $this->assertFileExists($this->fixturePath . '/.valet/profile.json');

        $rows = $this->profile->listProfiles();
        $names = array_column($rows, 'name');
        $this->assertContains('(project)', $names);
        $this->assertContains('laravel-app', $names);
    }

    /**
     * @test
     */
    public function it_uses_global_profile_and_applies(): void
    {
        $this->profile->save('demo', [
            'php' => '8.2',
            'secure' => true,
            'isolate' => true,
            'database' => ['driver' => 'mysql', 'name' => 'demo_db'],
            'services' => ['redis'],
        ]);

        $siteIsolate = Mockery::mock();
        $siteIsolate->shouldReceive('isolateDirectory')->once()->with('my-app', '8.2', true)->andReturn(true);
        swap('Valet\SiteIsolate', $siteIsolate);

        $mysql = Mockery::mock();
        $mysql->shouldReceive('isDatabaseExists')->with('demo_db')->andReturn(false);
        $mysql->shouldReceive('createDatabase')->with('demo_db')->andReturn(true);
        swap('Valet\Mysql', $mysql);

        $registry = Mockery::mock();
        $registry->shouldReceive('start')->once()->with(['redis']);
        swap('Valet\ServiceRegistry', $registry);

        \ConsoleComponents\Writer::fake();
        $result = $this->profile->use('demo', true);

        $this->assertFileExists($this->fixturePath . '/.valet/profile.json');
        $this->assertNotEmpty($result['steps']);
        $this->assertStringContainsString('Isolated', $result['steps'][0]);
    }

    /**
     * @test
     */
    public function it_deletes_global_profile(): void
    {
        $this->profile->save('temp', ['php' => '8.3']);
        $path = $this->profile->globalPath('temp');
        $this->assertFileExists($path);

        $deleted = $this->profile->delete('temp');
        $this->assertSame($path, $deleted);
        $this->assertFileDoesNotExist($path);
    }

    /**
     * @test
     */
    public function it_captures_current_project_settings(): void
    {
        $data = $this->profile->captureCurrent();

        $this->assertSame('8.3', $data['php']);
        $this->assertTrue($data['secure']);
        $this->assertSame('mysql', $data['database']['driver']);
        $this->assertSame('my_app', $data['database']['name']);
        $this->assertTrue($data['isolate']);
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
