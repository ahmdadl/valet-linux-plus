/**
 * The activity drawer.
 *
 * A side panel over the current page showing the recent jobs and the tail of
 * the selected job's log. It is the one place where a background operation
 * becomes visible without leaving whatever page started it.
 */

import { useState } from 'react';
import { Link } from 'react-router-dom';

import { useJob, useJobs } from '../lib/hooks';
import { clockTime, cx, timeAgo } from '../lib/format';
import type { Job, JobStatus } from '../types';
import { Badge, Button, CodeBlock, EmptyState, Spinner } from './ui';

const STATUS_TONE: Record<JobStatus, 'ok' | 'bad' | 'accent' | 'neutral'> = {
  done: 'ok',
  failed: 'bad',
  cancelled: 'neutral',
  running: 'accent',
  queued: 'accent',
};

function JobRow({ job, selected, onSelect }: { job: Job; selected: boolean; onSelect: () => void }) {
  return (
    <li>
      <button
        type="button"
        onClick={onSelect}
        className={cx(
          'w-full rounded-lg border px-3 py-2 text-left transition-colors',
          selected
            ? 'border-accent bg-accent-soft/60 dark:bg-accent/10'
            : 'border-line hover:border-line-strong dark:border-night-line dark:hover:border-night-line-strong',
        )}
      >
        <div className="flex items-center justify-between gap-2">
          <span className="truncate font-mono text-[12px] font-medium">{job.slug}</span>
          <Badge tone={STATUS_TONE[job.status]}>{job.status}</Badge>
        </div>
        <div className="mt-0.5 flex items-center justify-between gap-2 text-[11px] text-muted dark:text-night-muted">
          <span className="truncate">{job.message || '—'}</span>
          <span className="shrink-0">{timeAgo(job.started_at)}</span>
        </div>
      </button>
    </li>
  );
}

export function ActivityDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data, isLoading } = useJobs();
  const [selectedId, setSelectedId] = useState<string | null>(null);

  const jobs = data?.data.jobs ?? [];
  const selected = jobs.find((job) => job.id === selectedId) ?? null;
  const detail = useJob(selectedId);

  return (
    <aside
      aria-hidden={!open}
      className={cx(
        'fixed inset-y-0 right-0 z-40 flex w-full max-w-sm flex-col border-l border-line bg-card',
        'transition-transform duration-200 dark:border-night-line dark:bg-night-card',
        open ? 'translate-x-0' : 'pointer-events-none translate-x-full',
      )}
    >
      <header className="flex items-center justify-between gap-2 border-b border-line px-4 py-3 dark:border-night-line">
        <div>
          <h2 className="text-sm font-semibold">Activity</h2>
          <p className="text-[11px] text-muted dark:text-night-muted">
            Background jobs, newest first
          </p>
        </div>
        <Button variant="ghost" size="sm" onClick={onClose} aria-label="Close activity">
          ✕
        </Button>
      </header>

      <div className="min-h-0 flex-1 overflow-auto scroll-thin px-3 py-3">
        {isLoading && jobs.length === 0 && (
          <div className="grid place-items-center py-8">
            <Spinner />
          </div>
        )}

        {!isLoading && jobs.length === 0 && (
          <EmptyState
            title="No jobs yet"
            hint="Imports, exports, snapshots and backups run here when they are too long for a single request."
          />
        )}

        {jobs.length > 0 && (
          <ul className="grid gap-2">
            {jobs.map((job) => (
              <JobRow
                key={job.id}
                job={job}
                selected={job.id === selectedId}
                onSelect={() => setSelectedId(job.id === selectedId ? null : job.id)}
              />
            ))}
          </ul>
        )}

        {selected !== null && (
          <div className="mt-3 grid gap-2 rounded-lg border border-line p-3 dark:border-night-line">
            <div className="flex items-center justify-between gap-2">
              <span className="font-mono text-[11px] text-muted dark:text-night-muted">
                {selected.id}
              </span>
              <Badge tone={STATUS_TONE[selected.status]}>{selected.status}</Badge>
            </div>

            <p className="text-[13px]">{selected.message}</p>

            <dl className="grid grid-cols-2 gap-1 text-[11px] text-muted dark:text-night-muted">
              <dt>Started</dt>
              <dd className="text-right">{clockTime(selected.started_at)}</dd>
              <dt>Finished</dt>
              <dd className="text-right">{clockTime(selected.finished_at)}</dd>
            </dl>

            {detail.isFetching && selected.status !== 'done' && (
              <p className="flex items-center gap-2 text-[11px] text-muted dark:text-night-muted">
                <Spinner className="size-3" /> Following…
              </p>
            )}

            {detail.data?.data.job.log !== undefined && detail.data.data.job.log !== '' && (
              <CodeBlock maxHeight="12rem">{detail.data.data.job.log}</CodeBlock>
            )}

            <Link
              to="/jobs"
              onClick={onClose}
              className="text-xs font-medium text-accent hover:underline dark:text-accent"
            >
              Full activity feed →
            </Link>
          </div>
        )}
      </div>
    </aside>
  );
}
