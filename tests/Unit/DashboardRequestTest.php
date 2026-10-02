<?php

namespace Valet\Tests\Unit;

use Valet\DashboardRequest;
use Valet\Tests\TestCase;

/**
 * The security gates on an incoming dashboard request.
 *
 * These rules are the only thing standing between a page the user happens to
 * have open and a root shell, so each case is pinned here rather than left to
 * integration testing.
 */
class DashboardRequestTest extends TestCase
{
    /**
     * @param  array<string, string|null>  $overrides
     * @param  array<string, mixed>  $payload
     */
    private function request(array $overrides = [], array $payload = []): DashboardRequest
    {
        return new DashboardRequest(
            $overrides['method'] ?? 'POST',
            $overrides['slug'] ?? 'service.restart',
            $overrides['host'] ?? 'valet.test',
            $overrides['remoteAddress'] ?? '127.0.0.1',
            $overrides['origin'] ?? null,
            $overrides['referer'] ?? null,
            $overrides['csrfCookie'] ?? null,
            $overrides['csrfHeader'] ?? null,
            $payload
        );
    }

    private function token(): string
    {
        return str_repeat('a', 64);
    }

    public function testItTreatsEveryLoopbackFormAsLocal(): void
    {
        foreach (['127.0.0.1', '::1', '::ffff:127.0.0.1'] as $address) {
            $this->assertTrue($this->request(['remoteAddress' => $address])->isLoopback());
        }
    }

    public function testItRejectsRemoteAndMissingAddresses(): void
    {
        $this->assertFalse($this->request(['remoteAddress' => '192.168.1.20'])->isLoopback());
        $this->assertFalse($this->request(['remoteAddress' => '10.0.0.5'])->isLoopback());
        $this->assertFalse($this->request(['remoteAddress' => ''])->isLoopback());

        // A hostname that merely looks local is not local.
        $this->assertFalse($this->request(['remoteAddress' => 'localhost'])->isLoopback());
    }

    public function testItRequiresBothHalvesOfTheCsrfToken(): void
    {
        $token = $this->token();

        $this->assertTrue($this->request(['csrfCookie' => $token, 'csrfHeader' => $token])->hasValidCsrf());

        $this->assertFalse($this->request(['csrfCookie' => $token])->hasValidCsrf());
        $this->assertFalse($this->request(['csrfHeader' => $token])->hasValidCsrf());
        $this->assertFalse($this->request([])->hasValidCsrf());
    }

    public function testItRejectsAMismatchedCsrfToken(): void
    {
        $this->assertFalse($this->request([
            'csrfCookie' => $this->token(),
            'csrfHeader' => str_repeat('b', 64),
        ])->hasValidCsrf());
    }

    public function testItRejectsATriviallyShortCsrfToken(): void
    {
        // A one-character token is trivially guessable, so length is part of
        // the check rather than a formatting nicety.
        $this->assertFalse($this->request([
            'csrfCookie' => 'a',
            'csrfHeader' => 'a',
        ])->hasValidCsrf());
    }

    public function testItAcceptsARequestWithNoBrowserOrigin(): void
    {
        // curl sends neither header. It still has to pass the CSRF check, so
        // this does not open a hole.
        $this->assertTrue($this->request([])->hasValidOrigin());
    }

    public function testItAcceptsAMatchingOrigin(): void
    {
        $this->assertTrue($this->request(['origin' => 'http://valet.test'])->hasValidOrigin());
        $this->assertTrue($this->request(['origin' => 'http://www.valet.test'])->hasValidOrigin());
        $this->assertTrue($this->request(['referer' => 'http://valet.test/'])->hasValidOrigin());
    }

    public function testItRejectsACrossOriginRequest(): void
    {
        $this->assertFalse($this->request(['origin' => 'http://evil.test'])->hasValidOrigin());
        $this->assertFalse($this->request(['referer' => 'https://notvalet.test/attack'])->hasValidOrigin());

        // A subdomain of ours is still somebody else's origin for this host.
        $this->assertFalse($this->request(['origin' => 'http://other.valet.test'])->hasValidOrigin());
    }

    public function testItFallsBackToTheRefererWhenTheOriginIsForeign(): void
    {
        $this->assertFalse($this->request([
            'origin' => 'http://evil.test',
            'referer' => 'http://valet.test/',
        ])->hasValidOrigin());
    }

    public function testItStripsTheConfirmationFromTheParameters(): void
    {
        $request = $this->request([], ['service' => 'nginx', 'confirm' => '1']);

        $this->assertSame(['service' => 'nginx'], $request->params());
        $this->assertSame('1', $request->confirmation());
    }

    public function testItReportsNoConfirmationWhenAbsent(): void
    {
        $this->assertNull($this->request([], ['service' => 'nginx'])->confirmation());
        $this->assertNull($this->request([], ['confirm' => ''])->confirmation());
        $this->assertNull($this->request([], ['confirm' => true])->confirmation());
    }

    public function testItNamesTheCsrfCookieConstantly(): void
    {
        // The page reads this cookie out of document.cookie, so the name is
        // part of the contract between the two halves.
        $this->assertSame('valet_csrf', DashboardRequest::csrfCookieName());
    }
}
