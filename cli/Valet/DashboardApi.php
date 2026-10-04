<?php

namespace Valet;

use Valet\Exceptions\DashboardActionException;
use Valet\Facades\DnsMasq as DnsMasqFacade;
use Valet\Facades\Nginx as NginxFacade;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\SiteIsolate as SiteIsolateFacade;
use Valet\Facades\SiteProxy as SiteProxyFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

/**
 * Registry and dispatcher for every action the web dashboard can perform.
 *
 * The dashboard is the only part of Valet reachable over HTTP, so it is also
 * the only part that must treat input as hostile. Three rules keep that safe:
 *
 *  1. Nothing is dispatched that is not in the ACTIONS allowlist below. There
 *     is no code path that accepts a command name from the client.
 *  2. Parameters are validated and coerced against a per-action schema before
 *     a handler ever sees them.
 *  3. Anything that mutates state must be a POST from loopback with a valid
 *     double-submit CSRF token and a same-origin Origin/Referer, and anything
 *     destructive must additionally echo its target back for confirmation.
 *
 * Actions are grouped into three tiers:
 *
 *  - read: observational, safe from anywhere the dashboard is reachable.
 *  - user: mutates the developer's own configuration/home directory.
 *  - root: needs elevated privileges and is routed through the root-owned
 *          privileged helper (see DashboardPrivilege) instead of running
 *          inline, so no user-writable code is ever executed as root.
 */
class DashboardApi
{
    public const TIER_READ = 'read';
    public const TIER_USER = 'user';
    public const TIER_ROOT = 'root';

    /**
     * Directory database exports are written to.
     *
     * Mysql/Postgres::exportDatabase() write relative to the working
     * directory, so the dashboard runs it inside this folder rather than
     * inside whatever site happens to be serving the request.
     */
    public const EXPORT_DIR = VALET_HOME_PATH . '/Exports';

    /**
     * Directory backup archives are read from.
     */
    public const BACKUP_DIR = Backup::BACKUP_DIR;

    /**
     * Built-in service names the dashboard may act on.
     *
     * Deliberately a fixed list rather than ServiceRegistry::allServices() so
     * that a service registered through config.json can never become a target
     * for a privileged action.
     */
    public const SERVICE_NAMES = ['dnsmasq', 'nginx', 'php', 'mailpit', 'mysql', 'redis', 'postgres'];

    /**
     * The user-privilege implementation behind each root-tier action.
     *
     * The privileged helper runs Valet as the unprivileged install user and
     * replays the privileged commands it attempts as root, so this mapping is
     * what gives the helper its behaviour without the helper knowing anything
     * about Valet internals.
     *
     * @var array<string, string>
     */
    private const PERFORMERS = [
        'service.start' => 'performServiceStart',
        'service.stop' => 'performServiceStop',
        'service.restart' => 'performServiceRestart',
        'site.secure' => 'performSiteSecure',
        'site.unsecure' => 'performSiteUnsecure',
        'site.proxy' => 'performSiteProxy',
        'site.unproxy' => 'performSiteUnproxy',
        'site.isolate' => 'performSiteIsolate',
        'site.unisolate' => 'performSiteUnisolate',
        'domain.set' => 'performDomainSet',
        'port.set' => 'performPortSet',
        'trust.ca' => 'performTrustCa',
        'cert.renew' => 'performCertRenew',
        'php.switch' => 'performPhpSwitch',
        'xdebug.enable' => 'performXdebugEnable',
        'xdebug.disable' => 'performXdebugDisable',
    ];

    /**
     * The complete action allowlist.
     *
     * This is the only place an action may be added. Each entry declares:
     *   label        human readable name shown in the UI
     *   tier         read | user | root
     *   destructive  whether the action destroys state
     *   confirm      null | true (click OK) | param name (must echo the value)
     *   job          run out-of-process and poll instead of inline
     *   params       per-parameter schema (type/required/default/pattern/in/…)
     *
     * The method that performs an action lives in handlerFor() rather than
     * here, so the metadata stays data and the code stays code.
     *
     * @var array<string, array{
     *     label: string,
     *     tier: string,
     *     destructive: bool,
     *     confirm: true|string|null,
     *     job: bool,
     *     params: array<string, array<string, mixed>>
     * }>
     */
    private const ACTIONS = [
        // ----------------------------------------------------------- read ---
        'logs.view' => [
            'label' => 'View logs',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'service' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9_.-]{1,40}$/'],
                'lines' => ['type' => 'int', 'required' => false, 'default' => 100, 'min' => 10, 'max' => 1000],
                'grep' => ['type' => 'string', 'required' => false, 'default' => '', 'maxLength' => 120],
            ],
        ],
        'diagnose.run' => [
            'label' => 'Run diagnostics',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'health.run' => [
            'label' => 'Service health',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'services.list' => [
            'label' => 'Service status',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'databases.list' => [
            'label' => 'MySQL databases',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'pg.databases.list' => [
            'label' => 'PostgreSQL databases',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'snapshots.list' => [
            'label' => 'Snapshots',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'site' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => '',
                    'maxLength' => 64,
                    'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/',
                ],
            ],
        ],
        'backups.list' => [
            'label' => 'Backups',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'profiles.list' => [
            'label' => 'Profiles',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'certs.list' => [
            'label' => 'Certificates',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'addons.list' => [
            'label' => 'Addons',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'cache.status' => [
            'label' => 'Cache status',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],
        'node.current' => [
            'label' => 'Node version',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [],
        ],

        // ----------------------------------------------------------- user ---
        'site.link' => [
            'label' => 'Link site',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
                'path' => ['type' => 'path', 'required' => true],
            ],
        ],
        'site.unlink' => [
            'label' => 'Unlink site',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
            ],
        ],
        'site.park' => [
            'label' => 'Park directory',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'path' => ['type' => 'path', 'required' => true],
            ],
        ],
        'site.forget' => [
            'label' => 'Forget directory',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'path',
            'job' => false,
            'params' => [
                'path' => ['type' => 'path', 'required' => true],
            ],
        ],
        'db.create' => [
            'label' => 'Create database',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
            ],
        ],
        'db.drop' => [
            'label' => 'Drop database',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
            ],
        ],
        'db.reset' => [
            'label' => 'Reset database',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
            ],
        ],
        'db.import' => [
            'label' => 'Import database',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => true,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
                'file' => ['type' => 'path', 'required' => true],
            ],
        ],
        'db.export' => [
            'label' => 'Export database',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => true,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
                'sql' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'db.url' => [
            'label' => 'Database URL',
            'tier' => self::TIER_READ,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
            ],
        ],
        'pg.create' => [
            'label' => 'Create PostgreSQL database',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
            ],
        ],
        'pg.drop' => [
            'label' => 'Drop PostgreSQL database',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
            ],
        ],
        'pg.reset' => [
            'label' => 'Reset PostgreSQL database',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
            ],
        ],
        'pg.import' => [
            'label' => 'Import PostgreSQL database',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => true,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
                'file' => ['type' => 'path', 'required' => true],
            ],
        ],
        'pg.export' => [
            'label' => 'Export PostgreSQL database',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => true,
            'params' => [
                'name' => ['type' => 'database', 'required' => true],
                'sql' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'snapshot.create' => [
            'label' => 'Create snapshot',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => true,
            'params' => [
                'path' => ['type' => 'path', 'required' => true],
                'name' => ['type' => 'string', 'required' => false, 'default' => '', 'maxLength' => 64],
                'with_db' => ['type' => 'bool', 'required' => false, 'default' => false],
                'notes' => ['type' => 'string', 'required' => false, 'default' => '', 'maxLength' => 200],
            ],
        ],
        'snapshot.restore' => [
            'label' => 'Restore snapshot',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => true,
            'params' => [
                'path' => ['type' => 'path', 'required' => true],
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            ],
        ],
        'snapshot.delete' => [
            'label' => 'Delete snapshot',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'path' => ['type' => 'path', 'required' => true],
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            ],
        ],
        'backup.create' => [
            'label' => 'Create backup',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => true,
            'params' => [
                'with_db' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'backup.restore' => [
            'label' => 'Restore backup',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'archive',
            'job' => true,
            'params' => [
                'archive' => ['type' => 'archive', 'required' => true],
            ],
        ],
        'profile.use' => [
            'label' => 'Use profile',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
                'apply' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'profile.delete' => [
            'label' => 'Delete profile',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            ],
        ],
        'cache.clear' => [
            'label' => 'Clear caches',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => true,
            'job' => false,
            'params' => [
                'composer' => ['type' => 'bool', 'required' => false, 'default' => false],
                'npm' => ['type' => 'bool', 'required' => false, 'default' => false],
                'valet' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'addon.enable' => [
            'label' => 'Enable addon',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            ],
        ],
        'addon.disable' => [
            'label' => 'Disable addon',
            'tier' => self::TIER_USER,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            ],
        ],
        'node.use' => [
            'label' => 'Switch Node version',
            'tier' => self::TIER_USER,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'version' => ['type' => 'string', 'required' => true, 'pattern' => '/^[0-9]{1,3}(\.[0-9]{1,3}){0,2}$/'],
            ],
        ],

        // ----------------------------------------------------------- root ---
        'service.start' => [
            'label' => 'Start service',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'service' => ['type' => 'string', 'required' => true, 'in' => self::SERVICE_NAMES],
            ],
        ],
        'service.stop' => [
            'label' => 'Stop service',
            'tier' => self::TIER_ROOT,
            'destructive' => true,
            'confirm' => true,
            'job' => false,
            'params' => [
                'service' => ['type' => 'string', 'required' => true, 'in' => self::SERVICE_NAMES],
            ],
        ],
        'service.restart' => [
            'label' => 'Restart service',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'service' => ['type' => 'string', 'required' => true, 'in' => self::SERVICE_NAMES],
            ],
        ],
        'site.secure' => [
            'label' => 'Secure site',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
            ],
        ],
        'site.unsecure' => [
            'label' => 'Unsecure site',
            'tier' => self::TIER_ROOT,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
            ],
        ],
        'site.proxy' => [
            'label' => 'Proxy site',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
                'host' => ['type' => 'string', 'required' => true, 'maxLength' => 200, 'pattern' => '#^https?://[A-Za-z0-9._~:/?#\[\]@!$&\'()*+,;=%-]+$#'],
                'secure' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'site.unproxy' => [
            'label' => 'Remove proxy',
            'tier' => self::TIER_ROOT,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
            ],
        ],
        'site.isolate' => [
            'label' => 'Isolate site PHP',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
                'version' => ['type' => 'version', 'required' => true],
                'secure' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'site.unisolate' => [
            'label' => 'Remove PHP isolation',
            'tier' => self::TIER_ROOT,
            'destructive' => true,
            'confirm' => 'name',
            'job' => false,
            'params' => [
                'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
            ],
        ],
        'domain.set' => [
            'label' => 'Change TLD',
            'tier' => self::TIER_ROOT,
            'destructive' => true,
            'confirm' => 'domain',
            'job' => false,
            'params' => [
                'domain' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-z0-9][a-z0-9.-]{0,61}$/'],
            ],
        ],
        'port.set' => [
            'label' => 'Change nginx port',
            'tier' => self::TIER_ROOT,
            'destructive' => true,
            'confirm' => 'port',
            'job' => false,
            'params' => [
                'port' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 65535],
                'https' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'trust.ca' => [
            'label' => 'Trust Valet CA',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'check' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'cert.renew' => [
            'label' => 'Renew certificate',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'site' => ['type' => 'string', 'required' => false, 'default' => '', 'pattern' => '/^[A-Za-z0-9]([A-Za-z0-9._-]{0,62})$/'],
                'force' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
        ],
        'php.switch' => [
            'label' => 'Switch global PHP',
            'tier' => self::TIER_ROOT,
            'destructive' => true,
            'confirm' => 'version',
            'job' => false,
            'params' => [
                'version' => ['type' => 'version', 'required' => true],
            ],
        ],
        'xdebug.enable' => [
            'label' => 'Enable Xdebug',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'version' => ['type' => 'version', 'required' => false, 'default' => ''],
            ],
        ],
        'xdebug.disable' => [
            'label' => 'Disable Xdebug',
            'tier' => self::TIER_ROOT,
            'destructive' => false,
            'confirm' => null,
            'job' => false,
            'params' => [
                'version' => ['type' => 'version', 'required' => false, 'default' => ''],
            ],
        ],
    ];

    public function __construct(
        private Configuration $config,
        private Filesystem $files,
        private SiteLink $siteLink,
        private ServiceRegistry $registry,
        private Mysql $mysql,
        private Postgres $postgres,
        private Snapshot $snapshot,
        private Backup $backup,
        private Cache $cache,
        private Addon $addon,
        private Node $node,
        private Log $log,
        private Certificate $certificate,
        private Diagnose $diagnose,
        private Health $health,
        private DashboardPrivilege $privilege,
        private DashboardJob $jobs
    ) {
    }

    /**
     * Every action slug in the registry.
     *
     * @return array<int, string>
     */
    public function slugs(): array
    {
        return array_keys(self::ACTIONS);
    }

    /**
     * Whether the given slug exists in the allowlist.
     */
    public function has(string $slug): bool
    {
        return isset(self::ACTIONS[$slug]);
    }

    /**
     * Look up a single action definition.
     *
     * @return array<string, mixed>
     *
     * @throws DashboardActionException
     */
    public function definition(string $slug): array
    {
        if (!isset(self::ACTIONS[$slug])) {
            throw new DashboardActionException('Unknown dashboard action: ' . $slug);
        }

        return self::ACTIONS[$slug];
    }

    /**
     * The action catalog the UI uses to build its controls.
     *
     * Internal fields (the handler method name) are deliberately omitted so the
     * browser never learns how an action is implemented.
     *
     * @return array<int, array{
     *     slug: string,
     *     label: string,
     *     tier: string,
     *     destructive: bool,
     *     confirm: true|string|null,
     *     job: bool,
     *     params: array<string, array<string, mixed>>
     * }>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach (self::ACTIONS as $slug => $definition) {
            $catalog[] = [
                'slug' => $slug,
                'label' => $definition['label'],
                'tier' => $definition['tier'],
                'destructive' => $definition['destructive'],
                'confirm' => $definition['confirm'],
                'job' => $definition['job'],
                'params' => $this->publicParams($definition['params']),
            ];
        }

        return $catalog;
    }

    /**
     * Validate a request and run the action it names.
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     *
     * @throws DashboardActionException
     */
    public function dispatch(DashboardRequest $request): array
    {
        try {
            $definition = $this->definition($request->slug);
            $isMutation = $definition['tier'] !== self::TIER_READ;

            if ($isMutation) {
                $this->authorize($request, $definition);
            }

            $params = $this->validateParams($request->slug, $request->params());

            if ($isMutation) {
                $this->audit($request->slug, $params, 'dispatched');
            }

            if ((bool) $definition['job']) {
                $job = $this->jobs->start($request->slug, $params);

                return [
                    'ok' => true,
                    'message' => 'Started. Follow it in the activity panel.',
                    'data' => [],
                    'job' => $job['id'],
                ];
            }

            return $this->execute($request->slug, $params);
        } catch (DashboardActionException $e) {
            // Every refusal comes back as a normal result, so a caller cannot
            // forget to handle it and end up running something anyway.
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Run a root-tier action on behalf of the privileged helper.
     *
     * This is the helper's only way into Valet. It is refused unless deferred
     * mode is active, which only the helper itself can arrange, so it cannot be
     * used to turn a terminal session into a root one.
     *
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     *
     * @throws DashboardActionException
     */
    public function performPrivileged(string $slug, array $params): array
    {
        if (!DeferredPrivileged::enabled()) {
            throw new DashboardActionException('This action may only run inside the privileged helper.');
        }

        $definition = $this->definition($slug);

        if ($definition['tier'] !== self::TIER_ROOT) {
            throw new DashboardActionException('Not a privileged action: ' . $slug);
        }

        if (!array_key_exists($slug, self::PERFORMERS)) {
            throw new DashboardActionException('No privileged performer for ' . $slug);
        }

        return $this->execute($slug, $params);
    }

    /**
     * Run an already validated action.
     *
     * Used by the dispatcher and by the background job runner. Authorisation and
     * the audit trail happen when the action is requested; here the parameters
     * are re-validated because the job runner reads them back from disk.
     *
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    public function execute(string $slug, array $params): array
    {
        $definition = $this->definition($slug);
        $params = $this->validateParams($slug, $params);

        $handler = $this->handlerFor($slug);

        try {
            $result = $handler($params);
        } catch (\Throwable $e) {
            $this->audit($slug, $params, 'failed: ' . $e->getMessage());

            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'data' => [],
                'job' => null,
            ];
        }

        $this->audit($slug, $params, $result['ok'] ? 'succeeded' : 'failed');

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'data' => $result['data'],
            'job' => null,
        ];
    }

    /**
     * Enforce the security rules that gate a mutating action.
     *
     * @param array<string, mixed> $definition
     *
     * @throws DashboardActionException
     */
    private function authorize(DashboardRequest $request, array $definition): void
    {
        if ($request->method !== 'POST') {
            throw new DashboardActionException('Mutating actions must be sent with POST.');
        }

        if (!$request->isLoopback()) {
            throw new DashboardActionException(
                'The dashboard only performs actions requested from this machine. Run the command in your terminal instead.'
            );
        }

        if (!$request->hasValidOrigin()) {
            throw new DashboardActionException('Cross-origin dashboard requests are rejected.');
        }

        if (!$request->hasValidCsrf()) {
            throw new DashboardActionException('Missing or invalid CSRF token. Reload the dashboard and try again.');
        }

        $this->assertConfirmed($request, $definition);
    }

    /**
     * Require an explicit confirmation for destructive actions.
     *
     * `confirm => true` only needs an acknowledgement, while
     * `confirm => '<param>'` requires the client to echo that parameter back so
     * a user cannot be tricked into dropping one database while confirming
     * another.
     *
     * @param array<string, mixed> $definition
     *
     * @throws DashboardActionException
     */
    private function assertConfirmed(DashboardRequest $request, array $definition): void
    {
        $confirm = $definition['confirm'] ?? null;

        if ($confirm === null) {
            return;
        }

        $given = $request->confirmation();

        if ($given === null) {
            throw new DashboardActionException('This action must be confirmed.');
        }

        if ($confirm === true) {
            if ($given !== '1') {
                throw new DashboardActionException('This action must be confirmed.');
            }

            return;
        }

        $expected = $request->params()[$confirm] ?? null;

        if (!is_string($expected) || !hash_equals($expected, $given)) {
            throw new DashboardActionException('Confirmation did not match the ' . $confirm . ' being changed.');
        }
    }

    /**
     * Validate and coerce parameters against an action's schema.
     *
     * Unknown parameters are rejected outright rather than ignored, so a typo
     * can never silently turn into a default value.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws DashboardActionException
     */
    public function validateParams(string $slug, array $params): array
    {
        $definition = $this->definition($slug);

        /** @var array<string, array<string, mixed>> $schema */
        $schema = is_array($definition['params'] ?? null) ? $definition['params'] : [];

        foreach (array_keys($params) as $key) {
            if (!isset($schema[(string) $key])) {
                throw new DashboardActionException('Unknown parameter for ' . $slug . ': ' . (string) $key);
            }
        }

        $clean = [];

        foreach ($schema as $key => $spec) {
            /** @var array<string, mixed> $spec */
            $provided = $params[$key] ?? null;

            if ($provided === null || $provided === '') {
                if ((bool) ($spec['required'] ?? false)) {
                    throw new DashboardActionException('Missing required parameter: ' . $key);
                }

                $clean[$key] = $spec['default'] ?? null;

                continue;
            }

            $clean[$key] = $this->coerce($key, $spec, $provided);
        }

        return $clean;
    }

    /**
     * Coerce and range/format check a single parameter.
     *
     * @param  array<string, mixed>  $spec
     * @param  mixed  $value
     *
     * @throws DashboardActionException
     */
    private function coerce(string $key, array $spec, $value): mixed
    {
        $type = is_string($spec['type'] ?? null) ? $spec['type'] : 'string';

        if ($type === 'bool') {
            return is_bool($value)
                ? $value
                : in_array(strtolower(is_scalar($value) ? (string) $value : ''), ['1', 'true', 'yes', 'on'], true);
        }

        if ($type === 'int') {
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]+$/', $value))) {
                throw new DashboardActionException('Parameter must be an integer: ' . $key);
            }

            $number = (int) $value;

            if (isset($spec['min']) && $number < $this->bound($spec['min'])) {
                throw new DashboardActionException('Parameter is below the allowed minimum: ' . $key);
            }

            if (isset($spec['max']) && $number > $this->bound($spec['max'])) {
                throw new DashboardActionException('Parameter is above the allowed maximum: ' . $key);
            }

            return $number;
        }

        if (!is_string($value)) {
            throw new DashboardActionException('Parameter must be a string: ' . $key);
        }

        $value = trim($value);

        $maxLength = $this->bound($spec['maxLength'] ?? 512);
        if (strlen($value) > $maxLength) {
            throw new DashboardActionException('Parameter is too long: ' . $key);
        }

        if (isset($spec['in'])) {
            /** @var array<int, string> $allowed */
            $allowed = $spec['in'];

            if (!in_array($value, $allowed, true)) {
                throw new DashboardActionException('Parameter has an unsupported value: ' . $key);
            }
        }

        $pattern = is_string($spec['pattern'] ?? null) ? $spec['pattern'] : '';
        if ($pattern !== '' && preg_match($pattern, $value) !== 1) {
            throw new DashboardActionException('Parameter has an invalid format: ' . $key);
        }

        switch ($type) {
            case 'path':
                $this->assertUsablePath($key, $value);

                break;

            case 'archive':
                // A bare file name only: the directory is added server-side so
                // traversal ("../..") is impossible.
                if (basename($value) !== $value || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) !== 1) {
                    throw new DashboardActionException('Parameter has an invalid format: ' . $key);
                }

                break;

            case 'database':
                if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) !== 1) {
                    throw new DashboardActionException('Parameter has an invalid format: ' . $key);
                }

                break;

            case 'version':
                if (in_array($value, PhpFpm::isolationSupportedPhpVersions(), true)
                    || in_array($value, PhpFpm::supportedPhpVersions(), true)) {
                    break;
                }

                if (preg_match('/^[0-9]{1,2}\.[0-9]{1,2}$/', $value) !== 1) {
                    throw new DashboardActionException('Unsupported PHP version: ' . $key);
                }

                break;
        }

        return $value;
    }

    /**
     * Reject paths that are absolute-looking but unusable or unsafe.
     *
     * These values are only ever written to Valet's own JSON configuration or
     * passed to the Valet CLI as a separate argv entry, but validating them
     * here keeps a control character or a relative path out of every handler.
     *
     * @throws DashboardActionException
     */
    private function assertUsablePath(string $key, string $value): void
    {
        if (!str_starts_with($value, '/')) {
            throw new DashboardActionException('Parameter must be an absolute path: ' . $key);
        }

        if (str_contains($value, '//')) {
            throw new DashboardActionException('Parameter is not a normalised path: ' . $key);
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new DashboardActionException('Parameter contains control characters: ' . $key);
        }

        if (str_ends_with($value, '/')) {
            throw new DashboardActionException('Parameter must not end with a slash: ' . $key);
        }
    }

    /**
     * Reduce a param schema to what the browser needs.
     *
     * @param mixed $schema
     *
     * @return array<string, mixed>
     */
    private function publicParams($schema): array
    {
        if (!is_array($schema)) {
            return [];
        }

        $public = [];

        foreach ($schema as $key => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $public[(string) $key] = [
                'type' => (string) ($spec['type'] ?? 'string'),
                'required' => (bool) ($spec['required'] ?? false),
                'default' => $spec['default'] ?? null,
                'in' => $spec['in'] ?? null,
            ];
        }

        return $public;
    }

    /**
     * Append an entry to the dashboard audit log.
     *
     * @param array<string, mixed> $params
     */
    private function audit(string $slug, array $params, string $outcome): void
    {
        try {
            $dir = VALET_HOME_PATH . '/Log';

            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $entry = [
                'time' => gmdate('c'),
                'action' => $slug,
                'params' => $this->auditParams($params),
                'outcome' => $outcome,
                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'),
            ];

            $this->files->appendAsUser(
                $dir . '/dashboard-audit.log',
                json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL
            );
        } catch (\Throwable $e) {
            // Auditing must never break the action itself.
        }
    }

    /**
     * Redact values that must never reach the audit log.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function auditParams(array $params): array
    {
        $safe = [];

        foreach ($params as $key => $value) {
            $key = (string) $key;

            if (in_array($key, ['token', 'password', 'secret', 'auth'], true)) {
                $safe[$key] = '[redacted]';

                continue;
            }

            $safe[$key] = is_scalar($value) ? $value : null;
        }

        return $safe;
    }

    /**
     * Build a standard success response.
     *
     * @param array<string|int, mixed> $data
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function ok(string $message, array $data = []): array
    {
        return ['ok' => true, 'message' => $message, 'data' => $data, 'job' => null];
    }

    /**
     * Build a standard failure response.
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function fail(string $message): array
    {
        // `job` is part of the envelope on every response so the browser can
        // read the shape without checking which branch produced it.
        return ['ok' => false, 'message' => $message, 'data' => [], 'job' => null];
    }

    /**
     * Read a numeric bound out of a parameter schema.
     *
     * The bounds are literals in the registry, so a non-numeric one is a typo
     * rather than anything a request can influence.
     *
     * @param  mixed  $value
     */
    private function bound($value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * Read a validated string parameter.
     *
     * The schema has already rejected anything of the wrong shape, so the
     * fallback here is unreachable in practice; it exists so a missing key can
     * never become a warning in the middle of a service restart.
     *
     * @param  array<string, mixed>  $params
     */
    private function text(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Read a validated integer parameter.
     *
     * @param  array<string, mixed>  $params
     */
    private function number(array $params, string $key): int
    {
        $value = $params[$key] ?? 0;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * Read a validated boolean parameter.
     *
     * @param  array<string, mixed>  $params
     */
    private function flag(array $params, string $key): bool
    {
        $value = $params[$key] ?? false;

        return is_bool($value) ? $value : (is_string($value) ? $value !== '' && $value !== '0' : (bool) $value);
    }

    /**
     * The method that performs an action.
     *
     * Written as an explicit match rather than a name in the registry: the
     * registry's labels and parameters are data, but the code that runs is
     * code, and a typo in one cannot silently become an unhandled action.
     *
     * @return callable(array<string, mixed>): array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     *
     * @throws DashboardActionException
     */
    private function handlerFor(string $slug): callable
    {
        return match ($slug) {
            'logs.view' => $this->handleLogsView(...),
            'diagnose.run' => $this->handleDiagnoseRun(...),
            'health.run' => $this->handleHealthRun(...),
            'services.list' => $this->handleServicesList(...),
            'databases.list' => $this->handleDatabasesList(...),
            'pg.databases.list' => $this->handlePgDatabasesList(...),
            'snapshots.list' => $this->handleSnapshotsList(...),
            'backups.list' => $this->handleBackupsList(...),
            'profiles.list' => $this->handleProfilesList(...),
            'certs.list' => $this->handleCertsList(...),
            'addons.list' => $this->handleAddonsList(...),
            'cache.status' => $this->handleCacheStatus(...),
            'node.current' => $this->handleNodeCurrent(...),
            'site.link' => $this->handleSiteLink(...),
            'site.unlink' => $this->handleSiteUnlink(...),
            'site.park' => $this->handleSitePark(...),
            'site.forget' => $this->handleSiteForget(...),
            'db.create' => $this->handleDbCreate(...),
            'db.drop' => $this->handleDbDrop(...),
            'db.reset' => $this->handleDbReset(...),
            'db.import' => $this->handleDbImport(...),
            'db.export' => $this->handleDbExport(...),
            'db.url' => $this->handleDbUrl(...),
            'pg.create' => $this->handlePgCreate(...),
            'pg.drop' => $this->handlePgDrop(...),
            'pg.reset' => $this->handlePgReset(...),
            'pg.import' => $this->handlePgImport(...),
            'pg.export' => $this->handlePgExport(...),
            'snapshot.create' => $this->handleSnapshotCreate(...),
            'snapshot.restore' => $this->handleSnapshotRestore(...),
            'snapshot.delete' => $this->handleSnapshotDelete(...),
            'backup.create' => $this->handleBackupCreate(...),
            'backup.restore' => $this->handleBackupRestore(...),
            'profile.use' => $this->handleProfileUse(...),
            'profile.delete' => $this->handleProfileDelete(...),
            'cache.clear' => $this->handleCacheClear(...),
            'addon.enable' => $this->handleAddonEnable(...),
            'addon.disable' => $this->handleAddonDisable(...),
            'node.use' => $this->handleNodeUse(...),
            'service.start' => $this->handleServiceStart(...),
            'service.stop' => $this->handleServiceStop(...),
            'service.restart' => $this->handleServiceRestart(...),
            'site.secure' => $this->handleSiteSecure(...),
            'site.unsecure' => $this->handleSiteUnsecure(...),
            'site.proxy' => $this->handleSiteProxy(...),
            'site.unproxy' => $this->handleSiteUnproxy(...),
            'site.isolate' => $this->handleSiteIsolate(...),
            'site.unisolate' => $this->handleSiteUnisolate(...),
            'domain.set' => $this->handleDomainSet(...),
            'port.set' => $this->handlePortSet(...),
            'trust.ca' => $this->handleTrustCa(...),
            'cert.renew' => $this->handleCertRenew(...),
            'php.switch' => $this->handlePhpSwitch(...),
            'xdebug.enable' => $this->handleXdebugEnable(...),
            'xdebug.disable' => $this->handleXdebugDisable(...),
            default => throw new DashboardActionException('Action has no handler: ' . $slug),
        };
    }

    // -------------------------------------------------------------- read ---

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleLogsView(array $params): array
    {
        $service = $this->text($params, 'service');
        $lines = $this->number($params, 'lines');
        $grep = $this->text($params, 'grep');

        return $this->ok(
            'Read ' . $lines . ' line(s) of ' . $service,
            ['lines' => $this->log->collect([$service], $lines, $grep === '' ? null : $grep)]
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDiagnoseRun(array $params): array
    {
        return $this->ok('Diagnostics collected', ['diagnose' => $this->diagnose->gather()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleHealthRun(array $params): array
    {
        return $this->ok('Health collected', ['health' => $this->health->checkAll()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleServicesList(array $params): array
    {
        return $this->ok('Service status', ['services' => $this->registry->statusRows()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDatabasesList(array $params): array
    {
        return $this->ok('MySQL databases', ['databases' => $this->flatDatabases($this->mysql->getDatabases())]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handlePgDatabasesList(array $params): array
    {
        return $this->ok('PostgreSQL databases', ['databases' => $this->flatDatabases($this->postgres->getDatabases())]);
    }

    /**
     * Flatten the driver's list into plain names.
     *
     * Both drivers return a list of single-element arrays because that is what
     * the CLI's `Writer::table` wanted, and report a failure as a one-element
     * list containing the error text. The dashboard wants names, so unwrap both
     * shapes and surface the message rather than a row of gibberish.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<int, string>
     */
    private function flatDatabases(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $row = reset($row);
            }

            if (!is_string($row) || $row === '') {
                continue;
            }

            $names[] = $row;
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSnapshotsList(array $params): array
    {
        $site = $this->text($params, 'site');

        return $this->ok('Snapshots', [
            'snapshots' => $this->snapshot->list($site === '' ? null : $site),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleBackupsList(array $params): array
    {
        return $this->ok('Backups', ['backups' => $this->backup->listBackups()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleProfilesList(array $params): array
    {
        return $this->ok('Profiles', ['profiles' => $this->profile()->listProfiles()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleCertsList(array $params): array
    {
        return $this->ok('Certificates', ['certificates' => $this->certificate->list()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleAddonsList(array $params): array
    {
        return $this->ok('Addons', ['addons' => $this->addon->list()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleCacheStatus(array $params): array
    {
        return $this->ok('Cache status', ['cache' => $this->cache->status()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleNodeCurrent(array $params): array
    {
        return $this->ok('Node version', [
            'node' => [
                'current' => $this->node->current(),
                'installed' => $this->installedNodeVersions(),
                'available' => $this->node->nvmAvailable(),
            ],
        ]);
    }

    /**
     * Every Node version nvm actually has on disk, newest last.
     *
     * @return array<int, string>
     */
    private function installedNodeVersions(): array
    {
        try {
            return $this->node->installedVersions();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDbUrl(array $params): array
    {
        $name = $this->text($params, 'name');
        $domain = is_string($domain = $this->config->get('domain', 'test')) ? $domain : 'test';

        return $this->ok('Database URL', [
            'url' => 'https://database.valet.' . $domain . '/' . $name,
        ]);
    }

    // -------------------------------------------------------------- user ---

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteLink(array $params): array
    {
        $name = $this->text($params, 'name');
        $path = $this->text($params, 'path');

        if (!is_dir($path)) {
            return $this->fail('No such directory: ' . $path);
        }

        $url = $this->siteLink->link($path, $name);

        return $this->ok('Linked ' . $url, ['url' => $url]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteUnlink(array $params): array
    {
        $this->siteLink->unlink($this->text($params, 'name'));

        return $this->ok('Unlinked ' . $this->text($params, 'name'));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSitePark(array $params): array
    {
        $path = $this->text($params, 'path');

        if (!is_dir($path)) {
            return $this->fail('No such directory: ' . $path);
        }

        $this->config->addPath($path);

        return $this->ok('Parked ' . $path, ['paths' => $this->paths()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteForget(array $params): array
    {
        $this->config->removePath($this->text($params, 'path'));

        return $this->ok('Forgot ' . $this->text($params, 'path'), ['paths' => $this->paths()]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDbCreate(array $params): array
    {
        $name = $this->text($params, 'name');

        if (!$this->mysql->createDatabase($name)) {
            return $this->fail('Could not create database ' . $name);
        }

        return $this->ok('Created database ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDbDrop(array $params): array
    {
        $name = $this->text($params, 'name');

        if (!$this->mysql->dropDatabase($name)) {
            return $this->fail('Could not drop database ' . $name);
        }

        return $this->ok('Dropped database ' . $name);
    }

    /**
     * Drop and recreate a database, leaving it empty.
     *
     * This mirrors `valet db:reset`: the database is emptied, not repopulated
     * from a previous dump.
     *
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDbReset(array $params): array
    {
        $name = $this->text($params, 'name');

        if (!$this->mysql->isDatabaseExists($name)) {
            return $this->fail('Database does not exist: ' . $name);
        }

        if (!$this->mysql->dropDatabase($name)) {
            return $this->fail('Could not drop database ' . $name);
        }

        if (!$this->mysql->createDatabase($name)) {
            return $this->fail('Could not recreate database ' . $name);
        }

        return $this->ok('Reset database ' . $name);
    }

    /**
     * Import a dump, replacing the target database.
     *
     * The dump is imported by removing any existing database first. That also
     * avoids Mysql::importDatabase()'s interactive prompt, which cannot be
     * answered from a background job.
     *
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDbImport(array $params): array
    {
        $name = $this->text($params, 'name');
        $file = $this->text($params, 'file');

        if (!$this->files->exists($file)) {
            return $this->fail('Dump file not found: ' . $file);
        }

        if ($this->mysql->isDatabaseExists($name)) {
            $this->mysql->dropDatabase($name);
        }

        $this->mysql->createDatabase($name);
        $this->mysql->importDatabase($file, $name);

        return $this->ok('Imported ' . $file . ' into ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDbExport(array $params): array
    {
        $name = $this->text($params, 'name');

        // exportDatabase() writes relative to the working directory, which for a
        // web request is the site root (and may not be writable).
        $export = $this->within(self::EXPORT_DIR, fn (): array => $this->mysql->exportDatabase($name, $this->flag($params, 'sql')));

        return $this->ok('Exported ' . $name, $export);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handlePgCreate(array $params): array
    {
        $name = $this->text($params, 'name');

        if (!$this->postgres->createDatabase($name)) {
            return $this->fail('Could not create database ' . $name);
        }

        return $this->ok('Created database ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handlePgDrop(array $params): array
    {
        $name = $this->text($params, 'name');

        if (!$this->postgres->dropDatabase($name)) {
            return $this->fail('Could not drop database ' . $name);
        }

        return $this->ok('Dropped database ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handlePgReset(array $params): array
    {
        $name = $this->text($params, 'name');

        if (!$this->postgres->isDatabaseExists($name)) {
            return $this->fail('Database does not exist: ' . $name);
        }

        if (!$this->postgres->dropDatabase($name)) {
            return $this->fail('Could not drop database ' . $name);
        }

        if (!$this->postgres->createDatabase($name)) {
            return $this->fail('Could not recreate database ' . $name);
        }

        return $this->ok('Reset database ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handlePgImport(array $params): array
    {
        $name = $this->text($params, 'name');
        $file = $this->text($params, 'file');

        if (!$this->files->exists($file)) {
            return $this->fail('Dump file not found: ' . $file);
        }

        if ($this->postgres->isDatabaseExists($name)) {
            $this->postgres->dropDatabase($name);
        }

        $this->postgres->createDatabase($name);
        $this->postgres->importDatabase($file, $name);

        return $this->ok('Imported ' . $file . ' into ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handlePgExport(array $params): array
    {
        $name = $this->text($params, 'name');
        $export = $this->within(self::EXPORT_DIR, fn (): array => $this->postgres->exportDatabase($name, $this->flag($params, 'sql')));

        return $this->ok('Exported ' . $name, $export);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSnapshotCreate(array $params): array
    {
        $name = $this->text($params, 'name');
        $path = $this->text($params, 'path');

        if (!is_dir($path)) {
            return $this->fail('No such directory: ' . $path);
        }

        $dir = $this->within($path, fn (): string => $this->snapshot->create(
            $name === '' ? null : $name,
            (bool) ($params['with_db'] ?? false),
            $this->text($params, 'notes') ?: null
        ));

        return $this->ok('Created snapshot', ['path' => $dir]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSnapshotRestore(array $params): array
    {
        $name = $this->text($params, 'name');
        $path = $this->text($params, 'path');

        if (!is_dir($path)) {
            return $this->fail('No such directory: ' . $path);
        }

        $this->within($path, function () use ($name): void {
            $this->snapshot->restore($name, true);
        });

        return $this->ok('Restored snapshot ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSnapshotDelete(array $params): array
    {
        $name = $this->text($params, 'name');
        $path = $this->text($params, 'path');

        if (!is_dir($path)) {
            return $this->fail('No such directory: ' . $path);
        }

        $this->within($path, function () use ($name): void {
            $this->snapshot->delete($name);
        });

        return $this->ok('Deleted snapshot ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleBackupCreate(array $params): array
    {
        $path = $this->backup->backup(null, (bool) ($params['with_db'] ?? false));

        return $this->ok('Created backup', ['path' => $path]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleBackupRestore(array $params): array
    {
        // Only a bare file name is accepted; the path is resolved inside the
        // Valet backup directory so the client can never choose where to read
        // from or write to.
        $archive = self::BACKUP_DIR . '/' . $this->text($params, 'archive');

        if (!$this->files->exists($archive)) {
            return $this->fail('Backup not found: ' . $this->text($params, 'archive'));
        }

        $this->backup->restore($archive, true);

        return $this->ok('Restored backup ' . $this->text($params, 'archive'));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleProfileUse(array $params): array
    {
        $result = $this->profile()->use($this->text($params, 'name'), (bool) ($params['apply'] ?? false));

        return $this->ok('Applied profile ' . $this->text($params, 'name'), ['profile' => $result]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleProfileDelete(array $params): array
    {
        $name = $this->text($params, 'name');
        $this->profile()->delete($name);

        return $this->ok('Deleted profile ' . $name);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleCacheClear(array $params): array
    {
        $result = $this->cache->clear(
            (bool) ($params['composer'] ?? false),
            (bool) ($params['npm'] ?? false),
            (bool) ($params['valet'] ?? false),
            true
        );

        return $this->ok('Cleared caches', ['cleared' => $result]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleAddonEnable(array $params): array
    {
        $this->addon->enable($this->text($params, 'name'));

        return $this->ok('Enabled addon ' . $this->text($params, 'name'));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleAddonDisable(array $params): array
    {
        $this->addon->disable($this->text($params, 'name'));

        return $this->ok('Disabled addon ' . $this->text($params, 'name'));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleNodeUse(array $params): array
    {
        $this->node->use($this->text($params, 'version'));

        return $this->ok('Switched Node to ' . $this->text($params, 'version'));
    }

    // -------------------------------------------------------------- root ---

    /**
     * Run a root-tier action, either here or inside the privileged helper.
     *
     * From a web request the work is handed to DashboardPrivilege. Inside the
     * helper - identified by the deferred-command environment variable the
     * helper itself sets - the action is performed as the ordinary user and
     * every privileged command Valet attempts is collected for the helper to
     * run afterwards. That keeps a single implementation of "what does
     * `valet secure` actually do" instead of a second one in the helper.
     *
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function privileged(string $slug, array $params): array
    {
        if (DeferredPrivileged::enabled()) {
            $performer = self::PERFORMERS[$slug] ?? null;

            if (!is_string($performer) || !method_exists($this, $performer)) {
                throw new DashboardActionException('No privileged performer for ' . $slug);
            }

            return $this->{$performer}($params);
        }

        $result = $this->privilege->run($slug, $params);

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'data' => $result['data'],
            'job' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleServiceStart(array $params): array
    {
        return $this->privileged('service.start', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleServiceStop(array $params): array
    {
        return $this->privileged('service.stop', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleServiceRestart(array $params): array
    {
        return $this->privileged('service.restart', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteSecure(array $params): array
    {
        return $this->privileged('site.secure', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteUnsecure(array $params): array
    {
        return $this->privileged('site.unsecure', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteProxy(array $params): array
    {
        return $this->privileged('site.proxy', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteUnproxy(array $params): array
    {
        return $this->privileged('site.unproxy', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteIsolate(array $params): array
    {
        return $this->privileged('site.isolate', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleSiteUnisolate(array $params): array
    {
        return $this->privileged('site.unisolate', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleDomainSet(array $params): array
    {
        return $this->privileged('domain.set', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handlePortSet(array $params): array
    {
        return $this->privileged('port.set', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleTrustCa(array $params): array
    {
        return $this->privileged('trust.ca', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleCertRenew(array $params): array
    {
        return $this->privileged('cert.renew', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handlePhpSwitch(array $params): array
    {
        return $this->privileged('php.switch', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleXdebugEnable(array $params): array
    {
        return $this->privileged('xdebug.enable', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function handleXdebugDisable(array $params): array
    {
        return $this->privileged('xdebug.disable', $params);
    }

    // ---------------------------------------------------- root performers ---

    /**
     * Start, stop or restart one of the services the dashboard may touch.
     *
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performServiceControl(array $params, string $action): array
    {
        $service = $this->text($params, 'service');

        match ($action) {
            'start' => $this->registry->start([$service]),
            'stop' => $this->registry->stop([$service]),
            default => $this->registry->restart([$service]),
        };

        return $this->ok(ucfirst($action) . 'ed ' . $service);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performServiceStart(array $params): array
    {
        return $this->performServiceControl($params, 'start');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performServiceStop(array $params): array
    {
        return $this->performServiceControl($params, 'stop');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performServiceRestart(array $params): array
    {
        return $this->performServiceControl($params, 'restart');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performSiteSecure(array $params): array
    {
        $url = $this->siteUrl($this->text($params, 'name'));

        SiteSecureFacade::secure($url);
        NginxFacade::restart();

        return $this->ok('Secured ' . $url);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performSiteUnsecure(array $params): array
    {
        $url = $this->siteUrl($this->text($params, 'name'));

        SiteSecureFacade::unsecure($url, true);
        NginxFacade::restart();

        return $this->ok('Unsecured ' . $url);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performSiteProxy(array $params): array
    {
        $url = $this->siteUrl($this->text($params, 'name'));
        $host = $this->text($params, 'host');

        SiteProxyFacade::proxyCreate($url, $host, (bool) ($params['secure'] ?? false));
        NginxFacade::restart();

        return $this->ok('Now proxying ' . $url . ' to ' . $host);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performSiteUnproxy(array $params): array
    {
        $url = $this->siteUrl($this->text($params, 'name'));

        SiteSecureFacade::unsecure($url);
        NginxFacade::restart();

        return $this->ok('No longer proxying ' . $url);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performSiteIsolate(array $params): array
    {
        $name = $this->text($params, 'name');
        $version = $this->text($params, 'version');

        if (!SiteIsolateFacade::isolateDirectory($name, $version, (bool) ($params['secure'] ?? false))) {
            return $this->fail('Could not isolate ' . $name);
        }

        return $this->ok($name . ' now uses PHP ' . $version);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performSiteUnisolate(array $params): array
    {
        $name = $this->text($params, 'name');

        SiteIsolateFacade::unIsolateDirectory($name);

        return $this->ok($name . ' now uses the default PHP version');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performDomainSet(array $params): array
    {
        $domain = trim($this->text($params, 'domain'), '.');
        $previous = is_string($previous = $this->config->get('domain', '')) ? $previous : '';

        DnsMasqFacade::updateDomain($domain);
        $this->config->set('domain', $domain);
        SiteSecureFacade::reSecureForNewDomain($previous, $domain);
        PhpFpmFacade::restart();
        NginxFacade::restart();

        return $this->ok('Valet domain is now ' . $domain);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performPortSet(array $params): array
    {
        $port = $this->text($params, 'port');

        if ((bool) ($params['https'] ?? false)) {
            $this->config->set('https_port', $port);
        } else {
            NginxFacade::updatePort($port);
            $this->config->set('port', $port);
        }

        SiteSecureFacade::regenerateSecuredSitesConfig();
        NginxFacade::restart();
        PhpFpmFacade::restart();

        return $this->ok('Valet port is now ' . $port);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performTrustCa(array $params): array
    {
        return $this->ok('Certificate authority trust updated', [
            'result' => SiteSecureFacade::trustCaCertificate((bool) ($params['check'] ?? false)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performCertRenew(array $params): array
    {
        $site = $this->text($params, 'site');

        return $this->ok('Certificates renewed', [
            'result' => $this->certificate->renew(
                $site === '' ? null : $this->siteUrl($site),
                $this->flag($params, 'force')
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performPhpSwitch(array $params): array
    {
        $version = $this->text($params, 'version');

        PhpFpmFacade::switchVersion($version);

        return $this->ok('Valet now uses PHP ' . $version);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performXdebugEnable(array $params): array
    {
        return $this->performXdebug($params, true);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performXdebugDisable(array $params): array
    {
        return $this->performXdebug($params, false);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>, job: string|null}
     */
    private function performXdebug(array $params, bool $enable): array
    {
        $version = $this->text($params, 'version');
        $target = $version === '' ? null : $version;

        if ($enable) {
            PhpFpmFacade::enableXdebug($target);
        } else {
            PhpFpmFacade::disableXdebug($target);
        }

        return $this->ok('Xdebug ' . ($enable ? 'enabled' : 'disabled') . ' for PHP ' . ($target ?? 'current'));
    }

    /**
     * Resolve a site name to the URL Valet serves it on.
     */
    private function siteUrl(string $name): string
    {
        return $this->config->parseDomain($name);
    }

    // ------------------------------------------------------------ helpers ---

    /**
     * Resolve the Profile service lazily.
     *
     * Profile is not injected because it is only used by a handful of actions
     * and its dependency graph is comparatively deep.
     */
    private function profile(): Profile
    {
        $profile = resolve(Profile::class);

        if (!$profile instanceof Profile) {
            throw new \RuntimeException('Profile service is unavailable.');
        }

        return $profile;
    }

    /**
     * The configured parked paths.
     *
     * @return array<int, string>
     */
    private function paths(): array
    {
        $paths = $this->config->get('paths', []);

        if (!is_array($paths)) {
            return [];
        }

        return array_values(array_map(static fn ($path): string => (string) $path, $paths));
    }

    /**
     * Run a callback with a different working directory.
     *
     * Snapshot and export operations are written for the terminal and resolve
     * the current project from getcwd(). A web request has no meaningful
     * working directory, so those calls are made from inside the project the
     * user picked, with the original directory always restored afterwards.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback
     *
     * @return TReturn
     */
    private function within(string $directory, callable $callback)
    {
        if (!is_dir($directory)) {
            $this->files->ensureDirExists($directory, user());
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new DashboardActionException('Directory is not writable: ' . $directory);
        }

        $previous = getcwd();
        $changed = $previous !== false && @chdir($directory);

        try {
            return $callback();
        } finally {
            if ($changed && $previous !== false) {
                @chdir($previous);
            }
        }
    }
}
