<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\ServiceManager;
use Valet\Diagnose;
use Valet\Doctor;
use Valet\Filesystem;
use Valet\Tests\TestCase;

use function Valet\swap;

class DoctorTest extends TestCase
{
    private MockInterface $diagnose;
    private MockInterface $commandLine;
    private MockInterface $filesystem;
    private MockInterface $config;
    private MockInterface $serviceManager;
    private Doctor $doctor;

    public function setUp(): void
    {
        parent::setUp();

        $this->diagnose = Mockery::mock(Diagnose::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);

        /** @var Diagnose $diagnose */
        $diagnose = $this->diagnose;
        /** @var CommandLine $commandLine */
        $commandLine = $this->commandLine;
        /** @var Filesystem $filesystem */
        $filesystem = $this->filesystem;
        /** @var Configuration $config */
        $config = $this->config;
        /** @var ServiceManager $serviceManager */
        $serviceManager = $this->serviceManager;

        $this->doctor = new Doctor($diagnose, $commandLine, $filesystem, $config, $serviceManager);
    }

    /**
     * @test
     */
    public function it_plans_nginx_dnsmasq_and_service_fixes(): void
    {
        $this->filesystem->shouldReceive('isDir')->with(VALET_HOME_PATH)->andReturn(true);
        $this->config->shouldReceive('get')->with('port', 80)->andReturn(80);
        $this->serviceManager->shouldReceive('isActive')->with('nginx')->andReturn(true);

        $diagnosis = [
            'nginx' => ['nginx -t' => 'nginx: [emerg] unexpected "}"'],
            'dns' => ['DnsMasq' => 'inactive', 'Domain' => 'test'],
            'services' => [
                'nginx' => 'inactive',
                'dnsmasq' => 'inactive',
                'php' => 'active',
                'mailpit' => 'active',
                'mysql' => 'active',
                'redis' => 'active',
            ],
        ];

        $actions = $this->doctor->planFixes($diagnosis);
        $ids = array_column($actions, 'id');

        $this->assertContains('nginx_config', $ids);
        $this->assertContains('dnsmasq', $ids);
        $this->assertContains('start_nginx', $ids);
        $this->assertContains('start_dnsmasq', $ids);
        $this->assertCount(4, $actions);
    }

    /**
     * @test
     */
    public function it_plans_permission_and_certificate_fixes(): void
    {
        $this->filesystem->shouldReceive('isDir')->with(VALET_HOME_PATH)->andReturn(false);
        $this->config->shouldReceive('get')->with('port', 80)->andReturn(80);
        $this->serviceManager->shouldReceive('isActive')->with('nginx')->andReturn(true);

        $secured = collect(['app.test']);
        $siteSecure = Mockery::mock();
        $siteSecure->shouldReceive('secured')->andReturn($secured);
        swap('Valet\SiteSecure', $siteSecure);

        $this->filesystem
            ->shouldReceive('exists')
            ->with(VALET_HOME_PATH . '/Certificates/app.test.crt')
            ->andReturn(false);

        $diagnosis = [
            'nginx' => ['nginx -t' => 'nginx: configuration file test is successful'],
            'dns' => ['DnsMasq' => 'active'],
            'services' => [
                'nginx' => 'active',
                'dnsmasq' => 'active',
                'php' => 'active',
                'mailpit' => 'active',
            ],
        ];

        $actions = $this->doctor->planFixes($diagnosis);
        $ids = array_column($actions, 'id');

        $this->assertContains('permissions', $ids);
        $this->assertContains('cert_app.test', $ids);
    }

    /**
     * @test
     */
    public function it_reports_port_conflicts_without_killing(): void
    {
        $this->filesystem->shouldReceive('isDir')->with(VALET_HOME_PATH)->andReturn(true);
        $this->config->shouldReceive('get')->with('port', 80)->andReturn(80);
        $this->serviceManager->shouldReceive('isActive')->with('nginx')->andReturn(false);
        $this->commandLine
            ->shouldReceive('run')
            ->with(Mockery::pattern('/ss -ltnp/'))
            ->andReturn('LISTEN 0 128 *:80 users:(("apache2",pid=99,fd=4))');

        $diagnosis = [
            'nginx' => ['nginx -t' => 'successful'],
            'dns' => ['DnsMasq' => 'active'],
            'services' => [
                'nginx' => 'active',
                'dnsmasq' => 'active',
                'php' => 'active',
                'mailpit' => 'active',
            ],
        ];

        $actions = $this->doctor->planFixes($diagnosis);
        $this->assertSame('port_conflict', $actions[0]['id']);

        $applied = $this->doctor->applyFixes($actions);
        $this->assertSame('reported', $applied[0]['status']);
    }

    /**
     * @test
     */
    public function it_applies_service_start_fixes(): void
    {
        $registry = Mockery::mock();
        $registry->shouldReceive('start')->once()->with(['nginx']);
        swap('Valet\ServiceRegistry', $registry);

        $actions = [[
            'id' => 'start_nginx',
            'description' => 'Start inactive service [nginx]',
            'status' => 'pending',
        ]];

        $result = $this->doctor->applyFixes($actions);

        $this->assertSame('fixed', $result[0]['status']);
    }

    /**
     * @test
     */
    public function it_dry_runs_without_applying(): void
    {
        $this->diagnose->shouldReceive('gather')->once()->andReturn([
            'nginx' => ['nginx -t' => 'successful'],
            'dns' => ['DnsMasq' => 'inactive'],
            'services' => [
                'nginx' => 'active',
                'dnsmasq' => 'inactive',
                'php' => 'active',
                'mailpit' => 'active',
            ],
        ]);
        $this->diagnose->shouldReceive('run')->once()->with(false);

        $this->filesystem->shouldReceive('isDir')->with(VALET_HOME_PATH)->andReturn(true);
        $this->config->shouldReceive('get')->with('port', 80)->andReturn(80);
        $this->serviceManager->shouldReceive('isActive')->with('nginx')->andReturn(true);

        \ConsoleComponents\Writer::fake();
        $result = $this->doctor->run(false, true, false);

        $this->assertNotEmpty($result['actions']);
        foreach ($result['actions'] as $action) {
            $this->assertSame('planned', $action['status']);
        }

        /** @var BufferedOutput $writerOutput */
        $writerOutput = \ConsoleComponents\Writer::output();
        $this->assertStringContainsString('dry-run', $writerOutput->fetch());
    }
}
