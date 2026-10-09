/**
 * The dashboard's data contract.
 *
 * These types mirror the PHP collectors exactly (Dashboard::data(),
 * DashboardApi::ACTIONS, DashboardJob, DashboardPrivilege). They are hand
 * written rather than generated so a reader can see the whole surface in one
 * file, and every field is optional-tolerant: a single gather that fails on
 * the PHP side degrades to a default instead of breaking the page.
 *
 * Keep this file in sync when the PHP payload changes.
 */

/** Authorisation tier of an action, as assigned by DashboardApi::ACTIONS. */
export type Tier = 'read' | 'user' | 'root';

/** Every action slug the API will accept. */
export type ActionSlug =
  | 'logs.view'
  | 'diagnose.run'
  | 'health.run'
  | 'services.list'
  | 'databases.list'
  | 'pg.databases.list'
  | 'snapshots.list'
  | 'backups.list'
  | 'profiles.list'
  | 'certs.list'
  | 'addons.list'
  | 'cache.status'
  | 'node.current'
  | 'db.url'
  | 'site.link'
  | 'site.unlink'
  | 'site.park'
  | 'site.forget'
  | 'db.create'
  | 'db.drop'
  | 'db.reset'
  | 'db.import'
  | 'db.export'
  | 'pg.create'
  | 'pg.drop'
  | 'pg.reset'
  | 'pg.import'
  | 'pg.export'
  | 'snapshot.create'
  | 'snapshot.restore'
  | 'snapshot.delete'
  | 'backup.create'
  | 'backup.restore'
  | 'profile.use'
  | 'profile.delete'
  | 'cache.clear'
  | 'addon.enable'
  | 'addon.disable'
  | 'node.use'
  | 'service.start'
  | 'service.stop'
  | 'service.restart'
  | 'site.secure'
  | 'site.unsecure'
  | 'site.proxy'
  | 'site.unproxy'
  | 'site.isolate'
  | 'site.unisolate'
  | 'domain.set'
  | 'port.set'
  | 'trust.ca'
  | 'cert.renew'
  | 'php.switch'
  | 'xdebug.enable'
  | 'xdebug.disable';

/** Parameter kinds DashboardApi::validateParams() understands. */
export type ParamType =
  | 'string'
  | 'bool'
  | 'int'
  | 'path'
  | 'archive'
  | 'database'
  | 'version';

/** Public half of an action's parameter schema, as returned by /api/catalog. */
export interface ActionParam {
  type: ParamType;
  required: boolean;
  default: string | number | boolean | null;
  /** Allowed values, when the API constrains the parameter. */
  in: string[] | null;
}

/** Public description of one action, as returned by /api/catalog. */
export interface ActionDefinition {
  slug: ActionSlug;
  label: string;
  tier: Tier;
  destructive: boolean;
  /**
   * What the user must type to confirm: true means "yes", a string means that
   * exact value, null means no confirmation step at all.
   */
  confirm: true | string | null;
  /** Whether the action returns a background job id instead of a result. */
  job: boolean;
  params: Record<string, ActionParam>;
}

/** The JSON envelope every endpoint answers with. */
export interface Envelope<T = Record<string, unknown>> {
  ok: boolean;
  message: string;
  data: T;
  job: string | null;
}

/** One site, normalised from parked / linked / proxied sources. */
export interface Site {
  name: string;
  url: string;
  path: string;
  secured: boolean;
  /** PHP version this site is isolated onto, if any. */
  isolated: string | null;
  /** Proxy target, when the site is a proxy rather than a directory. */
  proxy: string | null;
  type: 'parked' | 'linked' | 'proxy';
}

export interface SiteCounts {
  parked: number;
  linked: number;
  proxied: number;
  secured: number;
  isolated: number;
  total: number;
}

/** Service row as collected into /api/data (name, installed, status). */
export interface DashboardService {
  name: string;
  installed: boolean;
  status: string;
}

/** Service row as returned by the services.list action (same unit, fuller). */
export interface ServiceStatusRow {
  service: string;
  installed: boolean;
  enabled: boolean;
  active: boolean;
}

export interface HealthRow {
  service: string;
  healthy: boolean;
  message: string;
}

/** The privileged helper state reported by DashboardPrivilege::status(). */
export interface Privileges {
  enabled: boolean;
  helper_path: string;
  helper_installed: boolean;
  helper_trusted: boolean;
  sudoers_path: string;
  sudoers_installed: boolean;
  granted_users: string[];
  install_command: string;
  verbs: string[];
}

/** Everything /api/data reports. */
export interface DashboardData {
  domain: string;
  port: number;
  https_port: number;
  php_version: string;
  php_versions: string[];
  paths: string[];
  sites: Site[];
  counts: SiteCounts;
  services: DashboardService[];
  health: HealthRow[];
  mail_url: string;
  database_url: string;
  nginx_sites: number;
  valet_version: string;
  privileges: Privileges;
  php_switch: string[];
}

/** The /api/data envelope body. */
export interface DataPayload {
  dashboard: DashboardData;
  privileges: Privileges;
  csrf: string;
  php_versions: string[];
  php_isolation: string[];
}

/** The /api/catalog envelope body. */
export interface CatalogPayload {
  tiers: Tier[];
  actions: ActionDefinition[];
}

export type JobStatus = 'queued' | 'running' | 'done' | 'failed' | 'cancelled';

/** A background job, as returned by /api/jobs and /api/jobs/{id}. */
export interface Job {
  id: string;
  slug: string;
  params: Record<string, unknown>;
  status: JobStatus;
  message: string;
  data: Record<string, unknown>;
  started_at: string;
  finished_at: string | null;
  /** Tail of the job log; only present on the single-job endpoint. */
  log?: string;
}

/** Values the ActionModal collects, keyed by parameter name. */
export type ParamValues = Record<string, string | number | boolean>;

/** Records returned by the read actions, in the shapes their handlers emit. */
export interface LogsResult {
  lines: string[];
}

export interface DiagnoseResult {
  diagnose: Record<string, unknown>;
}

export interface HealthResult {
  health: HealthRow[];
}

export interface ServicesResult {
  services: ServiceStatusRow[];
}

export interface DatabasesResult {
  databases: string[];
}

export interface SnapshotsResult {
  snapshots: Array<{
    name: string;
    site: string;
    timestamp: string;
    includes_db: boolean;
    notes: string | null;
    path: string;
  }>;
}

export interface BackupsResult {
  backups: string[];
}

export interface ProfilesResult {
  profiles: Array<{ name: string; scope: string; path: string }>;
}

export interface CertificatesResult {
  certificates: Array<{
    site: string;
    path: string;
    expires_at: string | null;
    days_remaining: number | null;
    valid: boolean;
    subject?: string | null;
    issuer?: string | null;
  }>;
}

export interface AddonsResult {
  addons: Array<{ name: string; enabled: boolean; type: string; description: string }>;
}

export interface CacheResult {
  cache: Record<string, { path: string; bytes: number; human: string }>;
}

export interface NodeResult {
  node: { current: string | null; installed: string[]; available: boolean };
}

/** Anything the read actions can hand back, keyed by the action that produced it. */
export interface ActionResultData {
  lines?: string[];
  diagnose?: Record<string, unknown>;
  health?: HealthRow[];
  services?: ServiceStatusRow[];
  databases?: string[];
  snapshots?: SnapshotsResult['snapshots'];
  backups?: string[];
  profiles?: ProfilesResult['profiles'];
  certificates?: CertificatesResult['certificates'];
  addons?: AddonsResult['addons'];
  cache?: CacheResult['cache'];
  node?: NodeResult['node'];
}
