<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\Api;
use Valet\Tests\TestCase;

use function Valet\swap;

class ApiTest extends TestCase
{
    private Api $api;

    public function setUp(): void
    {
        parent::setUp();
        $this->api = new Api();
    }

    /**
     * @test
     */
    public function it_lists_resources(): void
    {
        $this->assertSame(['sites', 'services', 'env', 'health', 'profiles'], $this->api->resources());
    }

    /**
     * @test
     */
    public function it_returns_catalog(): void
    {
        $catalog = $this->api->catalog();
        $this->assertSame(1, $catalog['schema_version']);
        $this->assertContains('sites', $catalog['resources']);
    }

    /**
     * @test
     */
    public function it_wraps_env_in_envelope(): void
    {
        $env = Mockery::mock();
        $env->shouldReceive('gather')->andReturn([
            'SITE_URL' => 'https://my-app.test',
            'PHP_VERSION' => '8.3',
        ]);
        swap('Valet\Environment', $env);

        $payload = $this->api->get('env');
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame('env', $payload['schema_command']);
        $this->assertSame('https://my-app.test', $payload['data']['SITE_URL']);
        $this->assertArrayHasKey('timestamp', $payload);
    }

    /**
     * @test
     */
    public function it_rejects_unknown_resource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->api->get('nope');
    }
}
