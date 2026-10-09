/**
 * Query hooks.
 *
 * Everything the dashboard knows comes from two endpoints: /api/data for state
 * and /api/catalog for what may be done to it. React Query handles the
 * polling, the de-duplication of concurrent readers and the invalidation after
 * a mutation, so no page ever fetches the same thing twice.
 */

import {
  useMutation,
  useQuery,
  useQueryClient,
  type QueryKey,
  type UseMutationResult,
} from '@tanstack/react-query';
import { useCallback, useMemo, useRef, useState } from 'react';

import {
  fetchCatalog,
  fetchData,
  fetchJob,
  fetchJobs,
  indexCatalog,
  runAction,
} from './api';
import type {
  ActionDefinition,
  ActionSlug,
  CatalogPayload,
  DataPayload,
  Envelope,
  Job,
  ParamValues,
} from '../types';

/** Poll interval for the state payload, in milliseconds. */
export const DATA_POLL_MS = 5000;

/** Poll interval for the activity feed while a job is in flight. */
export const JOBS_POLL_MS = 1200;

/** Poll nothing while the tab is hidden: a background dashboard must be idle. */
function backgroundPoll(baseMs: number): number | false {
  if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
    return false;
  }

  return baseMs;
}

/** Dashboard state, polled every few seconds. */
export function useData() {
  return useQuery<Envelope<DataPayload>, Error>({
    queryKey: ['data'],
    queryFn: ({ signal }) => fetchData(signal),
    refetchInterval: () => backgroundPoll(DATA_POLL_MS),
    refetchIntervalInBackground: false,
    staleTime: 1000,
  });
}

/** The action catalogue. Never changes while the page is open. */
export function useCatalog() {
  return useQuery<Envelope<CatalogPayload>, Error>({
    queryKey: ['catalog'],
    queryFn: ({ signal }) => fetchCatalog(signal),
    staleTime: Infinity,
    gcTime: Infinity,
    refetchOnWindowFocus: false,
  });
}

/** The catalogue indexed by slug. */
export function useActions(): {
  actions: Map<ActionSlug, ActionDefinition>;
  ready: boolean;
} {
  const { data } = useCatalog();

  const actions = useMemo(
    () => indexCatalog(data?.data.actions ?? []),
    [data],
  );

  return { actions, ready: actions.size > 0 };
}

/** Recent jobs, polled quickly so the activity feed feels live. */
export function useJobs() {
  return useQuery<Envelope<{ jobs: Job[] }>, Error>({
    queryKey: ['jobs'],
    queryFn: ({ signal }) => fetchJobs(signal),
    refetchInterval: () => backgroundPoll(JOBS_POLL_MS),
    refetchIntervalInBackground: false,
    staleTime: 500,
  });
}

/** Whether any job is queued or running. */
export function useRunningJobs(): Job[] {
  const { data } = useJobs();

  return useMemo(
    () => (data?.data.jobs ?? []).filter((job) => job.status === 'queued' || job.status === 'running'),
    [data],
  );
}

/** Follow one job until it leaves the running states. */
export function useJob(id: string | null) {
  return useQuery<Envelope<{ job: Job }>, Error>({
    queryKey: ['jobs', id],
    queryFn: ({ signal }) => fetchJob(id as string, signal),
    enabled: id !== null,
    refetchInterval: (query) => {
      const job = query.state.data?.data.job;

      return job && job.status !== 'done' && job.status !== 'failed' && job.status !== 'cancelled'
        ? JOBS_POLL_MS
        : false;
    },
  });
}

/** A mutation that runs an action and refreshes state when it lands. */
export type ActionMutation = UseMutationResult<
  Envelope<Record<string, unknown>>,
  Error,
  { slug: ActionSlug; params: ParamValues }
>;

export function useAction(): ActionMutation {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ slug, params }) => runAction(slug, params),
    onSuccess: () => {
      // Every read payload is derived from the same collector, so one
      // invalidation refreshes the whole page.
      void queryClient.invalidateQueries({ queryKey: ['data'] });
      void queryClient.invalidateQueries({ queryKey: ['jobs'] });
      // On-demand reads (databases, snapshots, certificates…) are stale the
      // moment something changes, so they re-run too.
      void queryClient.invalidateQueries({ queryKey: ['read'] });
    },
  });
}

/**
 * A read-only action, run on demand and cached by its parameters.
 *
 * Read actions (logs.view, databases.list, diagnose.run, …) are not part of the
 * polled payload because some of them shell out; they run when a page asks and
 * the result is kept until the parameters change.
 */
export function useReadAction<T>(
  slug: ActionSlug,
  params: ParamValues = {},
  options: { enabled?: boolean } = {},
) {
  const key = JSON.stringify(params);

  return useQuery<Envelope<Record<string, unknown>>, Error, T, QueryKey>({
    queryKey: ['read', slug, key],
    queryFn: ({ signal }) => runAction(slug, params, signal),
    enabled: options.enabled ?? true,
    staleTime: 10_000,
    retry: false,
    select: (envelope) => envelope.data as unknown as T,
  });
}

/** Form state for a catalog-driven action, plus validation against its schema. */
export function useActionForm(action: ActionDefinition | undefined) {
  const [values, setValues] = useState<ParamValues>({});
  const [confirmInput, setConfirmInput] = useState('');
  const initialised = useRef<ActionSlug | null>(null);

  // Seed defaults once per action.
  if (action && initialised.current !== action.slug) {
    initialised.current = action.slug;

    const defaults: ParamValues = {};

    for (const [name, param] of Object.entries(action.params)) {
      if (param.default !== null) {
        defaults[name] = param.default as string | number | boolean;
      } else if (param.type === 'bool') {
        defaults[name] = false;
      }
    }

    setValues(defaults);
    setConfirmInput('');
  }

  const setValue = useCallback((name: string, value: string | number | boolean) => {
    setValues((current) => ({ ...current, [name]: value }));
  }, []);

  /** Parameter names that are required but still empty. */
  const missing = useMemo(() => {
    if (!action) {
      return [] as string[];
    }

    return Object.entries(action.params)
      .filter(([name, param]) => {
        if (!param.required) {
          return false;
        }

        const value = values[name];

        return value === undefined || value === null || value === '';
      })
      .map(([name]) => name);
  }, [action, values]);

  /**
   * What the user has to type to confirm.
   *
   * `confirm: 'name'` means the value of the `name` parameter, so the target
   * moves as the parameters are filled in; `true` means the literal "yes" and
   * a plain value is already a literal.
   */
  const confirmTarget = useMemo(() => {
    const target = action?.confirm ?? null;

    if (target === null || target === true) {
      return target;
    }

    const current = values[target];

    return current === undefined || current === '' ? String(target) : String(current);
  }, [action, values]);

  const confirmSatisfied = useMemo(() => {
    if (confirmTarget === null) {
      return true;
    }

    if (confirmTarget === true) {
      return confirmInput.trim().toLowerCase() === 'yes';
    }

    return confirmInput.trim() === confirmTarget;
  }, [confirmTarget, confirmInput]);

  const canSubmit = missing.length === 0 && confirmSatisfied;

  const reset = useCallback(() => {
    setValues({});
    setConfirmInput('');
    initialised.current = null;
  }, []);

  return {
    values,
    setValue,
    missing,
    confirmInput,
    setConfirmInput,
    confirmTarget,
    confirmSatisfied,
    canSubmit,
    reset,
  };
}
