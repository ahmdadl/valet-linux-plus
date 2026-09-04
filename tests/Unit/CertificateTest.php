<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\Certificate;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Tests\TestCase;

use function Valet\swap;

class CertificateTest extends TestCase
{
    private MockInterface $files;
    private MockInterface $cli;
    private MockInterface $config;
    private Certificate $certificate;
    private string $pem;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = Mockery::mock(Filesystem::class);
        $this->cli = Mockery::mock(CommandLine::class);
        $this->config = Mockery::mock(Configuration::class);

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var CommandLine $cli */
        $cli = $this->cli;
        /** @var Configuration $config */
        $config = $this->config;

        $this->certificate = new Certificate($files, $cli, $config);
        $this->pem = $this->generatePem(60);

        $this->config->shouldReceive('get')->with('domain', 'test')->andReturn('test')->byDefault();
    }

    /**
     * @test
     */
    public function it_parses_certificate_expiry(): void
    {
        $parsed = $this->certificate->parseCertificate($this->pem);

        $this->assertTrue($parsed['valid']);
        $this->assertNotNull($parsed['expires_at']);
        $this->assertIsInt($parsed['days_remaining']);
        $this->assertGreaterThan(50, $parsed['days_remaining']);
        $this->assertLessThanOrEqual(60, $parsed['days_remaining']);
    }

    /**
     * @test
     */
    public function it_lists_certificates_with_expiry_fields(): void
    {
        $siteSecure = Mockery::mock();
        $siteSecure->shouldReceive('secured')->andReturn(collect(['app.test']));
        $siteSecure->shouldReceive('certificateFilePath')->with('app.test', 'crt')
            ->andReturn(VALET_HOME_PATH . '/Certificates/app.test.crt');
        swap('Valet\SiteSecure', $siteSecure);

        $this->files->shouldReceive('exists')
            ->with(VALET_HOME_PATH . '/Certificates/app.test.crt')
            ->andReturn(true);
        $this->files->shouldReceive('get')
            ->with(VALET_HOME_PATH . '/Certificates/app.test.crt')
            ->andReturn($this->pem);

        $rows = $this->certificate->list();

        $this->assertCount(1, $rows);
        $this->assertSame('app.test', $rows[0]['site']);
        $this->assertArrayHasKey('expires_at', $rows[0]);
        $this->assertArrayHasKey('days_remaining', $rows[0]);
        $this->assertTrue($rows[0]['valid']);
    }

    /**
     * @test
     */
    public function it_renews_when_near_expiry_or_forced(): void
    {
        $nearExpiryPem = $this->generatePem(5);

        $siteSecure = Mockery::mock();
        $siteSecure->shouldReceive('secured')->andReturn(collect(['old.test']));
        $siteSecure->shouldReceive('certificateFilePath')->with('old.test', 'crt')
            ->andReturn(VALET_HOME_PATH . '/Certificates/old.test.crt');
        $siteSecure->shouldReceive('secure')->once()->with('old.test');
        swap('Valet\SiteSecure', $siteSecure);

        $nginx = Mockery::mock();
        $nginx->shouldReceive('restart')->once();
        swap('Valet\Nginx', $nginx);

        $this->files->shouldReceive('exists')->andReturn(true);
        $this->files->shouldReceive('get')->andReturn($nearExpiryPem);

        $results = $this->certificate->renew(null, false);

        $this->assertTrue($results[0]['renewed']);
        $this->assertSame('old.test', $results[0]['site']);
    }

    /**
     * @test
     */
    public function it_skips_renewal_when_plenty_of_days_remain(): void
    {
        $siteSecure = Mockery::mock();
        $siteSecure->shouldReceive('certificateFilePath')->with('fresh.test', 'crt')
            ->andReturn(VALET_HOME_PATH . '/Certificates/fresh.test.crt');
        $siteSecure->shouldReceive('secure')->never();
        swap('Valet\SiteSecure', $siteSecure);

        $this->files->shouldReceive('exists')->andReturn(true);
        $this->files->shouldReceive('get')->andReturn($this->pem);

        $results = $this->certificate->renew('fresh.test', false);

        $this->assertFalse($results[0]['renewed']);
        $this->assertStringContainsString('days remaining', $results[0]['reason']);
    }

    /**
     * @test
     */
    public function it_checks_trust_status(): void
    {
        $siteSecure = Mockery::mock();
        $siteSecure->shouldReceive('trustCaCertificate')->once()->with(true)->andReturn([
            'ca_exists' => true,
            'trusted' => false,
            'ca_path' => '/tmp/ca.pem',
            'system_path' => '/tmp/ca.crt',
        ]);
        swap('Valet\SiteSecure', $siteSecure);

        $result = $this->certificate->trust(true);

        $this->assertTrue($result['ca_exists']);
        $this->assertFalse($result['trusted']);
    }

    private function generatePem(int $daysValid): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'test.valet'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, $daysValid, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        return $pem;
    }
}
