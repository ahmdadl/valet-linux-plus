<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\Configuration;
use Valet\Environment;
use Valet\Filesystem;
use Valet\Tests\TestCase;

use function Valet\swap;

class EnvironmentTest extends TestCase
{
    private MockInterface $config;
    private MockInterface $files;
    private Environment $environment;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Configuration::class);
        $this->files = Mockery::mock(Filesystem::class);

        /** @var Configuration $config */
        $config = $this->config;
        /** @var Filesystem $files */
        $files = $this->files;

        $this->environment = new Environment($config, $files);

        $projectContext = Mockery::mock();
        $projectContext->shouldReceive('fromCwd')->andReturn([
            'site' => 'my-app',
            'url' => 'my-app.test',
            'driver' => 'Valet\\Drivers\\LaravelValetDriver',
            'framework' => 'laravel',
        ])->byDefault();
        $projectContext->shouldReceive('envPath')->andReturn(false)->byDefault();
        swap('Valet\ProjectContext', $projectContext);

        $siteSecure = Mockery::mock();
        $siteSecure->shouldReceive('secured')->andReturn(collect(['my-app.test']))->byDefault();
        swap('Valet\SiteSecure', $siteSecure);

        $siteIsolate = Mockery::mock();
        $siteIsolate->shouldReceive('isolatedPhpVersion')->andReturn(null)->byDefault();
        swap('Valet\SiteIsolate', $siteIsolate);
    }

    /**
     * @test
     */
    public function it_gathers_defaults_from_valet_config(): void
    {
        $this->config
            ->shouldReceive('get')
            ->andReturnUsing(function (string $key, $default = null) {
                return match ($key) {
                    'domain' => 'test',
                    'mysql' => [
                        'user' => 'valet',
                        'password' => 'secret',
                        'host' => '127.0.0.1',
                        'port' => '3306',
                    ],
                    'php_version' => '8.3',
                    'port' => 80,
                    default => $default,
                };
            });

        $vars = $this->environment->gather();

        $this->assertSame('https://my-app.test', $vars['SITE_URL']);
        $this->assertSame('8.3', $vars['PHP_VERSION']);
        $this->assertSame('mysql', $vars['DB_CONNECTION']);
        $this->assertSame('127.0.0.1', $vars['DB_HOST']);
        $this->assertSame('3306', $vars['DB_PORT']);
        $this->assertSame('my_app', $vars['DB_DATABASE']);
        $this->assertSame('valet', $vars['DB_USERNAME']);
        $this->assertSame('secret', $vars['DB_PASSWORD']);
        $this->assertSame('redis://127.0.0.1:6379', $vars['REDIS_URL']);
        $this->assertSame('https://mails.test', $vars['MAIL_URL']);
    }

    /**
     * @test
     */
    public function it_merges_project_dotenv_over_defaults(): void
    {
        $envPath = '/tmp/valet-test.env';

        $projectContext = Mockery::mock();
        $projectContext->shouldReceive('fromCwd')->andReturn([
            'site' => 'my-app',
            'url' => 'my-app.test',
            'driver' => false,
            'framework' => 'laravel',
        ]);
        $projectContext->shouldReceive('envPath')->andReturn($envPath);
        swap('Valet\ProjectContext', $projectContext);

        $this->config
            ->shouldReceive('get')
            ->andReturnUsing(function (string $key, $default = null) {
                return match ($key) {
                    'domain' => 'test',
                    'mysql' => ['user' => 'valet', 'password' => 'secret'],
                    'php_version' => '8.3',
                    default => $default,
                };
            });

        $this->files
            ->shouldReceive('exists')
            ->with($envPath)
            ->andReturn(true);
        $this->files
            ->shouldReceive('get')
            ->with($envPath)
            ->andReturn("DB_DATABASE=my_app\nDB_USERNAME=appuser\n");

        $vars = $this->environment->gather();

        $this->assertSame('my_app', $vars['DB_DATABASE']);
        $this->assertSame('appuser', $vars['DB_USERNAME']);
        $this->assertSame('secret', $vars['DB_PASSWORD']);
    }

    /**
     * @test
     */
    public function it_exports_shell_and_dotenv_formats(): void
    {
        $vars = [
            'SITE_URL' => 'https://app.test',
            'DB_PASSWORD' => "p'ass",
        ];

        $dotenv = $this->environment->export($vars, 'dotenv');
        $this->assertStringContainsString('SITE_URL=https://app.test', $dotenv);
        $this->assertStringContainsString("DB_PASSWORD=p'ass", $dotenv);

        $shell = $this->environment->export($vars, 'shell');
        $this->assertStringContainsString("export SITE_URL='https://app.test'", $shell);
        $this->assertStringContainsString('export DB_PASSWORD=', $shell);
    }

    /**
     * @test
     */
    public function it_prints_database_url(): void
    {
        $this->config
            ->shouldReceive('get')
            ->andReturnUsing(function (string $key, $default = null) {
                return match ($key) {
                    'domain' => 'test',
                    'mysql' => [
                        'user' => 'valet',
                        'password' => 'secret',
                        'host' => '127.0.0.1',
                        'port' => '3306',
                    ],
                    'php_version' => '8.3',
                    default => $default,
                };
            });

        $url = $this->environment->databaseUrl();

        $this->assertSame('mysql://valet:secret@127.0.0.1:3306/my_app', $url);
    }

    /**
     * @test
     */
    public function it_outputs_json_envelope(): void
    {
        $this->config
            ->shouldReceive('get')
            ->andReturnUsing(function (string $key, $default = null) {
                return match ($key) {
                    'domain' => 'test',
                    'mysql' => ['user' => 'valet', 'password' => ''],
                    'php_version' => '8.3',
                    default => $default,
                };
            });

        \ConsoleComponents\Writer::fake();
        $this->environment->run(true);

        /** @var BufferedOutput $writerOutput */
        $writerOutput = \ConsoleComponents\Writer::output();
        $output = $writerOutput->fetch();

        $this->assertStringContainsString('schema_version', $output);
        $this->assertStringContainsString('schema_command', $output);
        $this->assertStringContainsString('SITE_URL', $output);
    }
}
