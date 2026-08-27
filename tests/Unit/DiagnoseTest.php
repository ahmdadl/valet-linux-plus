<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Diagnose;
use Valet\Filesystem;
use Valet\Tests\TestCase;

class DiagnoseTest extends TestCase
{
    private MockInterface $commandLine;
    private MockInterface $filesystem;
    private MockInterface $config;
    private Diagnose $diagnose;

    public function setUp(): void
    {
        parent::setUp();

        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);

        /** @var CommandLine $commandLine */
        $commandLine = $this->commandLine;
        /** @var Filesystem $filesystem */
        $filesystem = $this->filesystem;
        /** @var Configuration $config */
        $config = $this->config;

        $this->diagnose = new Diagnose($commandLine, $filesystem, $config);
    }

    /**
     * @test
     */
    public function it_outputs_diagnose_in_human_readable_format(): void
    {
        $this->mockGatherDependencies();

        \ConsoleComponents\Writer::fake();

        $this->diagnose->run(false);

        /** @var BufferedOutput $writerOutput */
        $writerOutput = \ConsoleComponents\Writer::output();
        $output = $writerOutput->fetch();

        $this->assertStringContainsString('Valet Diagnose', $output);
        $this->assertStringContainsString('OS', $output);
        $this->assertStringContainsString('Kernel', $output);
        $this->assertStringContainsString('Package Manager', $output);
        $this->assertStringContainsString('Service Manager', $output);
        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString('Configured PHP', $output);
        $this->assertStringContainsString('Supported PHP', $output);
        $this->assertStringContainsString('Isolated Sites', $output);
        $this->assertStringContainsString('Nginx', $output);
        $this->assertStringContainsString('nginx -t', $output);
        $this->assertStringContainsString('Domain', $output);
        $this->assertStringContainsString('DnsMasq', $output);
        $this->assertStringContainsString('Services', $output);
        $this->assertStringContainsString('nginx', $output);
        $this->assertStringContainsString('php', $output);
        $this->assertStringContainsString('mailpit', $output);
        $this->assertStringContainsString('dnsmasq', $output);
        $this->assertStringContainsString('mysql', $output);
        $this->assertStringContainsString('redis', $output);
        $this->assertStringContainsString('Paths', $output);
        $this->assertStringContainsString('Links', $output);
        $this->assertStringContainsString('Proxies', $output);
        $this->assertStringContainsString('Secured', $output);
        $this->assertStringContainsString('Version', $output);
    }

    /**
     * @test
     */
    public function it_outputs_diagnose_as_json(): void
    {
        $this->mockGatherDependencies();

        \ConsoleComponents\Writer::fake();

        $this->diagnose->run(true);

        /** @var BufferedOutput $writerOutput */
        $writerOutput = \ConsoleComponents\Writer::output();
        $output = $writerOutput->fetch();

        // The JSON keys should be present in the output. Note: the Writer info
        // component HTML-escapes content, so we assert on the plain key names
        // rather than decoding the (escaped) JSON.
        $this->assertStringContainsString('os', $output);
        $this->assertStringContainsString('package_manager', $output);
        $this->assertStringContainsString('service_manager', $output);
        $this->assertStringContainsString('php', $output);
        $this->assertStringContainsString('nginx', $output);
        $this->assertStringContainsString('dns', $output);
        $this->assertStringContainsString('services', $output);
        $this->assertStringContainsString('paths', $output);
        $this->assertStringContainsString('valet_version', $output);
    }

    /**
     * @test
     */
    public function it_returns_a_diagnostic_data_array(): void
    {
        $this->mockGatherDependencies();

        /** @var array<string, array<string, mixed>> $data */
        $data = $this->diagnose->gather();

        $this->assertArrayHasKey('os', $data);
        $this->assertArrayHasKey('php', $data);
        $this->assertArrayHasKey('nginx', $data);
        $this->assertArrayHasKey('dns', $data);
        $this->assertArrayHasKey('services', $data);
        $this->assertArrayHasKey('paths', $data);
        $this->assertArrayHasKey('valet_version', $data);

        // Values sourced from the mocked Configuration / Filesystem / CLI.
        $this->assertSame('8.3', $data['php']['Configured PHP']);
        $this->assertSame('test', $data['dns']['Domain']);
        $this->assertSame('1.2.3', $data['valet_version']);

        // Facade-derived counts are best-effort and fall back to 0 when the
        // underlying services are unavailable (e.g. in the test environment).
        $this->assertIsInt($data['paths']['Links']);
        $this->assertIsInt($data['paths']['Proxies']);
        $this->assertIsInt($data['paths']['Secured']);
    }

    /**
     * Set up the mocked dependencies to return canned values.
     */
    private function mockGatherDependencies(): void
    {
        $this->commandLine
            ->shouldReceive('run')
            ->andReturnUsing(function (string $command) {
                if (str_contains($command, 'os-release')) {
                    return 'ID=ubuntu' . PHP_EOL . 'VERSION="22.04"';
                }
                if (str_contains($command, 'uname -r')) {
                    return '5.15.0-valet';
                }
                if (str_contains($command, 'nginx -t')) {
                    return 'nginx: configuration file /etc/nginx/nginx.conf test is successful';
                }
                if (str_contains($command, 'systemctl is-active')) {
                    return 'active';
                }

                return '';
            })
            ->zeroOrMoreTimes();

        $this->filesystem
            ->shouldReceive('scandir')
            ->with(VALET_HOME_PATH . '/Nginx')
            ->andReturn(['site1.test', 'site2.test'])
            ->zeroOrMoreTimes();

        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH . '/composer.json')
            ->andReturn(json_encode(['name' => 'ahmdadl/valet-linux-plus', 'version' => '1.2.3']))
            ->zeroOrMoreTimes();

        $this->config
            ->shouldReceive('get')
            ->andReturnUsing(function (string $key, $default = null) {
                return match ($key) {
                    'php_version' => '8.3',
                    'domain' => 'test',
                    'paths' => ['/home/user/code'],
                    default => $default,
                };
            })
            ->zeroOrMoreTimes();
    }
}
