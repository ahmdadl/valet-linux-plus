/**
 * Overview.
 *
 * The landing page answers three questions in one screen: how many sites are
 * there, is anything broken, and what do people actually do here.
 */

import { Link } from 'react-router-dom';

import { QuickAction } from '../components/QuickAction';
import { HealthRows, StatCard } from '../components/panels';
import { Badge, Button, Card, CardHeader, EmptyState, Spinner } from '../components/ui';
import { useActionsApi } from '../lib/actionContext';
import { useData } from '../lib/hooks';
import { humanizeName } from '../lib/format';
import type { ActionSlug } from '../types';

/** The actions worth a button on the landing page. */
const QUICK: Array<{ slug: ActionSlug; label: string; hint: string }> = [
  { slug: 'site.link', label: 'Link a site', hint: 'Point a name at a directory' },
  { slug: 'site.park', label: 'Park a path', hint: 'Serve every folder inside a path' },
  { slug: 'site.secure', label: 'Secure a site', hint: 'Issue a trusted certificate' },
  { slug: 'db.create', label: 'New database', hint: 'Create a MySQL database' },
  { slug: 'snapshot.create', label: 'Snapshot', hint: 'Save files and database' },
  { slug: 'backup.create', label: 'Back up', hint: 'Full Valet backup' },
  { slug: 'php.switch', label: 'Switch PHP', hint: 'Change the global version' },
  { slug: 'cache.clear', label: 'Clear caches', hint: 'Composer, npm and Valet' },
];

export function OverviewPage() {
  const { data, isLoading, error } = useData();
  const { open } = useActionsApi();

  if (error) {
    return (
      <EmptyState
        title="Could not read the dashboard"
        hint={error.message}
        action={
          <Button variant="primary" onClick={() => window.location.reload()}>
            Reload
          </Button>
        }
      />
    );
  }

  if (isLoading || data === undefined) {
    return (
      <div className="grid place-items-center py-24">
        <Spinner className="size-6" />
      </div>
    );
  }

  const { dashboard, privileges } = data.data;
  const { counts, services, health, sites } = dashboard;

  const running = services.filter((service) => service.status === 'running').length;
  const unhealthy = health.filter((row) => !row.healthy);
  const recentSites = sites.slice(0, 8);

  return (
    <div className="grid gap-5">
      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Sites"
          value={counts.total}
          hint={`${counts.parked} parked · ${counts.linked} linked · ${counts.proxied} proxied`}
          tone="accent"
        />
        <StatCard
          label="Secured"
          value={counts.secured}
          hint={`${counts.isolated} isolated to a PHP version`}
        />
        <StatCard
          label="Services"
          value={`${running}/${services.length}`}
          hint={unhealthy.length === 0 ? 'all healthy' : `${unhealthy.length} unhealthy`}
          tone={unhealthy.length === 0 ? 'ok' : 'warn'}
        />
        <StatCard
          label="Nginx configs"
          value={dashboard.nginx_sites}
          hint={`PHP ${dashboard.php_version} · port ${dashboard.port}`}
        />
      </section>

      <div className="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <Card>
          <CardHeader
            title="Health"
            subtitle={
              health.length === 0
                ? 'Run a check to see how the services are doing'
                : `${health.length - unhealthy.length} of ${health.length} checks passing`
            }
            actions={
              <QuickAction slug="health.run" size="sm">
                Run check
              </QuickAction>
            }
          />
          {health.length === 0 ? (
            <EmptyState
              title="No health data yet"
              hint="Run a check to probe Nginx, DNS, PHP-FPM and the database services."
            />
          ) : (
            <HealthRows rows={health} />
          )}
        </Card>

        <Card>
          <CardHeader
            title="Quick actions"
            subtitle="Everything here asks before it runs"
          />
          <ul className="grid gap-1.5 p-3 sm:grid-cols-2">
            {QUICK.map((item) => (
              <li key={item.slug}>
                <QuickAction
                  slug={item.slug}
                  className="h-auto w-full flex-col items-start gap-0.5 px-3 py-2 text-left"
                >
                  <span className="text-[13px] font-medium">{item.label}</span>
                  <span className="text-[11px] font-normal text-muted dark:text-night-muted">
                    {item.hint}
                  </span>
                </QuickAction>
              </li>
            ))}
          </ul>
          {!privileges.enabled && (
            <p className="border-t border-line px-3 py-2 text-[11px] text-muted dark:border-night-line dark:text-night-muted">
              Root-tier actions (securing sites, switching PHP, restarting services) need the
              privileged helper.
            </p>
          )}
        </Card>
      </div>

      <div className="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <Card>
          <CardHeader
            title="Sites"
            subtitle={`${sites.length} served under *.${dashboard.domain}`}
            actions={
              <Link
                to="/sites"
                className="text-xs font-medium text-accent hover:underline dark:text-accent"
              >
                Manage all →
              </Link>
            }
          />
          {recentSites.length === 0 ? (
            <EmptyState
              title="No sites yet"
              hint="Link a directory or park your projects folder to get started."
              action={
                <QuickAction slug="site.park" variant="primary" size="sm">
                  Park a path
                </QuickAction>
              }
            />
          ) : (
            <ul className="divide-y divide-line dark:divide-night-line">
              {recentSites.map((site) => (
                <li key={site.name} className="flex items-center gap-3 px-4 py-2.5">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-medium">{site.name}</p>
                    <p className="truncate font-mono text-[11px] text-muted dark:text-night-muted">
                      {site.url}
                    </p>
                  </div>
                  <span className="hidden sm:block">
                    <Badge tone={site.type === 'proxy' ? 'warn' : 'neutral'}>{site.type}</Badge>
                  </span>
                  {site.secured && <Badge tone="ok">secure</Badge>}
                  {site.isolated !== null && <Badge tone="accent">PHP {site.isolated}</Badge>}
                  <a
                    href={site.url}
                    target="_blank"
                    rel="noreferrer"
                    className="shrink-0 text-xs font-medium text-accent hover:underline dark:text-accent"
                  >
                    Visit
                  </a>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <CardHeader title="Services" subtitle="Start, stop and restart live on the Services page" />
          <ul className="grid gap-1.5 p-3">
            {services.map((service) => (
              <li
                key={service.name}
                className="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-2 dark:border-night-line"
              >
                <span className="truncate text-[13px] font-medium">
                  {humanizeName(service.name)}
                </span>
                <span className="flex items-center gap-2">
                  {!service.installed && <Badge tone="neutral">not installed</Badge>}
                  <Badge tone={service.status === 'running' ? 'ok' : 'neutral'}>
                    {service.status}
                  </Badge>
                  <button
                    type="button"
                    className="text-xs font-medium text-accent hover:underline dark:text-accent"
                    onClick={() => open('service.restart', { service: service.name })}
                  >
                    Restart
                  </button>
                </span>
              </li>
            ))}
          </ul>
          <p className="border-t border-line px-3 py-2 text-[11px] text-muted dark:border-night-line dark:text-night-muted">
            Start, stop and restart run through the privileged helper when it is installed;
            otherwise the API refuses them.
          </p>
        </Card>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <Card className="flex items-center justify-between gap-3 p-3.5">
          <div>
            <p className="text-[13px] font-medium">Mail catcher</p>
            <p className="font-mono text-[11px] text-muted dark:text-night-muted">
              {dashboard.mail_url}
            </p>
          </div>
          <Button size="sm" onClick={() => window.open(dashboard.mail_url, '_blank', 'noreferrer')}>
            Open
          </Button>
        </Card>
        <Card className="flex items-center justify-between gap-3 p-3.5">
          <div>
            <p className="text-[13px] font-medium">Database UI</p>
            <p className="font-mono text-[11px] text-muted dark:text-night-muted">
              {dashboard.database_url}
            </p>
          </div>
          <Button
            size="sm"
            onClick={() => window.open(dashboard.database_url, '_blank', 'noreferrer')}
          >
            Open
          </Button>
        </Card>
      </div>
    </div>
  );
}
