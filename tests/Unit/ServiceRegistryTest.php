<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\ServiceRegistry;
use Valet\Tests\TestCase;

use function Valet\swap;

class ServiceRegistryTest extends TestCase
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
    public function itResolvesKnownServiceNames(): void
    {
        $resolve = new \ReflectionMethod($this->registry, 'resolve');
        $resolve->setAccessible(true);

        $this->assertSame('nginx', $resolve->invoke($this->registry, 'nginx'));
        $this->assertSame('php', $resolve->invoke($this->registry, 'php'));
        $this->assertSame('mailpit', $resolve->invoke($this->registry, 'mailpit'));
        $this->assertSame('dnsmasq', $resolve->invoke($this->registry, 'dnsmasq'));
        $this->assertSame('mysql', $resolve->invoke($this->registry, 'mysql'));
        $this->assertSame('redis', $resolve->invoke($this->registry, 'redis'));
        $this->assertSame('postgres', $resolve->invoke($this->registry, 'postgres'));
    }

    /**
     * @test
     */
    public function itReturnsNullForUnknownServiceNames(): void
    {
        $resolve = new \ReflectionMethod($this->registry, 'resolve');
        $resolve->setAccessible(true);

        $this->assertNull($resolve->invoke($this->registry, 'apache'));
        $this->assertNull($resolve->invoke($this->registry, 'not-a-service'));
    }

    /**
     * @test
     */
    public function itStartsAllServicesWhenNoneSpecified(): void
    {
        $nginx = Mockery::mock();
        $php = Mockery::mock();
        $mailpit = Mockery::mock();
        $dnsmasq = Mockery::mock();
        $mysql = Mockery::mock();
        $redis = Mockery::mock();
        $postgres = Mockery::mock();

        $nginx->shouldReceive('start')->once();
        $php->shouldReceive('start')->once();
        $mailpit->shouldReceive('start')->once();
        $dnsmasq->shouldReceive('start')->once();
        $mysql->shouldReceive('start')->once();
        $redis->shouldReceive('start')->once();
        $postgres->shouldReceive('start')->once();

        swap('Valet\Nginx', $nginx);
        swap('Valet\PhpFpm', $php);
        swap('Valet\Mailpit', $mailpit);
        swap('Valet\DnsMasq', $dnsmasq);
        swap('Valet\Mysql', $mysql);
        swap('Valet\ValetRedis', $redis);
        swap('Valet\Postgres', $postgres);

        $this->registry->start([]);
    }

    /**
     * @test
     */
    public function itStartsOnlySpecifiedServices(): void
    {
        $nginx = Mockery::mock();
        $php = Mockery::mock();

        $nginx->shouldReceive('start')->once();
        $php->shouldNotReceive('start');

        swap('Valet\Nginx', $nginx);
        swap('Valet\PhpFpm', $php);

        $this->registry->start(['nginx']);
    }

    /**
     * @test
     */
    public function itStopsServicesWithoutDnsmasqWhenNoneSpecified(): void
    {
        $php = Mockery::mock();
        $nginx = Mockery::mock();
        $mailpit = Mockery::mock();
        $mysql = Mockery::mock();
        $redis = Mockery::mock();
        $dnsmasq = Mockery::mock();

        $php->shouldReceive('stop')->once();
        $nginx->shouldReceive('stop')->once();
        $mailpit->shouldReceive('stop')->once();
        $mysql->shouldReceive('stop')->once();
        $redis->shouldReceive('stop')->once();
        $dnsmasq->shouldNotReceive('stop');

        swap('Valet\PhpFpm', $php);
        swap('Valet\Nginx', $nginx);
        swap('Valet\Mailpit', $mailpit);
        swap('Valet\Mysql', $mysql);
        swap('Valet\ValetRedis', $redis);
        swap('Valet\DnsMasq', $dnsmasq);

        $this->registry->stop([]);
    }
}
