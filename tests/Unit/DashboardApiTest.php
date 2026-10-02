<?php

namespace Valet\Tests\Unit;

use Mockery\MockInterface;
use Valet\Addon;
use Valet\Backup;
use Valet\Cache;
use Valet\Certificate;
use Valet\Configuration;
use Valet\DashboardApi;
use Valet\DashboardJob;
use Valet\DashboardPrivilege;
use Valet\DashboardRequest;
use Valet\Diagnose;
use Valet\Filesystem;
use Valet\Health;
use Valet\Log;
use Valet\Mysql;
use Valet\Node;
use Valet\Postgres;
use Valet\ServiceRegistry;
use Valet\SiteLink;
use Valet\Snapshot;
use Valet\Tests\TestCase;

/**
 * The dashboard's action registry, dispatcher and parameter validation.
 *
 * The tests concentrate on the three things that can do damage: who is allowed
 * to ask, what they are allowed to ask for, and what has to be confirmed.
 */
class DashboardApiTest extends TestCase
{
    private MockInterface $jobs;

    private DashboardApi $api;

    private string $token;

    public function setUp(): void
    {
        parent::setUp();

        $this->jobs = \Mockery::mock(DashboardJob::class);
        $this->token = str_repeat('t', 64);

        $privilege = \Mockery::mock(DashboardPrivilege::class);
        $this->api = $this->apiWith(privilege: $this->asPrivilege($privilege));
    }

    /**
     * Build a request as the browser would send it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $overrides
     */
    private function request(string $slug, array $payload = [], array $overrides = []): DashboardRequest
    {
        // array_key_exists rather than ?? so a test can deliberately send a
        // null header, which is exactly what a missing one looks like.
        $pick = static fn (string $key, ?string $fallback): ?string => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : $fallback;

        return new DashboardRequest(
            $pick('method', 'POST') ?? 'POST',
            $slug,
            $pick('host', 'valet.test') ?? 'valet.test',
            $pick('remoteAddress', '127.0.0.1') ?? '127.0.0.1',
            $pick('origin', null),
            $pick('referer', null),
            $pick('csrfCookie', $this->token),
            $pick('csrfHeader', $this->token),
            $payload
        );
    }

    // ------------------------------------------------------------- the registry

    public function testItExposesEveryActionItIsPreparedToRun(): void
    {
        $this->assertContains('service.restart', $this->api->slugs());
        $this->assertContains('db.create', $this->api->slugs());
        $this->assertContains('logs.view', $this->api->slugs());
        $this->assertTrue($this->api->has('php.switch'));
        $this->assertFalse($this->api->has('shell.exec'));
    }

    public function testItKeepsEveryPrivilegedActionInStepWithTheHelper(): void
    {
        // The helper is the only thing that can run root-tier work, so an
        // action the helper does not know about would always fail. Catching
        // that here beats finding it out in the browser.
        foreach ($this->api->catalog() as $action) {
            $slug = $action['slug'];

            if ($action['tier'] === DashboardApi::TIER_ROOT) {
                $this->assertContains($slug, DashboardPrivilege::VERBS, $slug);
            }
        }
    }

    public function testItRefusesToExposeItsHandlersToTheBrowser(): void
    {
        foreach ($this->api->catalog() as $action) {
            $this->assertArrayNotHasKey('handler', $action);
        }
    }

    public function testItLabelsEveryAction(): void
    {
        foreach ($this->api->catalog() as $action) {
            $this->assertNotSame('', $action['label'], $action['slug']);
            $this->assertContains($action['tier'], [
                DashboardApi::TIER_READ,
                DashboardApi::TIER_USER,
                DashboardApi::TIER_ROOT,
            ]);
        }
    }

    public function testItConfirmsEveryDestructiveAction(): void
    {
        // Confirmation is the only thing between a stray click and a dropped
        // database, so "destructive but no confirmation" is not allowed.
        foreach ($this->api->catalog() as $action) {
            if (! $action['destructive']) {
                continue;
            }

            $this->assertNotNull($action['confirm'], $action['slug']);

            if ($action['confirm'] !== true) {
                $confirm = $action['confirm'];
                $params = (array) $action['params'];

                $this->assertArrayHasKey($confirm, $params, $confirm);
            }
        }
    }

    // ------------------------------------------------------------------ verbs

    public function testItRefusesAMutationThatIsNotAPost(): void
    {
        $result = $this->api->dispatch($this->request('db.create', ['name' => 'x'], ['method' => 'GET']));

        $this->assertFalse($result['ok']);
        $this->assertNull($result['job']);
    }

    public function testItRefusesAMutationFromAnotherMachine(): void
    {
        $result = $this->api->dispatch($this->request('db.create', ['name' => 'x'], ['remoteAddress' => '192.168.1.9']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('this machine', $result['message']);
    }

    public function testItRefusesAMutationWithoutACsrfToken(): void
    {
        $result = $this->api->dispatch($this->request('db.create', ['name' => 'x'], ['csrfHeader' => null]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('CSRF', $result['message']);
    }

    public function testItRefusesAMutationFromAnotherOrigin(): void
    {
        $result = $this->api->dispatch($this->request('db.create', ['name' => 'x'], ['origin' => 'http://evil.test']));

        $this->assertFalse($result['ok']);
    }

    public function testItRefusesAnUnknownAction(): void
    {
        $result = $this->api->dispatch($this->request('rm.rf', []));

        $this->assertFalse($result['ok']);
    }

    // ------------------------------------------------------- parameter checks

    public function testItValidatesParameterTypes(): void
    {
        $this->assertSame(['name' => 'shop'], $this->api->validateParams('db.create', ['name' => 'shop']));
        $this->assertSame(['name' => 'shop_2'], $this->api->validateParams('db.create', ['name' => 'shop_2']));
        $this->assertSame(['with_db' => true], $this->api->validateParams('backup.create', ['with_db' => '1']));
        $this->assertSame(['name' => 'shop', 'sql' => false], $this->api->validateParams('db.export', ['name' => 'shop', 'sql' => 0]));

        // Declared defaults are filled in so a handler never has to.
        $this->assertSame(['port' => 8080, 'https' => false], $this->api->validateParams('port.set', ['port' => '8080']));
    }

    public function testItRejectsAValueOfTheWrongShape(): void
    {
        foreach ([
            ['db.create', ['name' => '../../etc/passwd']],
            ['db.create', ['name' => 'has space']],
            ['db.create', ['name' => 'semi;colon']],
            ['db.create', ['name' => '']],
            ['site.link', ['name' => 'ok', 'path' => 'relative/path']],
            ['site.link', ['name' => 'ok', 'path' => '/tmp//double']],
            ['backup.restore', ['archive' => '../../etc/passwd']],
            ['backup.restore', ['archive' => '/absolute/path.tar.gz']],
        ] as [$slug, $params]) {
            $failed = false;

            try {
                $this->api->validateParams($slug, $params);
            } catch (\Throwable $e) {
                $failed = true;
            }

            $this->assertTrue($failed, $slug . ' ' . json_encode($params));
        }
    }

    public function testItRejectsAValueOutsideAnAllowlist(): void
    {
        // The service list is fixed at the top of DashboardApi precisely so a
        // service registered by an extension cannot become a privileged target.
        foreach (['root', 'ssh', 'Nginx', 'ngin x', 'nginx;id'] as $service) {
            $failed = false;

            try {
                $this->api->validateParams('service.restart', ['service' => $service]);
            } catch (\Throwable $e) {
                $failed = true;
            }

            $this->assertTrue($failed, $service);
        }
    }

    public function testItRejectsARequiredParameterThatWasLeftOut(): void
    {
        $this->expectException(\Throwable::class);

        $this->api->validateParams('db.create', []);
    }

    public function testItRefusesAParameterThatIsNotPartOfTheAction(): void
    {
        // Refusing beats dropping: a crafted body that carries an extra key is
        // a sign the caller does not know what this action does, and guessing
        // is how a value ends up somewhere it was never meant to go.
        $this->expectException(\Valet\Exceptions\DashboardActionException::class);

        $this->api->validateParams('db.create', ['name' => 'shop', 'evil' => 1]);
    }

    // ------------------------------------------------------------ confirmation

    public function testItRefusesADestructiveActionThatWasNotConfirmed(): void
    {
        $result = $this->api->dispatch($this->request('db.drop', ['name' => 'shop']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('confirmed', $result['message']);
    }

    public function testItRefusesAConfirmationForSomethingElse(): void
    {
        // The confirmation has to be the exact value being destroyed, so the
        // user cannot be shown one target and have another acted on.
        $result = $this->api->dispatch($this->request('db.drop', ['name' => 'shop', 'confirm' => 'other']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('match', $result['message']);
    }

    public function testItAcceptsAConfirmationMatchingTheTarget(): void
    {
        $mysql = \Mockery::mock(Mysql::class);
        $mysql->shouldReceive('dropDatabase')->once()->with('shop')->andReturn(true);

        $api = $this->apiWith(mysql: $this->asMysql($mysql));

        $result = $api->dispatch($this->request('db.drop', ['name' => 'shop', 'confirm' => 'shop']));

        $this->assertTrue($result['ok']);
        $this->assertSame('Dropped database shop', $result['message']);
    }

    public function testItAcceptsTheLiteralOneForAPlainConfirmation(): void
    {
        $privilege = \Mockery::mock(DashboardPrivilege::class);
        $privilege->shouldReceive('run')->once()->with('service.stop', ['service' => 'nginx'])->andReturn(
            ['ok' => true, 'message' => 'Stopped nginx', 'data' => []]
        );

        $api = $this->apiWith(privilege: $this->asPrivilege($privilege));

        $result = $api->dispatch($this->request('service.stop', ['service' => 'nginx', 'confirm' => '1']));

        $this->assertTrue($result['ok']);
    }

    // ------------------------------------------------------------ job dispatch

    public function testItQueuesABackgroundJobInsteadOfRunningItInline(): void
    {
        $this->jobs->shouldReceive('start')->once()->with('backup.create', ['with_db' => true])->andReturn(
            ['id' => str_repeat('a', 32), 'status' => 'queued']
        );

        $result = $this->api->dispatch($this->request('backup.create', ['with_db' => true]));

        $this->assertTrue($result['ok']);
        $this->assertSame(str_repeat('a', 32), $result['job']);
    }

    public function testItNeverQueuesAReadOnlyAction(): void
    {
        foreach ($this->api->catalog() as $action) {
            if ($action['tier'] === DashboardApi::TIER_READ) {
                $this->assertFalse($action['job'], $action['slug']);
            }
        }
    }

    // -------------------------------------------------------- privileged tier

    public function testItHandsAPrivilegedActionToTheHelper(): void
    {
        $privilege = \Mockery::mock(DashboardPrivilege::class);
        $privilege->shouldReceive('run')->once()->with('service.restart', ['service' => 'nginx'])->andReturn(
            ['ok' => true, 'message' => 'Restarted nginx', 'data' => []]
        );

        $result = $this->apiWith(privilege: $this->asPrivilege($privilege))->dispatch($this->request('service.restart', ['service' => 'nginx']));

        $this->assertTrue($result['ok']);
        $this->assertSame('Restarted nginx', $result['message']);
    }

    public function testItPassesTheHelpersOwnFailureThrough(): void
    {
        $privilege = \Mockery::mock(DashboardPrivilege::class);
        $privilege->shouldReceive('run')->once()->andReturn(
            ['ok' => false, 'message' => 'Privileged actions are not available.', 'data' => []]
        );

        $result = $this->apiWith(privilege: $this->asPrivilege($privilege))->dispatch($this->request('service.restart', ['service' => 'nginx']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not available', $result['message']);
    }

    public function testItRefusesToPerformAPrivilegedActionFromATerminal(): void
    {
        // performPrivileged() is reachable from the command line. Only the
        // helper's own child process may use it, so it cannot be turned into a
        // way to escalate a normal session.
        $this->expectException(\Valet\Exceptions\DashboardActionException::class);

        $this->api->performPrivileged('service.restart', ['service' => 'nginx']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Build an API instance with a few collaborators swapped out.
     *
     * The parameters are typed rather than collected in an array so that a
     * mistake at a call site is a type error, not a wrong mock.
     */
    private function apiWith(
        ?Mysql $mysql = null,
        ?Postgres $postgres = null,
        ?DashboardPrivilege $privilege = null,
        ?ServiceRegistry $registry = null,
        ?Snapshot $snapshot = null,
        ?Backup $backup = null
    ): DashboardApi {
        /** @var Configuration $config */
        $config = \Mockery::mock(Configuration::class);
        /** @var Filesystem $files */
        $files = \Mockery::mock(Filesystem::class);
        /** @var SiteLink $siteLink */
        $siteLink = \Mockery::mock(SiteLink::class);
        /** @var ServiceRegistry $registry */
        $registry = $registry ?? \Mockery::mock(ServiceRegistry::class);
        /** @var Mysql $mysql */
        $mysql = $mysql ?? \Mockery::mock(Mysql::class);
        /** @var Postgres $postgres */
        $postgres = $postgres ?? \Mockery::mock(Postgres::class);
        /** @var Snapshot $snapshot */
        $snapshot = $snapshot ?? \Mockery::mock(Snapshot::class);
        /** @var Backup $backup */
        $backup = $backup ?? \Mockery::mock(Backup::class);
        /** @var Cache $cache */
        $cache = \Mockery::mock(Cache::class);
        /** @var Addon $addon */
        $addon = \Mockery::mock(Addon::class);
        /** @var Node $node */
        $node = \Mockery::mock(Node::class);
        /** @var Log $log */
        $log = \Mockery::mock(Log::class);
        /** @var Certificate $certificate */
        $certificate = \Mockery::mock(Certificate::class);
        /** @var Diagnose $diagnose */
        $diagnose = \Mockery::mock(Diagnose::class);
        /** @var Health $health */
        $health = \Mockery::mock(Health::class);
        /** @var DashboardPrivilege $privilege */
        $privilege = $privilege ?? \Mockery::mock(DashboardPrivilege::class);
        /** @var DashboardJob $jobs */
        $jobs = $this->jobs;

        return new DashboardApi(
            $config,
            $files,
            $siteLink,
            $registry,
            $mysql,
            $postgres,
            $snapshot,
            $backup,
            $cache,
            $addon,
            $node,
            $log,
            $certificate,
            $diagnose,
            $health,
            $privilege,
            $jobs
        );
    }

    /**
     * Mockery types a mock as MockInterface, but it is a real instance of the
     * class it was made from. These keep that cast in one place.
     */
    private function asPrivilege(MockInterface $mock): DashboardPrivilege
    {
        /** @var DashboardPrivilege */
        return $mock;
    }

    private function asMysql(MockInterface $mock): Mysql
    {
        /** @var Mysql */
        return $mock;
    }
}
