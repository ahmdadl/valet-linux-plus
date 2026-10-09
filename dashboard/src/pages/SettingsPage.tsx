/**
 * Settings.
 *
 * The machine-wide knobs: the domain and ports everything hangs off, the
 * profiles that switch a project's configuration, the addons that extend Valet
 * and the caches that grow.
 */

import { useState } from 'react';

import { QuickAction } from '../components/QuickAction';
import { AddonRows, CacheRows } from '../components/panels';
import {
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorNote,
  PageHeader,
  Spinner,
} from '../components/ui';
import { useActionsApi } from '../lib/actionContext';
import { useData, useReadAction } from '../lib/hooks';
import { shortenPath } from '../lib/format';
import type { AddonsResult, CacheResult, ProfilesResult } from '../types';

export function SettingsPage() {
  const { data, error } = useData();
  const { open } = useActionsApi();
  const [tab, setTab] = useState<'machine' | 'profiles' | 'addons' | 'cache'>('machine');

  const profiles = useReadAction<ProfilesResult>('profiles.list');
  const addons = useReadAction<AddonsResult>('addons.list');
  const cache = useReadAction<CacheResult>('cache.status');

  if (error) {
    return <ErrorNote error={error} />;
  }

  const dashboard = data?.data.dashboard;

  if (data === undefined || dashboard === undefined) {
    return (
      <div className="grid place-items-center py-24">
        <Spinner className="size-6" />
      </div>
    );
  }

  const privileges = data.data.privileges;

  const tabs = [
    { id: 'machine', label: 'Machine' },
    { id: 'profiles', label: 'Profiles' },
    { id: 'addons', label: 'Addons' },
    { id: 'cache', label: 'Caches' },
  ] as const;

  return (
    <div className="grid gap-4">
      <PageHeader
        title="Settings"
        description="Domain, ports, profiles, addons and the caches behind them."
        actions={
          <QuickAction slug="cache.clear" variant="primary" size="sm">
            Clear caches
          </QuickAction>
        }
      />

      <div className="inline-flex w-fit rounded-lg border border-line p-0.5 dark:border-night-line">
        {tabs.map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={() => setTab(item.id)}
            className={
              item.id === tab
                ? 'rounded-md bg-accent px-3 py-1.5 text-[13px] font-medium text-white'
                : 'rounded-md px-3 py-1.5 text-[13px] font-medium text-muted hover:text-ink dark:text-night-muted dark:hover:text-night-ink'
            }
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'machine' && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader title="Domain" subtitle="Every site is served under this TLD" />
            <dl className="grid gap-2 px-4 py-3 text-[13px]">
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Current</dt>
                <dd className="font-mono font-semibold">*.{dashboard.domain}</dd>
              </div>
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Ports</dt>
                <dd className="tabular-nums">
                  {dashboard.port} / {dashboard.https_port}
                </dd>
              </div>
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Parked paths</dt>
                <dd className="max-w-[60%] text-right font-mono text-[11px] break-all">
                  {dashboard.paths.length === 0
                    ? 'none'
                    : dashboard.paths.map(shortenPath).join(', ')}
                </dd>
              </div>
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Nginx configs</dt>
                <dd className="tabular-nums">{dashboard.nginx_sites}</dd>
              </div>
            </dl>
            <div className="flex flex-wrap gap-2 border-t border-line px-4 py-3 dark:border-night-line">
              <QuickAction slug="domain.set" size="sm">
                Change domain
              </QuickAction>
              <QuickAction slug="port.set" size="sm" variant="ghost">
                Change ports
              </QuickAction>
              <QuickAction slug="site.park" size="sm" variant="ghost">
                Park another path
              </QuickAction>
            </div>
          </Card>

          <Card>
            <CardHeader
              title="Privileged helper"
              subtitle="Required for anything that writes outside your account"
            />
            <dl className="grid gap-2 px-4 py-3 text-[13px]">
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Enabled</dt>
                <dd>
                  <Badge tone={privileges.enabled ? 'ok' : 'warn'}>
                    {privileges.enabled ? 'yes' : 'no'}
                  </Badge>
                </dd>
              </div>
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Helper</dt>
                <dd>
                  <Badge tone={privileges.helper_installed ? 'ok' : 'neutral'}>
                    {privileges.helper_installed ? 'installed' : 'missing'}
                  </Badge>
                </dd>
              </div>
              <div className="flex items-center justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Sudoers</dt>
                <dd>
                  <Badge tone={privileges.sudoers_installed ? 'ok' : 'neutral'}>
                    {privileges.sudoers_installed ? 'installed' : 'missing'}
                  </Badge>
                </dd>
              </div>
              <div className="flex items-start justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Helper path</dt>
                <dd className="font-mono text-[11px] break-all">{privileges.helper_path}</dd>
              </div>
              <div className="flex items-start justify-between gap-2">
                <dt className="text-muted dark:text-night-muted">Sudoers path</dt>
                <dd className="font-mono text-[11px] break-all">{privileges.sudoers_path}</dd>
              </div>
            </dl>
            <p className="border-t border-line px-4 py-3 text-[11px] text-muted dark:border-night-line dark:text-night-muted">
              Install it from a terminal with{' '}
              <code className="font-mono">{privileges.install_command}</code>.
            </p>
          </Card>
        </div>
      )}

      {tab === 'profiles' && (
        <Card>
          <CardHeader
            title="Profiles"
            subtitle="Stored configurations a project can switch between"
            actions={
              <Button
                size="sm"
                variant="ghost"
                onClick={() => void profiles.refetch()}
                disabled={profiles.isFetching}
              >
                {profiles.isFetching ? <Spinner className="size-3.5" /> : '↻'}
              </Button>
            }
          />
          <ErrorNote error={profiles.error} />
          {profiles.data === undefined ? (
            <div className="grid place-items-center py-8">
              <Spinner className="size-5" />
            </div>
          ) : profiles.data.profiles.length === 0 ? (
            <EmptyState
              title="No profiles saved"
              hint="Save one from a project directory with `valet profile save <name>`."
            />
          ) : (
            <ul className="divide-y divide-line dark:divide-night-line">
              {profiles.data.profiles.map((profile) => (
                <li key={profile.path} className="flex flex-wrap items-center gap-2 px-4 py-2.5">
                  <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium">{profile.name}</p>
                    <p
                      className="truncate font-mono text-[11px] text-muted dark:text-night-muted"
                      title={profile.path}
                    >
                      {shortenPath(profile.path)}
                    </p>
                  </div>
                  <Badge tone={profile.scope === 'project' ? 'accent' : 'neutral'}>
                    {profile.scope}
                  </Badge>
                  <QuickAction
                    slug="profile.use"
                    values={{ name: profile.name, apply: true }}
                    size="sm"
                    variant="ghost"
                    title={
                      profile.name === '(project)'
                        ? 'Apply the project profile in this directory'
                        : `Apply ${profile.name}`
                    }
                  >
                    Apply
                  </QuickAction>
                  <QuickAction
                    slug="profile.delete"
                    values={{ name: profile.name }}
                    size="sm"
                    variant="ghost"
                  >
                    Delete
                  </QuickAction>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === 'addons' && (
        <Card>
          <CardHeader
            title="Addons"
            subtitle="Optional extras that extend what Valet installs and runs"
            actions={
              <Button
                size="sm"
                variant="ghost"
                onClick={() => void addons.refetch()}
                disabled={addons.isFetching}
              >
                {addons.isFetching ? <Spinner className="size-3.5" /> : '↻'}
              </Button>
            }
          />
          <ErrorNote error={addons.error} />
          {addons.data === undefined ? (
            <div className="grid place-items-center py-8">
              <Spinner className="size-5" />
            </div>
          ) : addons.data.addons.length === 0 ? (
            <EmptyState title="No addons in the catalogue" />
          ) : (
            <>
              <AddonRows rows={addons.data.addons} />
              <div className="flex flex-wrap gap-2 border-t border-line px-4 py-3 dark:border-night-line">
                {addons.data.addons
                  .filter((addon) => !addon.enabled)
                  .map((addon) => (
                    <QuickAction
                      key={addon.name}
                      slug="addon.enable"
                      values={{ name: addon.name }}
                      size="sm"
                    >
                      Enable {addon.name}
                    </QuickAction>
                  ))}
              </div>
            </>
          )}
        </Card>
      )}

      {tab === 'cache' && (
        <Card>
          <CardHeader
            title="Caches"
            subtitle="Where each tool keeps what it downloads"
            actions={
              <Button
                size="sm"
                variant="ghost"
                onClick={() => void cache.refetch()}
                disabled={cache.isFetching}
              >
                {cache.isFetching ? <Spinner className="size-3.5" /> : '↻'}
              </Button>
            }
          />
          <ErrorNote error={cache.error} />
          {cache.data === undefined ? (
            <div className="grid place-items-center py-8">
              <Spinner className="size-5" />
            </div>
          ) : (
            <>
              <CacheRows rows={cache.data.cache} />
              <div className="flex flex-wrap gap-2 border-t border-line px-4 py-3 dark:border-night-line">
                <QuickAction slug="cache.clear" size="sm">
                  Clear everything
                </QuickAction>
                <QuickAction slug="cache.clear" values={{ composer: true }} size="sm" variant="ghost">
                  Composer only
                </QuickAction>
                <QuickAction slug="cache.clear" values={{ npm: true }} size="sm" variant="ghost">
                  npm only
                </QuickAction>
                <QuickAction slug="cache.clear" values={{ valet: true }} size="sm" variant="ghost">
                  Valet only
                </QuickAction>
              </div>
            </>
          )}
        </Card>
      )}

      {tab === 'machine' && (
        <Card>
          <CardHeader title="Reported versions" subtitle="What the dashboard can see" />
          <dl className="grid gap-2 px-4 py-3 text-[13px]">
            <div className="flex items-center justify-between gap-2">
              <dt className="text-muted dark:text-night-muted">Valet</dt>
              <dd className="font-mono">{dashboard.valet_version}</dd>
            </div>
            <div className="flex items-center justify-between gap-2">
              <dt className="text-muted dark:text-night-muted">PHP in use</dt>
              <dd className="font-mono">{dashboard.php_version}</dd>
            </div>
            <div className="flex items-center justify-between gap-2">
              <dt className="text-muted dark:text-night-muted">PHP installed</dt>
              <dd className="font-mono">{dashboard.php_versions.join(', ') || 'none'}</dd>
            </div>
            <div className="flex items-start justify-between gap-2">
              <dt className="text-muted dark:text-night-muted">Mail</dt>
              <dd className="font-mono text-[11px] break-all">{dashboard.mail_url}</dd>
            </div>
            <div className="flex items-start justify-between gap-2">
              <dt className="text-muted dark:text-night-muted">Database UI</dt>
              <dd className="font-mono text-[11px] break-all">{dashboard.database_url}</dd>
            </div>
          </dl>
          <div className="flex flex-wrap gap-2 border-t border-line px-4 py-3 dark:border-night-line">
            <Button size="sm" onClick={() => open('diagnose.run')} variant="ghost">
              Run diagnostics
            </Button>
            <Button size="sm" onClick={() => open('health.run')} variant="ghost">
              Run health check
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}
