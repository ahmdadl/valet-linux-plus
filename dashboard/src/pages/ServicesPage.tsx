/**
 * Services.
 *
 * Live state from /api/data for the grid, and the richer services.list action
 * for enabled/active detail. Starting and stopping is root-tier, so the modal
 * carries the helper's own explanation when it is not installed.
 */

import { useState } from 'react';

import { QuickAction } from '../components/QuickAction';
import { ServiceRows } from '../components/panels';
import {
  Badge,
  Button,
  Card,
  CardHeader,
  ErrorNote,
  PageHeader,
  Spinner,
} from '../components/ui';
import { useActionsApi } from '../lib/actionContext';
import { useData, useReadAction } from '../lib/hooks';
import { cx, humanizeName } from '../lib/format';
import type { DashboardService, ServiceStatusRow } from '../types';

/** The service names the API accepts for start/stop/restart. */
const MANAGEABLE = ['dnsmasq', 'nginx', 'php', 'mailpit', 'mysql', 'redis', 'postgres'];

function ServiceTile({
  service,
  onRestart,
}: {
  service: DashboardService;
  onRestart: () => void;
}) {
  const running = service.status === 'running';
  const manageable = MANAGEABLE.includes(service.name);

  return (
    <li className="rounded-[var(--radius-card)] border border-line bg-card p-3.5 shadow-[var(--shadow-card)] dark:border-night-line dark:bg-night-card">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-[13px] font-semibold">{humanizeName(service.name)}</p>
          <p className="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted dark:text-night-muted">
            <span
              aria-hidden="true"
              className={cx(
                'size-2 rounded-full',
                running ? 'bg-ok' : service.installed ? 'bg-warn' : 'bg-line-strong dark:bg-night-line-strong',
              )}
            />
            {service.status}
          </p>
        </div>
        {!service.installed && <Badge tone="neutral">not installed</Badge>}
      </div>

      {manageable && (
        <div className="mt-3 flex flex-wrap gap-1.5">
          <QuickAction slug="service.start" values={{ service: service.name }} size="sm" variant="ghost">
            Start
          </QuickAction>
          <QuickAction
            slug="service.stop"
            values={{ service: service.name }}
            size="sm"
            variant="ghost"
          >
            Stop
          </QuickAction>
          <Button size="sm" variant="ghost" onClick={onRestart}>
            Restart
          </Button>
        </div>
      )}
    </li>
  );
}

export function ServicesPage() {
  const { data, isFetching, error } = useData();
  const { open } = useActionsApi();
  const [showDetail, setShowDetail] = useState(false);

  const detail = useReadAction<{ services: ServiceStatusRow[] }>('services.list', {}, {
    enabled: showDetail,
  });

  if (error) {
    return <ErrorNote error={error} />;
  }

  const services = data?.data.dashboard.services ?? [];

  return (
    <div className="grid gap-4">
      <PageHeader
        title="Services"
        description="Nginx, PHP-FPM, DNS, mail and the databases that serve your sites."
        actions={
          <>
            <Button size="sm" onClick={() => setShowDetail(true)} disabled={showDetail}>
              Detailed status
            </Button>
            <QuickAction slug="health.run" variant="primary" size="sm">
              Run health check
            </QuickAction>
          </>
        }
      />

      {services.length === 0 ? (
        <Card>
          <div className="grid place-items-center py-10">
            <Spinner className="size-6" />
          </div>
        </Card>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {services.map((service) => (
            <ServiceTile
              key={service.name}
              service={service}
              onRestart={() => open('service.restart', { service: service.name })}
            />
          ))}
        </ul>
      )}

      {showDetail && (
        <Card>
          <CardHeader
            title="Enabled and active"
            subtitle="From services.list: what the package manager and systemd report"
            actions={
              <Button
                size="sm"
                variant="ghost"
                onClick={() => void detail.refetch()}
                disabled={detail.isFetching}
              >
                {detail.isFetching ? <Spinner className="size-3.5" /> : '↻'}
              </Button>
            }
          />
          {detail.error ? (
            <div className="p-3">
              <ErrorNote error={detail.error} />
            </div>
          ) : detail.data === undefined ? (
            <div className="grid place-items-center py-8">
              <Spinner className="size-5" />
            </div>
          ) : (
            <ServiceRows rows={detail.data?.services ?? []} />
          )}
        </Card>
      )}

      <Card>
        <CardHeader
          title="Every service at once"
          subtitle="Restarting everything is the usual fix after a PHP switch"
        />
        <div className="flex flex-wrap gap-2 p-3">
          {['nginx', 'php', 'dnsmasq', 'mailpit'].map((service) => (
            <QuickAction key={service} slug="service.restart" values={{ service }} size="sm">
              Restart {humanizeName(service)}
            </QuickAction>
          ))}
        </div>
        {isFetching && (
          <p className="border-t border-line px-3 py-2 text-[11px] text-muted dark:border-night-line dark:text-night-muted">
            Refreshing…
          </p>
        )}
      </Card>
    </div>
  );
}
