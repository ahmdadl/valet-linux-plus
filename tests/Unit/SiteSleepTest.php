<?php

namespace Unit;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\SiteIsolate;
use Valet\SiteLink;
use Valet\SiteSecure;
use Valet\SiteSleep;
use Valet\Tests\TestCase;

class SiteSleepTest extends TestCase
{
    private Configuration|MockObject $config;
    private Filesystem|MockObject $filesystem;
    private SiteLink|MockObject $siteLink;
    private Nginx|MockObject $nginx;
    private PhpFpm|MockObject $phpFpm;
    private SiteIsolate|MockObject $siteIsolate;
    private SiteSecure|MockObject $siteSecure;
    private SiteSleep $siteSleep;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Configuration::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->siteLink = Mockery::mock(SiteLink::class);
        $this->nginx = Mockery::mock(Nginx::class);
        $this->phpFpm = Mockery::mock(PhpFpm::class);
        $this->siteIsolate = Mockery::mock(SiteIsolate::class);
        $this->siteSecure = Mockery::mock(SiteSecure::class);

        $this->siteSleep = new SiteSleep(
            $this->config,
            $this->filesystem,
            $this->siteLink,
            $this->nginx,
            $this->phpFpm,
            $this->siteIsolate,
            $this->siteSecure
        );
    }

    private function setupCommonMocks(): void
    {
        // parseDomain behaves like the real implementation: only add .test if not already present
        $this->config->shouldReceive('parseDomain')->andReturnUsing(function ($site) {
            return str_ends_with($site, '.test') ? $site : $site . '.test';
        });
        $this->config->shouldReceive('get')->with('domain')->andReturn('test');
    }

    /**
     * SiteSleep warns through the static ConsoleComponents\Writer, which
     * swap() cannot intercept, so the warnings are not asserted on here.
     * What is asserted instead is the behaviour each warning accompanies:
     * whether the FPM pool was stopped and whether the asleep flag was set.
     */

    /**
     * @test
     */
    public function itWillSleepIsolatedSiteWithUniqueVersionAndStopFpm(): void
    {
        $links = collect([
            'site.test' => [
                'url' => 'http://site.test',
                'secured' => '✕',
                'path' => '/path/to/site',
            ],
        ]);

        $this->setupCommonMocks();
        $this->siteLink->shouldReceive('links')->andReturn($links);

        // configuredSites() is called twice: once to resolve the site and once
        // inside countSitesUsingPhpVersion(). isolatedPhpVersion() is called
        // once for this site and once per configured site by that same count.
        $this->nginx->shouldReceive('configuredSites')->twice()->andReturn(collect(['site.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site.test')->twice()->andReturn('8.2');

        $this->config->shouldReceive('get')->with('sites', [])->andReturn([]);
        $this->config->shouldReceive('set')->with('sites', ['site' => ['asleep' => true]])->once();

        $this->phpFpm->shouldReceive('stopIfUnused')->with('8.2')->once();

        $this->siteSleep->sleep('site', false, true);
    }

    /**
     * @test
     */
    public function itWillSleepIsolatedSiteWithSharedVersionAndWarn(): void
    {
        $links = collect([
            'site1.test' => ['url' => 'http://site1.test', 'secured' => '✕', 'path' => '/p1'],
            'site2.test' => ['url' => 'http://site2.test', 'secured' => '✕', 'path' => '/p2'],
        ]);

        $this->setupCommonMocks();
        $this->siteLink->shouldReceive('links')->andReturn($links);

        // site1 is the only site being slept, so isolatedPhpVersion() sees it
        // once in the main loop and once more via countSitesUsingPhpVersion(),
        // while site2 is only reached through that count.
        $this->nginx->shouldReceive('configuredSites')->twice()->andReturn(collect(['site1.test', 'site2.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site1.test')->twice()->andReturn('8.2');
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site2.test')->once()->andReturn('8.2');

        $this->config->shouldReceive('get')->with('sites', [])->andReturn([]);
        $this->config->shouldReceive('set')->with('sites', ['site1' => ['asleep' => true]])->once();

        // 8.2 is still needed by site2, so the pool must be left running.
        $this->phpFpm->shouldReceive('stopIfUnused')->never();

        $this->siteSleep->sleep('site1', false, true);
    }

    /**
     * @test
     */
    public function itWillSleepNonIsolatedSiteAndOnlySetFlag(): void
    {
        $links = collect([
            'site.test' => ['url' => 'http://site.test', 'secured' => '✕', 'path' => '/p1'],
        ]);

        $this->setupCommonMocks();
        $this->siteLink->shouldReceive('links')->andReturn($links);

        // A site with no isolated version never reaches
        // countSitesUsingPhpVersion(), so each of these is called only once.
        $this->nginx->shouldReceive('configuredSites')->once()->andReturn(collect(['site.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site.test')->once()->andReturn(null);

        $this->config->shouldReceive('get')->with('sites', [])->andReturn([]);
        $this->config->shouldReceive('set')->with('sites', ['site' => ['asleep' => true]])->once();

        // It shares the global pool, so it must not be stopped.
        $this->phpFpm->shouldReceive('stopIfUnused')->never();

        $this->siteSleep->sleep('site', false, true);
    }

    /**
     * @test
     */
    public function itWillSleepAllSites(): void
    {
        $links = collect([
            'site1.test' => ['url' => 'http://site1.test', 'secured' => '✕', 'path' => '/p1'],
            'site2.test' => ['url' => 'http://site2.test', 'secured' => '✕', 'path' => '/p2'],
        ]);

        $this->setupCommonMocks();
        $this->siteLink->shouldReceive('links')->andReturn($links);
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site1.test', 'site2.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->andReturn(null);

        // countSitesUsingPhpVersion called twice (once per site), should return 0
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site1.test', 'site2.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->andReturn(null);

        $this->config->shouldReceive('get')->with('sites', [])->andReturn([]);
        $this->config->shouldReceive('set')->with('sites', [
            'site1' => ['asleep' => true],
            'site2' => ['asleep' => true],
        ])->once();

        $this->phpFpm->shouldReceive('stopIfUnused')->never();

        $this->siteSleep->sleep(null, true, false);
    }

    /**
     * @test
     */
    public function itWillWakeSiteAndRestartPhpFpm(): void
    {
        $links = collect([
            'site.test' => ['url' => 'http://site.test', 'secured' => '✕', 'path' => '/p1'],
        ]);

        $this->setupCommonMocks();
        $this->siteLink->shouldReceive('links')->andReturn($links);
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site.test')->andReturn('8.2');

        $this->config->shouldReceive('get')->with('sites', [])->andReturn(['site' => ['asleep' => true]]);
        $this->config->shouldReceive('set')->with('sites', ['site' => ['asleep' => false]])->once();

        $this->phpFpm->shouldReceive('restart')->with('8.2')->once();

        $this->siteSleep->wake('site', false);
    }

    /**
     * @test
     */
    public function itWillWakeSiteAndHandleRestartFailure(): void
    {
        $links = collect([
            'site.test' => ['url' => 'http://site.test', 'secured' => '✕', 'path' => '/p1'],
        ]);

        $this->setupCommonMocks();
        $this->siteLink->shouldReceive('links')->andReturn($links);
        $this->nginx->shouldReceive('configuredSites')->once()->andReturn(collect(['site.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site.test')->once()->andReturn('8.2');

        $this->config->shouldReceive('get')->with('sites', [])->andReturn(['site' => ['asleep' => true]]);
        $this->config->shouldReceive('set')->with('sites', ['site' => ['asleep' => false]])->once();

        $this->phpFpm->shouldReceive('restart')->with('8.2')->once()->andThrow(new \RuntimeException('restart failed'));

        // The failure is swallowed and reported through Writer::warn, so the
        // call must return normally and still clear the asleep flag.
        $this->siteSleep->wake('site', false);

        $this->assertTrue(true, 'wake() swallowed the restart failure');
    }

    /**
     * @test
     */
    public function itWillWakeAllSites(): void
    {
        $links = collect([
            'site1.test' => ['url' => 'http://site1.test', 'secured' => '✕', 'path' => '/p1'],
            'site2.test' => ['url' => 'http://site2.test', 'secured' => '✕', 'path' => '/p2'],
        ]);

        $this->setupCommonMocks();
        $this->siteLink->shouldReceive('links')->andReturn($links);
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site1.test', 'site2.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site1.test')->andReturn('8.2');
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site2.test')->andReturn(null);

        $this->config->shouldReceive('get')->with('sites', [])->andReturn([
            'site1' => ['asleep' => true],
            'site2' => ['asleep' => true],
        ]);
        $this->config->shouldReceive('set')->with('sites', [
            'site1' => ['asleep' => false],
            'site2' => ['asleep' => false],
        ])->once();

        $this->phpFpm->shouldReceive('restart')->with('8.2')->once();

        $this->siteSleep->wake(null, true);
    }

    /**
     * @test
     */
    public function itWillCheckIfSiteIsAsleep(): void
    {
        $this->config->shouldReceive('parseDomain')->with('site')->andReturn('site.test');
        $this->config->shouldReceive('get')->with('domain')->andReturn('test');
        $this->config->shouldReceive('get')->with('sites', [])->andReturn(
            ['site' => ['asleep' => true]],
            ['site' => ['asleep' => false]],
            []
        );

        $this->assertTrue($this->siteSleep->isAsleep('site'));
        $this->assertFalse($this->siteSleep->isAsleep('site'));
        $this->assertFalse($this->siteSleep->isAsleep('site'));
    }

    /**
     * @test
     */
    public function itWillReturnAsleepSites(): void
    {
        $this->config->shouldReceive('get')->with('sites', [])->andReturn([
            'site1' => ['asleep' => true],
            'site2' => ['asleep' => false],
            'site3' => ['asleep' => true],
        ]);

        $asleep = $this->siteSleep->asleepSites();

        $this->assertEquals(['site1', 'site3'], $asleep);
    }

    /**
     * @test
     */
    public function itWillReturnEmptyAsleepSitesWhenConfigNotArray(): void
    {
        $this->config->shouldReceive('get')->with('sites', [])->andReturn('not-an-array');

        $asleep = $this->siteSleep->asleepSites();

        $this->assertEquals([], $asleep);
    }

    /**
     * @test
     */
    public function itWillReturnStatusRowsWithAsleepInfo(): void
    {
        $links = collect([
            'site1.test' => ['url' => 'http://site1.test', 'secured' => '✕', 'path' => '/p1'],
            'site2.test' => ['url' => 'https://site2.test', 'secured' => '✓', 'path' => '/p2'],
        ]);

        $this->siteLink->shouldReceive('links')->andReturn($links);
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site1.test', 'site2.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site1.test')->andReturn('8.2');
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site2.test')->andReturn(null);

        $this->config->shouldReceive('get')->with('domain')->andReturn('test');
        $this->config->shouldReceive('parseDomain')->with('site1')->andReturn('site1.test');
        $this->config->shouldReceive('parseDomain')->with('site2')->andReturn('site2.test');
        $this->config->shouldReceive('get')->with('sites', [])->andReturn([
            'site1' => ['asleep' => true],
            'site2' => ['asleep' => false],
        ]);

        // For countSitesUsingPhpVersion - only site1 uses 8.2
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site1.test', 'site2.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site1.test')->andReturn('8.2');
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site2.test')->andReturn(null);

        $rows = $this->siteSleep->statusRows();

        $this->assertCount(2, $rows);
        $this->assertEquals('site1', $rows[0]['site']);
        $this->assertEquals('http://site1.test', $rows[0]['url']);
        $this->assertTrue($rows[0]['asleep']);
        $this->assertEquals('8.2', $rows[0]['isolatedVersion']);
        $this->assertFalse($rows[0]['poolShared']);

        $this->assertEquals('site2', $rows[1]['site']);
        $this->assertEquals('https://site2.test', $rows[1]['url']);
        $this->assertFalse($rows[1]['asleep']);
        $this->assertNull($rows[1]['isolatedVersion']);
        $this->assertFalse($rows[1]['poolShared']);
    }

    /**
     * @test
     */
    public function itWillDetectPoolSharedWhenMultipleSitesUseSameVersion(): void
    {
        $links = collect([
            'site1.test' => ['url' => 'http://site1.test', 'secured' => '✕', 'path' => '/p1'],
            'site2.test' => ['url' => 'http://site2.test', 'secured' => '✕', 'path' => '/p2'],
        ]);

        $this->siteLink->shouldReceive('links')->andReturn($links);
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site1.test', 'site2.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site1.test')->andReturn('8.2');
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site2.test')->andReturn('8.2');

        $this->config->shouldReceive('get')->with('domain')->andReturn('test');
        $this->config->shouldReceive('parseDomain')->with('site1')->andReturn('site1.test');
        $this->config->shouldReceive('parseDomain')->with('site2')->andReturn('site2.test');
        $this->config->shouldReceive('get')->with('sites', [])->andReturn([
            'site1' => ['asleep' => false],
            'site2' => ['asleep' => false],
        ]);

        // For countSitesUsingPhpVersion - both sites use 8.2
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site1.test', 'site2.test']));
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site1.test')->andReturn('8.2');
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('site2.test')->andReturn('8.2');

        $rows = $this->siteSleep->statusRows();

        // site1 should have poolShared = true since site2 also uses 8.2
        $this->assertTrue($rows[0]['poolShared']);
        // site2 should also have poolShared = true
        $this->assertTrue($rows[1]['poolShared']);
    }

    /**
     * @test
     */
    public function itWillThrowExceptionForUnknownSite(): void
    {
        $links = collect([
            'site1.test' => ['url' => 'http://site1.test', 'secured' => '✕', 'path' => '/p1'],
        ]);

        $this->setupCommonMocks();
        $this->siteLink->shouldReceive('links')->andReturn($links);
        $this->nginx->shouldReceive('configuredSites')->andReturn(collect(['site1.test']));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Site [unknown] not found.');

        $this->siteSleep->sleep('unknown', false, false);
    }
}
