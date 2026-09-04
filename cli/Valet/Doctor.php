<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Contracts\ServiceManager;
use Valet\Facades\DnsMasq as DnsMasqFacade;
use Valet\Facades\Nginx as NginxFacade;
use Valet\Facades\ServiceRegistry as ServiceRegistryFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

class Doctor
{
    /**
     * Core Valet services that should normally be running.
     *
     * @var string[]
     */
    private const CORE_SERVICES = ['nginx', 'dnsmasq', 'php', 'mailpit'];

    public function __construct(
        public Diagnose $diagnose,
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $config,
        public ServiceManager $sm
    ) {
    }

    /**
     * Run doctor: always diagnose; optionally apply safe fixes.
     *
     * @return array{diagnosis: array<string, mixed>, actions: array<int, array{id: string, description: string, status: string, detail?: string}>}
     */
    public function run(bool $fix = false, bool $dryRun = false, bool $json = false): array
    {
        $diagnosis = $this->diagnose->gather();
        $actions = [];

        if ($fix || $dryRun) {
            $actions = $this->planFixes($diagnosis);

            if ($fix && !$dryRun) {
                $actions = $this->applyFixes($actions);
            } else {
                foreach ($actions as &$action) {
                    $action['status'] = 'planned';
                }
                unset($action);
            }
        }

        $result = [
            'diagnosis' => $diagnosis,
            'actions' => $actions,
        ];

        if ($json) {
            Writer::info((string) json_encode(
                JsonSchema::envelope('diagnose', $result),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return $result;
        }

        $this->diagnose->run(false);

        if ($fix || $dryRun) {
            Writer::info($dryRun ? 'Doctor dry-run (no changes applied)' : 'Doctor repairs');
            Writer::info('');

            if ($actions === []) {
                Writer::info('No repair actions needed.');
            }

            foreach ($actions as $action) {
                Writer::info(sprintf(
                    '[%s] %s%s',
                    strtoupper($action['status']),
                    $action['description'],
                    isset($action['detail']) ? ' — ' . $action['detail'] : ''
                ));
            }
        }

        return $result;
    }

    /**
     * Build a list of safe repair actions from a diagnosis snapshot.
     *
     * @param array<string, mixed> $diagnosis
     * @return array<int, array{id: string, description: string, status: string, detail?: string}>
     */
    public function planFixes(array $diagnosis): array
    {
        $actions = [];

        $nginx = is_array($diagnosis['nginx'] ?? null) ? $diagnosis['nginx'] : [];
        $nginxTestRaw = $nginx['nginx -t'] ?? '';
        $nginxTest = is_scalar($nginxTestRaw) ? (string) $nginxTestRaw : '';
        if ($nginxTest !== '' && !str_contains(strtolower($nginxTest), 'successful') && $nginxTest !== 'unknown') {
            $actions[] = [
                'id' => 'nginx_config',
                'description' => 'Regenerate Nginx configuration and restart Nginx',
                'status' => 'pending',
            ];
        }

        $dns = is_array($diagnosis['dns'] ?? null) ? $diagnosis['dns'] : [];
        $dnsmasqRaw = $dns['DnsMasq'] ?? '';
        $dnsmasq = is_scalar($dnsmasqRaw) ? (string) $dnsmasqRaw : '';
        if ($dnsmasq === 'inactive' || $dnsmasq === 'unknown') {
            $actions[] = [
                'id' => 'dnsmasq',
                'description' => 'Rewrite DnsMasq config and restart the service',
                'status' => 'pending',
            ];
        }

        /** @var array<string, string> $services */
        $services = is_array($diagnosis['services'] ?? null) ? $diagnosis['services'] : [];
        foreach (self::CORE_SERVICES as $service) {
            if (($services[$service] ?? '') === 'inactive') {
                $actions[] = [
                    'id' => 'start_' . $service,
                    'description' => sprintf('Start inactive service [%s]', $service),
                    'status' => 'pending',
                ];
            }
        }

        if (!$this->files->isDir(VALET_HOME_PATH) || !is_writable(VALET_HOME_PATH)) {
            $actions[] = [
                'id' => 'permissions',
                'description' => 'Repair ownership/permissions on VALET_HOME_PATH',
                'status' => 'pending',
            ];
        }

        foreach ($this->missingCertificates() as $site) {
            $actions[] = [
                'id' => 'cert_' . $site,
                'description' => sprintf('Re-secure site [%s] (missing certificate)', $site),
                'status' => 'pending',
                'detail' => $site,
            ];
        }

        $portRaw = $this->config->get('port', 80);
        $port = is_scalar($portRaw) ? (string) $portRaw : '80';
        $conflict = $this->portConflictDetail((int) $port);
        if ($conflict !== null) {
            $actions[] = [
                'id' => 'port_conflict',
                'description' => sprintf('Report process using port %s (non-destructive)', $port),
                'status' => 'pending',
                'detail' => $conflict,
            ];
        }

        return $actions;
    }

    /**
     * Execute planned fixes that are safe by default.
     *
     * @param array<int, array{id: string, description: string, status: string, detail?: string}> $actions
     * @return array<int, array{id: string, description: string, status: string, detail?: string}>
     */
    public function applyFixes(array $actions): array
    {
        foreach ($actions as &$action) {
            try {
                $this->applyOne($action);
                if ($action['id'] === 'port_conflict') {
                    $action['status'] = 'reported';
                } else {
                    $action['status'] = 'fixed';
                }
            } catch (\Throwable $e) {
                $action['status'] = 'failed';
                $action['detail'] = $e->getMessage();
            }
        }
        unset($action);

        return $actions;
    }

    /**
     * @param array{id: string, description: string, status: string, detail?: string} $action
     */
    private function applyOne(array $action): void
    {
        $id = $action['id'];

        if ($id === 'nginx_config') {
            NginxFacade::installServer();
            $test = trim($this->cli->run('nginx -t 2>&1'));
            if ($test !== '' && !str_contains(strtolower($test), 'successful')) {
                throw new \RuntimeException($test);
            }
            NginxFacade::restart();

            return;
        }

        if ($id === 'dnsmasq') {
            $domainRaw = $this->config->get('domain', 'test');
            $domain = is_scalar($domainRaw) ? (string) $domainRaw : 'test';
            DnsMasqFacade::updateDomain($domain);

            return;
        }

        if (str_starts_with($id, 'start_')) {
            $service = substr($id, strlen('start_'));
            ServiceRegistryFacade::start([$service]);

            return;
        }

        if ($id === 'permissions') {
            $user = user();
            $this->files->ensureDirExists(VALET_HOME_PATH, $user);
            $this->cli->run(sprintf(
                'chmod -R u+rwX %s',
                escapeshellarg(VALET_HOME_PATH)
            ));

            return;
        }

        if (str_starts_with($id, 'cert_')) {
            $site = $action['detail'] ?? substr($id, strlen('cert_'));
            SiteSecureFacade::secure((string) $site);

            return;
        }

        if ($id === 'port_conflict') {
            // Reporting only — never kill processes without an explicit --force path.
            return;
        }

        throw new \InvalidArgumentException(sprintf('Unknown doctor action [%s]', $id));
    }

    /**
     * Sites that have an Nginx config but no certificate while listed as secured.
     *
     * @return string[]
     */
    private function missingCertificates(): array
    {
        $missing = [];

        try {
            $secured = SiteSecureFacade::secured();
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($secured as $site) {
            $crt = VALET_HOME_PATH . '/Certificates/' . $site . '.crt';
            if (!$this->files->exists($crt)) {
                $missing[] = $site;
            }
        }

        return $missing;
    }

    private function portConflictDetail(int $port): ?string
    {
        if ($port <= 0) {
            return null;
        }

        try {
            if ($this->sm->isActive('nginx')) {
                return null;
            }
        } catch (\Throwable $e) {
            // continue to inspect listeners
        }

        try {
            $output = trim($this->cli->run(sprintf(
                'ss -ltnp "( sport = :%d )" 2>/dev/null || true',
                $port
            )));
        } catch (\Throwable $e) {
            return null;
        }

        if ($output === '' || !str_contains($output, ':' . $port)) {
            return null;
        }

        // Only report when something other than nginx appears to own the port.
        if (str_contains(strtolower($output), 'nginx')) {
            return null;
        }

        return $output;
    }
}
