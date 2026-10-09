import '@testing-library/jest-dom/vitest';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { App } from '../src/App';
import { ActionProvider } from '../src/lib/actionContext';
import { ToastProvider } from '../src/lib/toast';
import type { CatalogPayload, DataPayload } from '../src/types';

/**
 * A whole-app smoke test.
 *
 * The dashboard is a single React tree over one polled payload, so the failure
 * mode that matters is a tree that renders nothing. This boots the shell against
 * a fixed API response and asserts the real content appears.
 */

const dataPayload: DataPayload = {
  dashboard: {
    domain: 'test',
    port: 80,
    https_port: 443,
    php_version: '8.4',
    php_versions: ['8.3', '8.4'],
    paths: ['/home/me/projects'],
    sites: [
      {
        name: 'demo',
        url: 'https://demo.test',
        path: '/home/me/projects/demo',
        secured: true,
        isolated: '8.2',
        proxy: null,
        type: 'parked',
      },
    ],
    counts: { parked: 1, linked: 0, proxied: 0, secured: 1, isolated: 1, total: 1 },
    services: [
      { name: 'nginx', installed: true, status: 'running' },
      { name: 'php8.4-fpm', installed: true, status: 'running' },
      { name: 'mysql', installed: true, status: 'stopped' },
    ],
    health: [{ service: 'nginx', healthy: true, message: 'answering' }],
    mail_url: 'https://mails.test',
    database_url: 'https://database.valet.test',
    nginx_sites: 3,
    valet_version: '3.2.1',
    privileges: {
      enabled: false,
      helper_path: '/usr/local/libexec/valet-dashboard-helper',
      helper_installed: false,
      helper_trusted: false,
      sudoers_path: '/etc/sudoers.d/valet-dashboard',
      sudoers_installed: false,
      granted_users: [],
      install_command: 'sudo valet dashboard:privileges install',
      verbs: [],
    },
    php_switch: ['8.3', '8.4'],
  },
  privileges: {
    enabled: false,
    helper_path: '/usr/local/libexec/valet-dashboard-helper',
    helper_installed: false,
    helper_trusted: false,
    sudoers_path: '/etc/sudoers.d/valet-dashboard',
    sudoers_installed: false,
    granted_users: [],
    install_command: 'sudo valet dashboard:privileges install',
    verbs: [],
  },
  csrf: 'a'.repeat(64),
  php_versions: ['8.3', '8.4'],
  php_isolation: ['8.1', '8.2'],
};

const catalog: CatalogPayload = {
  tiers: ['read', 'user', 'root'],
  actions: [
    {
      slug: 'site.secure',
      label: 'Secure site',
      tier: 'root',
      destructive: false,
      confirm: null,
      job: false,
      params: { name: { type: 'string', required: true, default: null, in: null } },
    },
    {
      slug: 'health.run',
      label: 'Service health',
      tier: 'read',
      destructive: false,
      confirm: null,
      job: false,
      params: {},
    },
  ],
};

function jsonResponse(body: unknown) {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ ok: true, message: '', data: body, job: null }),
  };
}

describe('the dashboard shell', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => {
        if (url.includes('/api/data')) {
          return jsonResponse(dataPayload);
        }

        if (url.includes('/api/catalog')) {
          return jsonResponse(catalog);
        }

        if (url.includes('/api/jobs')) {
          return jsonResponse({ jobs: [] });
        }

        throw new Error('Unexpected fetch: ' + url);
      }),
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function renderShell() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
      <QueryClientProvider client={client}>
        <ToastProvider>
          <ActionProvider>
            <App />
          </ActionProvider>
        </ToastProvider>
      </QueryClientProvider>,
    );
  }

  it('renders the header, the navigation and the payload it fetched', async () => {
    renderShell();

    await waitFor(() => expect(screen.getByText('PHP 8.4')).toBeInTheDocument());

    // The brand, the domain and every section are present.
    expect(screen.getByText('Valet')).toBeInTheDocument();
    expect(screen.getByText('test · :80')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Overview' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Services' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Settings' })).toBeInTheDocument();

    // The overview stat cards come from the payload.
    await waitFor(() =>
      expect(screen.getByText('1 parked · 0 linked · 0 proxied')).toBeInTheDocument(),
    );
    expect(screen.getByText('all healthy')).toBeInTheDocument();
  });

  it('tells the user root actions are unavailable and how to enable them', async () => {
    renderShell();

    await waitFor(() =>
      expect(screen.getByText(/Root actions are disabled/i)).toBeInTheDocument(),
    );
    expect(
      screen.getByText('sudo valet dashboard:privileges install'),
    ).toBeInTheDocument();
  });

  it('shows the site it was given', async () => {
    renderShell();

    await waitFor(() => expect(screen.getByText('demo')).toBeInTheDocument());
    expect(screen.getByText('https://demo.test')).toBeInTheDocument();
  });
});
