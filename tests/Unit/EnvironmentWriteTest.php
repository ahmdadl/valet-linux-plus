<?php

namespace Valet\Tests\Unit;

use Valet\Configuration;
use Valet\Environment;
use Valet\Filesystem;
use Valet\Tests\TestCase;

class EnvironmentWriteTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_env_from_example_and_upserts_keys(): void
    {
        $dir = sys_get_temp_dir() . '/valet-envwrite-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/.env.example', "APP_NAME=Example\nDB_DATABASE=old\n");

        $files = new Filesystem();
        $config = \Mockery::mock(Configuration::class);
        /** @var Configuration $configInstance */
        $configInstance = $config;
        $environment = new Environment($configInstance, $files);

        $result = $environment->ensureAndWrite($dir, [
            'DB_DATABASE' => 'my_app',
            'DB_USERNAME' => 'valet',
        ], true);

        $this->assertTrue($result['created']);
        $this->assertTrue($result['updated']);
        $contents = file_get_contents($dir . '/.env');
        $this->assertIsString($contents);
        $this->assertStringContainsString('APP_NAME=Example', $contents);
        $this->assertStringContainsString('DB_DATABASE=my_app', $contents);
        $this->assertStringContainsString('DB_USERNAME=valet', $contents);

        unlink($dir . '/.env');
        unlink($dir . '/.env.example');
        rmdir($dir);
    }
}
