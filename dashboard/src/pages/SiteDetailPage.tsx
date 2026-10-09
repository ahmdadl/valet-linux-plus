/**
 * One site.
 *
 * Everything that can be done to a single site, plus the snapshots that belong
 * to it. Reachable by URL so it can be bookmarked and shared.
 */

import { useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';

import { QuickAction } from '../components/QuickAction';
import { SnapshotRows } from '../components/panels';
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
import type { SnapshotsResult } from '../types';

export function SiteDetailPage() {
  const { name = '' } = useParams();
  const { data, isLoading, error } = useData();
  const { open } = useActionsApi();

  const site = useMemo(
    () => (data?.data.dashboard.sites ?? []).find((candidate) => candidate.name === name),
    [data, name],
  );

  const snapshots = useReadAction<SnapshotsResult>('snapshots.list', { site: name });

  if (error) {
    return <ErrorNote error={error} />;
  }

  if (isLoading && site === undefined) {
    return (
      <div className="grid place-items-center py-24">
        <Spinner className="size-6" />
      </div>
    );
  }

  if (site === undefined) {
    return (
      <EmptyState
        title={`No site named “${name}”`}
        hint="It may have been unlinked since this page was opened."
        action={
          <Link to="/sites">
            <Button variant="primary" size="sm">
              Back to sites
            </Button>
          </Link>
        }
      />
    );
  }

  const domain = data?.data.dashboard.domain ?? 'test';

  return (
    <div className="grid gap-4">
      <PageHeader
        title={site.name}
        description={
          site.proxy !== null
            ? `Proxied to ${site.proxy}`
            : `Served from ${shortenPath(site.path)}`
        }
        actions={
          <>
            <a href={site.url} target="_blank" rel="noreferrer">
              <Button size="sm">Visit {site.secured ? 'https' : 'http'}</Button>
            </a>
            <QuickAction slug="logs.view" values={{ service: 'nginx' }} size="sm">
              Nginx logs
            </QuickAction>
          </>
        }
      />

      <div className="grid gap-4 lg:grid-cols-[1fr_1.2fr]">
        <Card>
          <CardHeader title="Details" />
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 px-4 py-3 text-[13px]">
            <dt className="text-muted dark:text-night-muted">URL</dt>
            <dd className="truncate font-mono text-[12px]">
              <a href={site.url} className="text-accent hover:underline dark:text-accent">
                {site.url}
              </a>
            </dd>

            <dt className="text-muted dark:text-night-muted">Type</dt>
            <dd>
              <Badge tone={site.type === 'proxy' ? 'warn' : 'neutral'}>{site.type}</Badge>
            </dd>

            <dt className="text-muted dark:text-night-muted">Certificate</dt>
            <dd>
              <Badge tone={site.secured ? 'ok' : 'neutral'}>
                {site.secured ? 'trusted' : 'none'}
              </Badge>
            </dd>

            <dt className="text-muted dark:text-night-muted">PHP</dt>
            <dd>
              {site.isolated === null ? (
                <span className="text-muted dark:text-night-muted">
                  follows the global version
                </span>
              ) : (
                <Badge tone="accent">isolated to {site.isolated}</Badge>
              )}
            </dd>

            <dt className="text-muted dark:text-night-muted">Path</dt>
            <dd className="truncate font-mono text-[12px]" title={site.path}>
              {site.proxy ?? site.path}
            </dd>
          </dl>
        </Card>

        <Card>
          <CardHeader
            title="Actions"
            subtitle="Each one asks for the values it needs, then confirms"
          />
          <div className="grid gap-2 p-3 sm:grid-cols-2">
            {site.secured ? (
              <QuickAction slug="site.unsecure" values={{ name: site.name }}>
                Unsecure
              </QuickAction>
            ) : (
              <QuickAction slug="site.secure" values={{ name: site.name }} variant="primary">
                Secure with a certificate
              </QuickAction>
            )}

            {site.isolated === null ? (
              <QuickAction
                slug="site.isolate"
                values={{ name: site.name }}
                title={`Pin ${site.name} to one PHP version`}
              >
                Isolate a PHP version
              </QuickAction>
            ) : (
              <QuickAction slug="site.unisolate" values={{ name: site.name }}>
                Stop isolating
              </QuickAction>
            )}

            {site.proxy === null ? (
              <QuickAction slug="site.proxy" values={{ name: site.name }}>
                Proxy to another host
              </QuickAction>
            ) : (
              <QuickAction slug="site.unproxy" values={{ name: site.name }}>
                Remove the proxy
              </QuickAction>
            )}

            <QuickAction
              slug="cert.renew"
              values={{ site: `${site.name}.${domain}` }}
              variant="ghost"
              title="Re-issue this site's certificate if it is close to expiry"
            >
              Renew certificate
            </QuickAction>

            <QuickAction slug="snapshot.create" values={{ path: site.path }} variant="ghost">
              Snapshot this site
            </QuickAction>

            {site.type === 'linked' ? (
              <QuickAction slug="site.unlink" values={{ name: site.name }} variant="ghost">
                Unlink
              </QuickAction>
            ) : (
              <QuickAction
                slug="site.forget"
                values={{ path: site.path }}
                variant="ghost"
                title="Stop serving this parked directory"
              >
                Forget this path
              </QuickAction>
            )}

            <Button
              variant="ghost"
              onClick={() => open('db.url', { name: site.name })}
              title="Show the database URL for this site"
            >
              Database URL
            </Button>
          </div>
        </Card>
      </div>

      <Card>
        <CardHeader
          title="Snapshots"
          subtitle="Files and, optionally, the database for this site"
          actions={
            <Button
              size="sm"
              variant="ghost"
              onClick={() => void snapshots.refetch()}
              disabled={snapshots.isFetching}
            >
              {snapshots.isFetching ? <Spinner className="size-3.5" /> : '↻'}
            </Button>
          }
        />
        {snapshots.error ? (
          <div className="p-3">
            <ErrorNote error={snapshots.error} />
          </div>
        ) : (
          <SnapshotRows
            rows={snapshots.data?.snapshots ?? []}
            onRestore={(snapshot) =>
              open('snapshot.restore', { path: snapshot.path, name: snapshot.name })
            }
            onDelete={(snapshot) =>
              open('snapshot.delete', { path: snapshot.path, name: snapshot.name })
            }
          />
        )}
      </Card>
    </div>
  );
}
