<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\Configuration;
use Valet\Mailpit;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\Postgres;
use Valet\ServiceRegistry;
use Valet\Tests\TestCase;

use function Valet\swap;

class ServiceRegistryCustomTest extends TestCase
{
    private ServiceRegistry $registry;

    public function setUp(): void
    {
        parent::setUp();

        $this->registry = new ServiceRegistry();
    }

    /**
     * @test
     */
    public function itExposesBuiltInTemplates(): void
    {
        $templates = $this->registry->templates();

        $this->assertArrayHasKey('minio', $templates);
        $this->assertSame('minio', $templates['minio']['package']);
        $this->assertSame('minio', $templates['minio']['service']);
        $this->assertSame(9000, $templates['minio']['port']);
        $this->assertSame('http://127.0.0.1:9000/minio/health/live', $templates['minio']['healthCheck']);
    }

    /**
     * @test
     */
    public function itReadsCustomServicesFromConfig(): void
    {
        Configuration::set('services', [
            'minio' => [
                'package' => 'minio',
                'service' => 'minio',
                'port' => 9000,
                'proxyHost' => 'http://127.0.0.1:9000',
            ],
            'meilisearch' => [
                'package' => 'meilisearch',
                'service' => 'meilisearch',
                'port' => 7700,
            ],
        ]);

        $services = $this->registry->customServices();

        $this->assertArrayHasKey('minio', $services);
        $this->assertArrayHasKey('meilisearch', $services);
        // Defaults are filled in.
        $this->assertSame('minio', $services['minio']['name']);
        $this->assertNull($services['minio']['healthCheck']);
        $this->assertSame('http://127.0.0.1:9000', $services['minio']['proxyHost']);
    }

    /**
     * @test
     */
    public function itPersistsAddedAndRemovedServices(): void
    {
        $this->registry->addService('minio', [
            'package' => 'minio',
            'service' => 'minio',
            'port' => 9000,
        ]);

        /** @var array<string, array<string, mixed>> $stored */
        $stored = Configuration::get('services', []);
        $this->assertArrayHasKey('minio', $stored);
        $this->assertSame('minio', $stored['minio']['package']);

        $this->assertTrue($this->registry->removeService('minio'));
        /** @var array<string, array<string, mixed>> $after */
        $after = Configuration::get('services', []);
        $this->assertArrayNotHasKey('minio', $after);
    }

    /**
     * @test
     */
    public function itCanAddServiceFromTemplate(): void
    {
        $this->registry->addService('minio', []);

        /** @var array<string, array<string, mixed>> $stored */
        $stored = Configuration::get('services', []);
        $this->assertSame(9000, $stored['minio']['port']);
        $this->assertSame('http://127.0.0.1:9000/minio/health/live', $stored['minio']['healthCheck']);
    }

    /**
     * @test
     */
    public function itRejectsInvalidServiceDefinitions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registry->addService('broken', [
            'package' => 'broken',
            'service' => 'broken',
            // missing required "port"
        ]);
    }

    /**
     * @test
     */
    public function itReportsServicePresence(): void
    {
        Configuration::set('services', [
            'minio' => ['package' => 'minio', 'service' => 'minio', 'port' => 9000],
        ]);

        $this->assertTrue($this->registry->hasService('minio'));
        $this->assertTrue($this->registry->isCustomService('minio'));
        $this->assertFalse($this->registry->isCustomService('nginx'));

        $this->assertTrue($this->registry->hasService('nginx'));
        $this->assertFalse($this->registry->isCustomService('nginx'));

        $this->assertFalse($this->registry->hasService('nope'));
    }

    /**
     * @test
     */
    public function resolveHandlesCustomServiceNames(): void
    {
        Configuration::set('services', [
            'minio' => ['package' => 'minio', 'service' => 'minio', 'port' => 9000],
        ]);

        $resolve = new \ReflectionMethod($this->registry, 'resolve');
        $resolve->setAccessible(true);

        $this->assertSame('minio', $resolve->invoke($this->registry, 'minio'));
        $this->assertSame('nginx', $resolve->invoke($this->registry, 'nginx'));
        $this->assertNull($resolve->invoke($this->registry, 'unknown'));
    }

    /**
     * @test
     */
    public function itStartsCustomServiceViaServiceManager(): void
    {
        $this->registry->addService('minio', [
            'package' => 'minio',
            'service' => 'minio',
            'port' => 9000,
        ]);

        $pm = Mockery::mock(PackageManager::class);
        $pm->shouldReceive('installed')->with('minio')->once()->andReturnTrue();

        $sm = Mockery::mock(ServiceManager::class);
        $sm->shouldReceive('restart')->with('minio')->once();

        swap(PackageManager::class, $pm);
        swap(ServiceManager::class, $sm);

        $this->registry->start(['minio']);
    }

    /**
     * @test
     */
    public function itStopsCustomServiceViaServiceManager(): void
    {
        $this->registry->addService('minio', [
            'package' => 'minio',
            'service' => 'minio',
            'port' => 9000,
        ]);

        $pm = Mockery::mock(PackageManager::class);
        $pm->shouldReceive('installed')->with('minio')->once()->andReturnTrue();

        $sm = Mockery::mock(ServiceManager::class);
        $sm->shouldReceive('stop')->with('minio')->once();

        swap(PackageManager::class, $pm);
        swap(ServiceManager::class, $sm);

        $this->registry->stop(['minio']);
    }

    /**
     * @test
     */
    public function itSkipsCustomServiceWhenPackageNotInstalled(): void
    {
        $this->registry->addService('minio', [
            'package' => 'minio',
            'service' => 'minio',
            'port' => 9000,
        ]);

        $pm = Mockery::mock(PackageManager::class);
        $pm->shouldReceive('installed')->with('minio')->once()->andReturnFalse();

        $sm = Mockery::mock(ServiceManager::class);
        $sm->shouldNotReceive('restart');
        $sm->shouldNotReceive('stop');

        swap(PackageManager::class, $pm);
        swap(ServiceManager::class, $sm);

        $this->registry->start(['minio']);
    }

    /**
     * @test
     */
    public function statusIncludesCustomServices(): void
    {
        $this->registry->addService('minio', [
            'package' => 'minio',
            'service' => 'minio',
            'port' => 9000,
        ]);

        $nginx = Mockery::mock();
        $php = Mockery::mock();
        $mailpit = Mockery::mock();
        $postgres = Mockery::mock();

        $nginx->shouldReceive('status')->once();
        $php->shouldReceive('status')->once();
        $mailpit->shouldReceive('status')->once();
        $postgres->shouldReceive('status')->once();

        swap(Nginx::class, $nginx);
        swap(PhpFpm::class, $php);
        swap(Mailpit::class, $mailpit);
        swap(Postgres::class, $postgres);

        $sm = Mockery::mock(ServiceManager::class);
        $sm->shouldReceive('printStatus')->with('minio')->once();
        swap(ServiceManager::class, $sm);

        $this->registry->status();
    }
}
