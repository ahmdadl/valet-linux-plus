/**
 * Background job tracking.
 *
 * Long actions return a job id and run detached. /api/jobs is polled by the
 * layout, and this watcher turns every state change into a toast so a job that
 * started in another tab still reports back here.
 */

import { useEffect, useRef } from 'react';

import { JOBS_POLL_MS, useJobs } from '../lib/hooks';
import { useToast } from '../lib/toast';
import type { JobStatus } from '../types';

const TERMINAL: JobStatus[] = ['done', 'failed', 'cancelled'];

export function JobWatcher() {
  const { data } = useJobs();
  const { push } = useToast();
  const seen = useRef(new Map<string, JobStatus>());

  const jobs = data?.data.jobs ?? [];

  useEffect(() => {
    for (const job of jobs) {
      const previous = seen.current.get(job.id);
      seen.current.set(job.id, job.status);

      if (previous !== undefined && previous !== job.status && TERMINAL.includes(job.status)) {
        push({
          tone: job.status === 'done' ? 'success' : 'error',
          title: `${job.slug} ${job.status}`,
          description: job.message || undefined,
        });
      }
    }

    // Keep the map from growing without bound.
    if (seen.current.size > 60) {
      const live = new Map(jobs.map((job) => [job.id, job.status] as const));
      seen.current = live;
    }
  }, [jobs, push]);

  return null;
}

/** True while any job is queued or running. */
export function useHasRunningJobs(): boolean {
  const { data } = useJobs();

  return (data?.data.jobs ?? []).some((job) => !TERMINAL.includes(job.status));
}

export const JOB_POLL_INTERVAL = JOBS_POLL_MS;
