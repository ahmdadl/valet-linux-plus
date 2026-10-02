<?php

namespace Valet\Tests\Unit;

use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\DashboardPrivilege;
use Valet\Filesystem;
use Valet\Tests\TestCase;

/**
 * The client half of the privileged helper.
 *
 * Nothing here runs as root, so what is testable is the decision to trust the
 * helper at all: a NOPASSWD rule pointing at something the invoking user can
 * edit would be a privilege escalation rather than a feature.
 */
class DashboardPrivilegeTest extends TestCase
{
    private MockInterface $files;

    private MockInterface $commandLine;

    private DashboardPrivilege $privilege;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = \Mockery::mock(Filesystem::class);
        $this->commandLine = \Mockery::mock(CommandLine::class);

        /** @var Filesystem $files */
        $files = $this->files;
        /** @var CommandLine $commandLine */
        $commandLine = $this->commandLine;

        $this->privilege = new DashboardPrivilege($files, $commandLine);
    }

    public function testItIsNotEnabledUntilBothFilesAreInPlace(): void
    {
        // A helper without a sudoers rule is unreachable; a rule without a
        // trusted helper would be dangerous. Both are required.
        $this->files->shouldReceive('exists')->with(DashboardPrivilege::HELPER_PATH)->andReturn(false);
        $this->files->shouldReceive('exists')->with(DashboardPrivilege::SUDOERS_PATH)->andReturn(true);

        $this->assertFalse($this->privilege->installed());
    }

    public function testItRefusesToRunWithoutTheHelperInstalled(): void
    {
        $this->files->shouldReceive('exists')->with(DashboardPrivilege::HELPER_PATH)->andReturn(false);
        $this->files->shouldReceive('exists')->with(DashboardPrivilege::SUDOERS_PATH)->andReturn(false);

        $result = $this->privilege->run('service.restart', ['service' => 'nginx']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('dashboard:privileges install', $result['message']);
    }

    public function testItRefusesAVerbItDoesNotOwn(): void
    {
        // run() is reachable from any request, so an unknown slug must never
        // reach the helper as a root request.
        $result = $this->privilege->run('shell.exec', []);

        $this->assertFalse($result['ok']);
        $this->assertSame('Unknown privileged action.', $result['message']);
    }

    public function testItNamesEveryRootTierActionItSupports(): void
    {
        foreach (DashboardPrivilege::VERBS as $slug) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]*(\.[a-z0-9]+)*$/', $slug);
        }

        $this->assertContains('service.restart', DashboardPrivilege::VERBS);
        $this->assertContains('php.switch', DashboardPrivilege::VERBS);
        $this->assertNotContains('db.drop', DashboardPrivilege::VERBS);
    }

    public function testItReportsWhatIsInstalled(): void
    {
        $this->files->shouldReceive('exists')->with(DashboardPrivilege::HELPER_PATH)->andReturn(false);
        $this->files->shouldReceive('exists')->with(DashboardPrivilege::SUDOERS_PATH)->andReturn(false);
        $this->files->shouldReceive('get')->with(DashboardPrivilege::SUDOERS_PATH)->andReturn('');

        $status = $this->privilege->status();

        $this->assertFalse($status['enabled']);
        $this->assertFalse($status['helper_installed']);
        $this->assertFalse($status['sudoers_installed']);
        $this->assertSame([], $status['granted_users']);
        $this->assertSame(DashboardPrivilege::VERBS, $status['verbs']);
        $this->assertStringContainsString('dashboard:privileges install', $status['install_command']);
    }

    public function testItListsWhoTheSudoersRuleGrants(): void
    {
        $this->files->shouldReceive('exists')->with(DashboardPrivilege::HELPER_PATH)->andReturn(false);
        $this->files->shouldReceive('exists')->with(DashboardPrivilege::SUDOERS_PATH)->andReturn(true);
        $this->files->shouldReceive('get')->with(DashboardPrivilege::SUDOERS_PATH)->andReturn(
            "# Managed by valet.\nDefaults:env_reset\nalice ALL=(root) NOPASSWD: /usr/local/libexec/valet-dashboard-helper \"\"\nbob ALL=(root) NOPASSWD: /usr/local/libexec/valet-dashboard-helper \"\"\n"
        );

        $status = $this->privilege->status();

        $this->assertSame(['alice', 'bob'], $status['granted_users']);
    }

    public function testItNamesTheInvokingUserAndNotRoot(): void
    {
        $method = new \ReflectionMethod(DashboardPrivilege::class, 'installUserFor');
        $method->setAccessible(true);

        $original = $_SERVER['SUDO_USER'] ?? null;
        $_SERVER['SUDO_USER'] = 'alice';

        try {
            // A rule for root would let anybody who reached the dashboard
            // restart services, so sudo's invoking user is the one to name.
            $this->assertSame('alice', $method->invoke($this->privilege, 0));

            // SUDO_USER is only believed when the process really is root;
            // otherwise any caller could set it and have a rule written for an
            // account of their choosing.
            $this->assertSame(\Valet\user(), $method->invoke($this->privilege, 1000));

            unset($_SERVER['SUDO_USER']);
            $this->assertSame(\Valet\user(), $method->invoke($this->privilege, 0));
        } finally {
            if ($original === null) {
                unset($_SERVER['SUDO_USER']);
            } else {
                $_SERVER['SUDO_USER'] = $original;
            }
        }
    }

    public function testItExplainsItselfWhenTheHelperSaysNothing(): void
    {
        // A helper that dies without a JSON answer must not be mistaken for a
        // success, so the reply is always one or the other.
        $result = $this->invoke('decode', [[
            'output' => '',
            'errors' => 'sudo: a password is required',
            'exitCode' => 1,
        ], 'service.restart']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('service.restart', $result['message']);
        $this->assertStringContainsString('password is required', $result['message']);
    }

    public function testItReadsAStructuredReplyFromTheHelper(): void
    {
        $result = $this->invoke('decode', [[
            'output' => '{"ok":true,"message":"Restarted nginx","data":{"ran":1}}',
            'errors' => '',
            'exitCode' => 0,
        ], 'service.restart']);

        $this->assertTrue($result['ok']);
        $this->assertSame('Restarted nginx', $result['message']);
        $this->assertSame(['ran' => 1], $result['data']);
    }

    public function testItTreatsAMalformedReplyAsAFailure(): void
    {
        $result = $this->invoke('decode', [[
            'output' => 'not json at all',
            'errors' => '',
            'exitCode' => 0,
        ], 'service.restart']);

        $this->assertFalse($result['ok']);
    }

    public function testItSendsTheWorkOnStdinAndNeverAsArguments(): void
    {
        // The sudoers rule pins the helper to an empty argument list, so
        // anything on the command line would be denied by sudo.
        $method = new \ReflectionMethod(DashboardPrivilege::class, 'run');
        $method->setAccessible(true);

        $helper = VALET_ROOT_PATH . '/cli/scripts/valet-dashboard-helper';

        $this->assertTrue(is_file($helper), 'the helper template should ship with Valet');
        $this->assertStringContainsString('@@VALET_ENTRY@@', (string) file_get_contents($helper));
        $this->assertStringContainsString('@@VALET_USER@@', (string) file_get_contents($helper));

        // And the rule itself must forbid arguments.
        $sudoers = new \ReflectionMethod(DashboardPrivilege::class, 'sudoersContents');
        $sudoers->setAccessible(true);

        $contents = $this->sudoersFor('alice');

        $this->assertStringContainsString(DashboardPrivilege::HELPER_PATH . ' ""', $contents);
        $this->assertStringContainsString('alice ALL=(root) NOPASSWD:', $contents);
    }

    public function testItWillNotWriteARuleForAnAccountNameItCannotSpell(): void
    {
        // sudoers has its own grammar; an unexpected name must not become a
        // rule that grants somebody else.
        foreach (['alice; rm -rf /', 'ALL', 'al ice', '', 'a b'] as $name) {
            $contents = $this->sudoersFor($name);

            $this->assertStringContainsString(
                'root ALL=(root) NOPASSWD:',
                $contents,
                $name
            );
        }
    }

    /**
     * Call a private method and get a well-typed answer back.
     *
     * @param  array<int, mixed>  $arguments
     * @return array{ok: bool, message: string, data: array<string|int, mixed>}
     */
    private function invoke(string $method, array $arguments = []): array
    {
        $reflection = new \ReflectionMethod(DashboardPrivilege::class, $method);
        $reflection->setAccessible(true);

        /** @var array{ok: bool, message: string, data: array<string|int, mixed>} */
        return $reflection->invokeArgs($this->privilege, $arguments);
    }

    /**
     * The sudoers fragment that would be written for an account.
     */
    private function sudoersFor(string $user): string
    {
        $reflection = new \ReflectionMethod(DashboardPrivilege::class, 'sudoersContents');
        $reflection->setAccessible(true);

        $contents = $reflection->invoke($this->privilege, $user);

        return is_string($contents) ? $contents : '';
    }
}
