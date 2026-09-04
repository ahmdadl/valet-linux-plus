<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\Bench;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Tests\TestCase;

class BenchTest extends TestCase
{
    private Bench $bench;

    public function setUp(): void
    {
        parent::setUp();

        /** @var CommandLine $cli */
        $cli = Mockery::mock(CommandLine::class);
        /** @var Configuration&MockInterface $config */
        $config = Mockery::mock(Configuration::class);
        /** @var Filesystem $files */
        $files = Mockery::mock(Filesystem::class);

        $config->shouldReceive('get')->with('https_port', 443)->andReturn(443)->byDefault();
        $config->shouldReceive('get')->with('port', 80)->andReturn(80)->byDefault();
        $config->shouldReceive('get')->with('domain', 'test')->andReturn('test')->byDefault();

        $this->bench = new Bench($cli, $config, $files);
    }

    /**
     * @test
     */
    public function percentile_helper_via_reflection(): void
    {
        $method = new \ReflectionMethod(Bench::class, 'percentile');
        $method->setAccessible(true);

        $p50 = $method->invoke($this->bench, [10.0, 20.0, 30.0, 40.0], 50.0);
        $this->assertEqualsWithDelta(25.0, $p50, 0.01);
    }

    /**
     * @test
     */
    public function resolve_host_appends_tld(): void
    {
        $method = new \ReflectionMethod(Bench::class, 'resolveHost');
        $method->setAccessible(true);

        $configFacade = Mockery::mock();
        $configFacade->shouldReceive('get')->with('domain', 'test')->andReturn('test');
        \Valet\swap('Valet\Configuration', $configFacade);

        $host = $method->invoke($this->bench, 'my-app');
        $this->assertSame('my-app.test', $host);
    }
}
