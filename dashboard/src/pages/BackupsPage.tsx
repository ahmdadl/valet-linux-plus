/**
 * Backups.
 *
 * Whole-environment backups, not per-site: every database and the Valet
 * configuration in one archive. Creating one is a job; restoring one is a job
 * that replaces what is there now.
 */

import { useState } from 'react';

import { QuickAction } from '../components/QuickAction';
import {
  Card,
  CardHeader,
  EmptyState,
  ErrorNote,
  Input,
  PageHeader,
  Spinner,
} from '../components/ui';
import { useReadAction } from '../lib/hooks';
import { matches } from '../lib/format';
import type { BackupsResult } from '../types';

export function BackupsPage() {
  const [search, setSearch] = useState('');

  const backups = useReadAction<BackupsResult>('backups.list');
  const archives = backups.data?.backups ?? [];
  const visible = archives.filter((archive) => matches(archive, search));

  return (
    <div className="grid gap-4">
      <PageHeader
        title="Backups"
        description="Archives of the whole Valet environment, including every database."
        actions={
          <QuickAction slug="backup.create" variant="primary" size="sm">
            Create a backup
          </QuickAction>
        }
      />

      <ErrorNote error={backups.error} />

      <Card>
        <CardHeader
          title="Archives"
          subtitle={
            backups.isFetching
              ? 'Reading the backup directory…'
              : `${visible.length} of ${archives.length} archives`
          }
          actions={
            <button
              type="button"
              onClick={() => void backups.refetch()}
              disabled={backups.isFetching}
              className="text-xs font-medium text-accent hover:underline disabled:opacity-50 dark:text-accent"
            >
              {backups.isFetching ? 'Loading…' : 'Refresh'}
            </button>
          }
        />

        <div className="border-b border-line p-3 dark:border-night-line">
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Filter archives by name…"
            aria-label="Filter backups"
          />
        </div>

        {backups.isLoading ? (
          <div className="grid place-items-center py-10">
            <Spinner className="size-5" />
          </div>
        ) : visible.length === 0 ? (
          <EmptyState
            title={archives.length === 0 ? 'No backups yet' : 'Nothing matches that filter'}
            hint={
              archives.length === 0
                ? 'A backup collects the Valet home directory and a dump of every database into one .tar.gz.'
                : 'Try a shorter search term.'
            }
            action={
              archives.length === 0 ? (
                <QuickAction slug="backup.create" variant="primary" size="sm">
                  Create the first one
                </QuickAction>
              ) : undefined
            }
          />
        ) : (
          <ul className="divide-y divide-line dark:divide-night-line">
            {visible.map((archive) => (
              <li key={archive} className="flex flex-wrap items-center gap-2 px-4 py-2.5">
                <div className="min-w-0 flex-1">
                  <p className="truncate font-mono text-[12px]" title={archive}>
                    {archive.split('/').pop() ?? archive}
                  </p>
                  <p className="truncate text-[11px] text-muted dark:text-night-muted" title={archive}>
                    {archive}
                  </p>
                </div>
                <QuickAction
                  slug="backup.restore"
                  values={{ archive }}
                  size="sm"
                  variant="ghost"
                  title="Replace the current environment with this archive"
                >
                  Restore
                </QuickAction>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="Before you restore" />
        <div className="grid gap-2 px-4 py-3 text-[13px]">
          <p className="text-muted dark:text-night-muted">
            Restoring replaces the current Valet home directory and re-imports every database dump
            in the archive. It runs as a background job and asks you to type the archive name to
            confirm, because there is no undo.
          </p>
          <p className="text-[11px] text-muted dark:text-night-muted">
            Archives are plain <code className="font-mono">.tar.gz</code> files under Valet's
            backup directory, so they can be copied elsewhere with any file manager.
          </p>
          <QuickAction slug="backup.create" variant="ghost" size="sm" className="justify-self-start">
            Take a fresh backup first
          </QuickAction>
        </div>
      </Card>
    </div>
  );
}
