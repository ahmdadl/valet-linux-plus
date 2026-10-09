import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiError, csrfToken, runAction } from '../src/lib/api';

/**
 * The CSRF double-submit is the only part of the security model the front end
 * owns, so it is pinned down here: the readable cookie must be echoed back in
 * the X-Valet-CSRF header on every mutation, and the request must stay
 * same-origin so the cookie is attached.
 */

const COOKIE = 'valet_csrf=abc123';

function mockCookie() {
  Object.defineProperty(document, 'cookie', {
    writable: true,
    configurable: true,
    value: COOKIE,
  });
}

describe('csrfToken', () => {
  it('reads the valet_csrf cookie', () => {
    mockCookie();

    expect(csrfToken()).toBe('abc123');
  });

  it('returns an empty string when the cookie is absent', () => {
    Object.defineProperty(document, 'cookie', {
      writable: true,
      configurable: true,
      value: 'other=1',
    });

    expect(csrfToken()).toBe('');
  });
});

describe('runAction', () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    mockCookie();
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('posts the parameters with the CSRF header and same-origin credentials', async () => {
    fetchMock.mockResolvedValue({
      ok: true,
      status: 200,
      text: async () => JSON.stringify({ ok: true, message: 'Linked', data: {}, job: null }),
    });

    const envelope = await runAction('site.link', { name: 'demo', path: '/srv/demo' });

    expect(envelope.ok).toBe(true);

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/actions/site.link');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect(init.headers['X-Valet-CSRF']).toBe('abc123');
    expect(init.headers['Content-Type']).toBe('application/json');
    expect(JSON.parse(init.body)).toEqual({ name: 'demo', path: '/srv/demo' });
  });

  it('raises an ApiError when the API refuses', async () => {
    fetchMock.mockResolvedValue({
      ok: false,
      status: 400,
      text: async () => JSON.stringify({ ok: false, message: 'Nope.', data: [], job: null }),
    });

    await expect(runAction('db.drop', { name: 'x' })).rejects.toThrow(ApiError);
    await expect(runAction('db.drop', { name: 'x' })).rejects.toThrow('Nope.');
  });

  it('raises an ApiError for a body it cannot parse', async () => {
    fetchMock.mockResolvedValue({
      ok: true,
      status: 200,
      text: async () => '<html>not json</html>',
    });

    await expect(runAction('health.run', {})).rejects.toThrow(/cannot read/);
  });

  it('surfaces the job id the API returns', async () => {
    fetchMock.mockResolvedValue({
      ok: true,
      status: 200,
      text: async () =>
        JSON.stringify({ ok: true, message: 'Started.', data: [], job: 'f'.repeat(32) }),
    });

    const envelope = await runAction('backup.create', { with_db: true });

    expect(envelope.job).toBe('f'.repeat(32));
  });
});
