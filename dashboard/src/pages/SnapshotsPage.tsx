/**
 * Snapshots.
 *
 * A snapshot is one site's files plus, optionally, its database. Restoring and
 * deleting are destructive and run as jobs, so both are confirmed and both are
 * followed in the activity feed.
 */

import { useMemo, useState } from 'react';

import { QuickAction } from '../components/QuickAction';
import { SnapshotRows } from '../components/panels';
import {
  Card,
  CardHeader,
  EmptyState,
  ErrorNote,
  PageHeader,
  Select,
  Spinner,
} from '../components/ui';
import { useActionsApi } from '../lib/actionContext';
import { useData, useReadAction } from '../lib/hooks';
import type { SnapshotsResult } from '../types';

export function SnapshotsPage() {
  const { data } = useData();
  const { open } = useActionsApi();

  const sites = data?.data.dashboard.sites ?? [];

  // The API lists snapshots per site; leave it empty to use the current
  // project directory, which for a dashboard request is not meaningful, so
  // default to the first site instead.
  const [site, setSite] = useState('');

  const snapshots = useReadAction<SnapshotsResult>('snapshots.list', { site });

  const rows = useMemo(() => snapshots.data?.snapshots ?? [], [snapshots.data]);
  const visible = useMemo(
    () => (site === '' ? rows : rows.filter((row) => row.site === site)),
    [rows, site],
  );

  return (
    <div className="grid gap-4">
      <PageHeader
        title="Snapshots"
        description="Point-in-time copies of a site's files and database, restorable at any time."
        actions={
          <>
            <Select
              value={site}
              onChange={(event) => setSite(event.target.value)}
              aria-label="Filter by site"
              className="w-44"
            >
              <option value="">All sites</option>
              {sites.map((candidate) => (
                <option key={candidate.name} value={candidate.name}>
                  {candidate.name}
                </option>
              ))}
            </Select>
            <QuickAction slug="snapshot.create" variant="primary" size="sm">
              Take a snapshot
            </QuickAction>
          </>
        }
      />

      <ErrorNote error={snapshots.error} />

      <Card>
        <CardHeader
          title="Stored snapshots"
          subtitle={
            snapshots.isFetching
              ? 'Reading snapshot directories…'
              : `${visible.length} snapshot${visible.length === 1 ? '' : 's'}`
          }
          actions={
            <button
              type="button"
              onClick={() => void snapshots.refetch()}
              disabled={snapshots.isFetching}
              className="text-xs font-medium text-accent hover:underline disabled:opacity-50 dark:text-accent"
            >
              {snapshots.isFetching ? 'Loading…' : 'Refresh'}
            </button>
          }
        />

        {snapshots.isLoading ? (
          <div className="grid place-items-center py-10">
            <Spinner className="size-5" />
          </div>
        ) : visible.length === 0 ? (
          <EmptyState
            title="No snapshots yet"
            hint="Snapshots are taken per site. Pick a path, give it a name and decide whether the database comes with it."
            action={
              <QuickAction slug="snapshot.create" variant="primary" size="sm">
                Take the first one
              </QuickAction>
            }
          />
        ) : (
          <SnapshotRows
            rows={visible}
            onRestore={(snapshot) =>
              open('snapshot.restore', { path: snapshot.path, name: snapshot.name })
            }
            onDelete={(snapshot) =>
              open('snapshot.delete', { path: snapshot.path, name: snapshot.name })
            }
          />
        )}
      </Card>

      <Card>
        <CardHeader title="What a snapshot contains" />
        <dl className="grid gap-2 px-4 py-3 text-[13px]">
          <div>
            <dt className="font-medium">Files</dt>
            <dd className="text-muted dark:text-night-muted">
              The site directory as it was, written beside the original under Valet's snapshot
              root.
            </dd>
          </div>
          <div>
            <dt className="font-medium">Database</dt>
            <dd className="text-muted dark:text-night-muted">
              Optional. A dump is taken with the snapshot so a restore brings the data back too.
            </dd>
          </div>
          <div>
            <dt className="font-medium">Restoring</dt>
            <dd className="text-muted dark:text-night-muted">
              Runs as a background job: the current files are replaced, so the confirmation asks
              for the snapshot name.
            </dd>
          </div>
        </dl>
      </Card>
    </div>
  );
}
