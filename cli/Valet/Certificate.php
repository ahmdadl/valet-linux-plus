<?php

namespace Valet;

use ConsoleComponents\Writer;
use DomainException;
use Valet\Facades\Configuration as ConfigurationFacade;
use Valet\Facades\Nginx as NginxFacade;
use Valet\Facades\ProjectContext as ProjectContextFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

class Certificate
{
    public const RENEW_WITHIN_DAYS = 30;

    public function __construct(
        public Filesystem $files,
        public CommandLine $cli,
        public Configuration $config
    ) {
    }

    /**
     * List secured site certificates with expiry metadata.
     *
     * @return array<int, array{site: string, path: string, expires_at: string|null, days_remaining: int|null, valid: bool}>
     */
    public function list(): array
    {
        $rows = [];

        foreach (SiteSecureFacade::secured() as $site) {
            $rows[] = $this->info((string) $site);
        }

        return $rows;
    }

    /**
     * OpenSSL-parsed info for one site certificate.
     *
     * @return array{site: string, path: string, expires_at: string|null, days_remaining: int|null, valid: bool, subject?: string|null, issuer?: string|null}
     */
    public function info(string $site): array
    {
        $site = $this->normalizeSite($site);
        $path = SiteSecureFacade::certificateFilePath($site, 'crt');

        if (!$this->files->exists($path)) {
            return [
                'site' => $site,
                'path' => $path,
                'expires_at' => null,
                'days_remaining' => null,
                'valid' => false,
            ];
        }

        $parsed = $this->parseCertificate($this->files->get($path));

        return [
            'site' => $site,
            'path' => $path,
            'expires_at' => $parsed['expires_at'],
            'days_remaining' => $parsed['days_remaining'],
            'valid' => $parsed['valid'],
            'subject' => $parsed['subject'],
            'issuer' => $parsed['issuer'],
        ];
    }

    /**
     * Renew a site certificate when expired / near expiry, or always when $force.
     * When $site is null, renew all secured sites that need it.
     *
     * @return array<int, array{site: string, renewed: bool, reason: string}>
     */
    public function renew(?string $site = null, bool $force = false): array
    {
        $sites = $site !== null && $site !== ''
            ? [$this->normalizeSite($site)]
            : SiteSecureFacade::secured()->values()->all();

        $results = [];
        $renewedAny = false;

        foreach ($sites as $url) {
            $url = (string) $url;
            $info = $this->info($url);
            $days = $info['days_remaining'];
            $needsRenewal = $force
                || !$info['valid']
                || $days === null
                || $days < self::RENEW_WITHIN_DAYS;

            if (!$needsRenewal) {
                $results[] = [
                    'site' => $url,
                    'renewed' => false,
                    'reason' => sprintf('%d days remaining', $days),
                ];
                continue;
            }

            SiteSecureFacade::secure($url);
            $renewedAny = true;
            $reason = $force
                ? 'forced'
                : ($days === null ? 'missing or invalid' : sprintf('%d days remaining', $days));
            $results[] = [
                'site' => $url,
                'renewed' => true,
                'reason' => $reason,
            ];
        }

        if ($renewedAny) {
            NginxFacade::restart();
        }

        return $results;
    }

    /**
     * Install or check the Valet CA trust.
     *
     * @return array{ca_exists: bool, trusted: bool, ca_path: string, system_path: string, installed?: bool}
     */
    public function trust(bool $check = false): array
    {
        /** @var array{ca_exists: bool, trusted: bool, ca_path: string, system_path: string, installed?: bool} $result */
        $result = SiteSecureFacade::trustCaCertificate($check);

        return $result;
    }

    /**
     * CLI: list certificates.
     */
    public function runList(bool $json = false): void
    {
        $rows = $this->list();

        if ($json) {
            Writer::info((string) json_encode([
                'schema_version' => JsonSchema::VERSION,
                'certificates' => $rows,
                'timestamp' => gmdate('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if ($rows === []) {
            Writer::warn('No secured sites found.');

            return;
        }

        Writer::table(
            ['Site', 'Expires', 'Days Left', 'Valid'],
            array_map(function (array $row) {
                return [
                    $row['site'],
                    $row['expires_at'] ?? 'n/a',
                    $row['days_remaining'] ?? 'n/a',
                    $row['valid'] ? 'yes' : 'no',
                ];
            }, $rows)
        );
    }

    public function runInfo(?string $site = null): void
    {
        $site = $site ?: (string) (ProjectContextFacade::site() ?: basename((string) getcwd()));
        $site = $this->normalizeSite($site);
        $info = $this->info($site);

        Writer::info((string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function runRenew(?string $site = null, bool $force = false): void
    {
        $results = $this->renew($site, $force);
        if ($results === []) {
            Writer::warn('No certificates to renew.');

            return;
        }

        foreach ($results as $result) {
            Writer::info(sprintf(
                '%s %s (%s)',
                $result['renewed'] ? 'Renewed' : 'Skipped',
                $result['site'],
                $result['reason']
            ));
        }
    }

    public function runTrust(bool $check = false): void
    {
        try {
            $result = $this->trust($check);
        } catch (DomainException $e) {
            Writer::error($e->getMessage());

            return;
        }

        if ($check) {
            Writer::info(sprintf(
                'CA exists: %s | Trusted in system store: %s',
                $result['ca_exists'] ? 'yes' : 'no',
                $result['trusted'] ? 'yes' : 'no'
            ));
            Writer::info('CA path: ' . $result['ca_path']);
            Writer::info('System path: ' . $result['system_path']);
            if (!$result['trusted']) {
                Writer::warn('Run `valet trust` (without --check) to install the CA. Restart browsers afterward.');
            }

            return;
        }

        Writer::info('Valet CA installed into the system trust store.');
        Writer::info('If browsers still warn, restart them or import the CA manually from: ' . $result['ca_path']);
    }

    /**
     * @return array{expires_at: string|null, days_remaining: int|null, valid: bool, subject: string|null, issuer: string|null}
     */
    public function parseCertificate(string $pem): array
    {
        $cert = @openssl_x509_parse($pem);
        if (!is_array($cert) || !isset($cert['validTo_time_t'])) {
            return [
                'expires_at' => null,
                'days_remaining' => null,
                'valid' => false,
                'subject' => null,
                'issuer' => null,
            ];
        }

        $expiresTs = (int) $cert['validTo_time_t'];
        $now = time();
        $days = (int) floor(($expiresTs - $now) / 86400);
        $subject = null;
        if (isset($cert['subject']) && is_array($cert['subject'])) {
            $subject = isset($cert['subject']['CN']) ? (string) $cert['subject']['CN'] : null;
        }
        $issuer = null;
        if (isset($cert['issuer']) && is_array($cert['issuer'])) {
            $issuer = isset($cert['issuer']['CN']) ? (string) $cert['issuer']['CN'] : null;
        }

        return [
            'expires_at' => gmdate('c', $expiresTs),
            'days_remaining' => $days,
            'valid' => $expiresTs > $now,
            'subject' => $subject,
            'issuer' => $issuer,
        ];
    }

    private function normalizeSite(string $site): string
    {
        $site = trim($site);
        if ($site === '') {
            $site = (string) (ProjectContextFacade::site() ?: basename((string) getcwd()));
        }

        $domain = ConfigurationFacade::get('domain', 'test');
        $domain = is_scalar($domain) ? (string) $domain : 'test';

        if (!str_contains($site, '.')) {
            $site = $site . '.' . $domain;
        }

        return basename($site);
    }
}
