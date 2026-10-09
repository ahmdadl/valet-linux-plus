/**
 * Activity.
 *
 * The full job feed. /api/jobs is polled every 1.2 seconds, so a job started
 * anywhere — another tab, the CLI — shows up here within a second or two.
 */

import { useState } from 'react';
import { Link } from 'react-router-dom';

import { useJobs } from '../lib/hooks';
import { clockTime, cx, timeAgo } from '../lib/format';
import { Badge, Card, CardHeader, CodeBlock, EmptyState, Spinner } from '../components/ui';
import type { Job, JobStatus } from '../types';

const TONE: Record<JobStatus, 'ok' | 'bad' | 'accent' | 'neutral'> = {
  done: 'ok',
  failed: 'bad',
  cancelled: 'neutral',
  running: 'accent',
  queued: 'accent',
};

export function JobsPage() {
  const { data, isFetching } = useJobs();
  const [selectedId, setSelectedId] = useState<string | null>(null);

  const jobs = data?.data.jobs ?? [];
  const selected = jobs.find((job) => job.id === selectedId) ?? null;

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Activity</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted dark:text-night-muted">
            Background jobs, newest first. Polled every 1.2 seconds while this page is open.
          </p>
        </div>
        {isFetching && (
          <span className="flex items-center gap-1.5 text-[11px] text-muted dark:text-night-muted">
            <Spinner className="size-3" /> syncing
          </span>
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_1.1fr]">
        <Card className="overflow-hidden">
          <CardHeader title="Recent jobs" subtitle={`${jobs.length} recorded`} />
          {jobs.length === 0 ? (
            <EmptyState
              title="Nothing has run yet"
              hint="Imports, exports, snapshots and backups appear here as soon as they start."
              action={
                <Link to="/backups">
                  <button
                    type="button"
                    className="text-xs font-medium text-accent hover:underline dark:text-accent"
                  >
                    Create a backup →
                  </button>
                </Link>
              }
            />
          ) : (
            <ul className="divide-y divide-line dark:divide-night-line">
              {jobs.map((job) => (
                <li key={job.id}>
                  <button
                    type="button"
                    onClick={() => setSelectedId(job.id === selectedId ? null : job.id)}
                    className={cx(
                      'w-full px-4 py-2.5 text-left transition-colors',
                      job.id === selectedId
                        ? 'bg-accent-soft/60 dark:bg-accent/10'
                        : 'hover:bg-line/40 dark:hover:bg-white/5',
                    )}
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="truncate font-mono text-[12px] font-medium">{job.slug}</span>
                      <Badge tone={TONE[job.status]}>{job.status}</Badge>
                    </div>
                    <div className="mt-0.5 flex items-center justify-between gap-2 text-[11px] text-muted dark:text-night-muted">
                      <span className="truncate">{job.message || '—'}</span>
                      <span className="shrink-0">{timeAgo(job.started_at)}</span>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card className="overflow-hidden">
          <CardHeader
            title="Job detail"
            subtitle={selected === null ? 'Pick a job to see its output' : selected.slug}
          />
          {selected === null ? (
            <EmptyState title="No job selected" hint="Logs appear as the job writes them." />
          ) : (
            <JobDetail job={selected} />
          )}
        </Card>
      </div>
    </div>
  );
}

function JobDetail({ job }: { job: Job }) {
  const [showParams, setShowParams] = useState(false);

  return (
    <div className="grid gap-3 p-3">
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[12px]">
        <dt className="text-muted dark:text-night-muted">Status</dt>
        <dd>
          <Badge tone={TONE[job.status]}>{job.status}</Badge>
        </dd>

        <dt className="text-muted dark:text-night-muted">Started</dt>
        <dd>
          {clockTime(job.started_at)} <span className="text-muted">({timeAgo(job.started_at)})</span>
        </dd>

        <dt className="text-muted dark:text-night-muted">Finished</dt>
        <dd>{job.finished_at === null ? '—' : clockTime(job.finished_at)}</dd>

        <dt className="text-muted dark:text-night-muted">Message</dt>
        <dd className="min-w-0 break-words">{job.message || '—'}</dd>

        <dt className="text-muted dark:text-night-muted">Id</dt>
        <dd className="font-mono text-[11px] break-all">{job.id}</dd>
      </dl>

      <div>
        <button
          type="button"
          onClick={() => setShowParams((current) => !current)}
          className="text-xs font-medium text-accent hover:underline dark:text-accent"
        >
          {showParams ? 'Hide' : 'Show'} parameters
        </button>
        {showParams && (
          <CodeBlock maxHeight="12rem" className="mt-2">
            {JSON.stringify(job.params, null, 2)}
          </CodeBlock>
        )}
      </div>

      <div>
        <p className="mb-1 text-xs font-medium text-muted dark:text-night-muted">Output</p>
        {job.log === undefined || job.log === '' ? (
          <p className="text-[12px] text-muted dark:text-night-muted">
            No output yet. Finished jobs keep the tail of their log.
          </p>
        ) : (
          <CodeBlock maxHeight="28rem">{job.log}</CodeBlock>
        )}
      </div>

      {Object.keys(job.data ?? {}).length > 0 && (
        <div>
          <p className="mb-1 text-xs font-medium text-muted dark:text-night-muted">Result data</p>
          <CodeBlock maxHeight="16rem">{JSON.stringify(job.data, null, 2)}</CodeBlock>
        </div>
      )}
    </div>
  );
}
