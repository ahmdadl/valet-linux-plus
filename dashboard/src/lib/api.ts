/**
 * HTTP client for the dashboard API.
 *
 * The security model lives in PHP (DashboardRequest + DashboardApi) and this
 * client is deliberately dumb about it: it only has to reproduce the
 * double-submit CSRF pattern exactly. The cookie `valet_csrf` is set by the
 * server with SameSite=Strict and httponly=false so JavaScript can read it; the
 * value must be echoed back in the `X-Valet-CSRF` header on every mutation.
 * `credentials: 'same-origin'` keeps the cookie on our own origin.
 */

import type {
  ActionDefinition,
  ActionSlug,
  CatalogPayload,
  DataPayload,
  Envelope,
  Job,
  ParamValues,
} from '../types';

/** Name of the CSRF cookie issued by DashboardServer. */
const CSRF_COOKIE = 'valet_csrf';

/** Header the API expects the CSRF token to arrive in. */
const CSRF_HEADER = 'X-Valet-CSRF';

/**
 * API origin.
 *
 * Same-origin in production (the dashboard serves both the page and the API).
 * `VITE_API_BASE` exists so a dev build can be pointed at another dashboard
 * host; it must be an origin the browser considers same-site for the CSRF
 * cookie to be sent.
 */
const API_BASE: string = (import.meta.env.VITE_API_BASE as string | undefined) ?? '';

/** Read the CSRF cookie, exactly as the vanilla dashboard did. */
export function csrfToken(): string {
  const match = document.cookie.match(
    new RegExp('(?:^|;\\s*)' + CSRF_COOKIE + '=([^;]*)'),
  );

  return match ? decodeURIComponent(match[1]) : '';
}

/** Raised for any non-2xx or `ok: false` response. */
export class ApiError extends Error {
  readonly status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST';
  body?: unknown;
  signal?: AbortSignal;
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<Envelope<T>> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };

  const method = options.method ?? 'GET';

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    // The double submit: echo the readable cookie back in a header.
    headers[CSRF_HEADER] = csrfToken();
  }

  const response = await fetch(API_BASE + path, {
    method,
    headers,
    credentials: 'same-origin',
    signal: options.signal,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  const text = await response.text();
  let payload: Envelope<T>;

  try {
    payload = JSON.parse(text) as Envelope<T>;
  } catch {
    throw new ApiError(
      response.ok ? 'The dashboard sent a response this build cannot read.' : `HTTP ${response.status}`,
      response.status,
    );
  }

  if (!response.ok || payload.ok === false) {
    throw new ApiError(payload.message || `HTTP ${response.status}`, response.status);
  }

  return payload;
}

/** First-paint payload: dashboard data, privileges and PHP version lists. */
export function fetchData(signal?: AbortSignal): Promise<Envelope<DataPayload>> {
  return request<DataPayload>('/api/data', { signal });
}

/** The public action catalogue that drives every form in the UI. */
export function fetchCatalog(signal?: AbortSignal): Promise<Envelope<CatalogPayload>> {
  return request<Envelope<CatalogPayload>['data']>('/api/catalog', { signal });
}

/** Recent background jobs, newest first. */
export function fetchJobs(signal?: AbortSignal): Promise<Envelope<{ jobs: Job[] }>> {
  return request<{ jobs: Job[] }>('/api/jobs', { signal });
}

/** One job, with the tail of its log. */
export function fetchJob(id: string, signal?: AbortSignal): Promise<Envelope<{ job: Job }>> {
  return request<{ job: Job }>(`/api/jobs/${encodeURIComponent(id)}`, { signal });
}

/**
 * Run an action.
 *
 * Parameters are sent as a flat JSON object; the API validates every one
 * against its schema (type, required, pattern, choices) before anything runs.
 */
export function runAction(
  slug: ActionSlug,
  params: ParamValues = {},
  signal?: AbortSignal,
): Promise<Envelope<Record<string, unknown>>> {
  return request<Record<string, unknown>>(`/api/actions/${slug}`, {
    method: 'POST',
    body: params,
    signal,
  });
}

/** Index a catalogue by slug for O(1) lookups while rendering. */
export function indexCatalog(actions: ActionDefinition[]): Map<ActionSlug, ActionDefinition> {
  const index = new Map<ActionSlug, ActionDefinition>();

  for (const action of actions) {
    index.set(action.slug, action);
  }

  return index;
}
