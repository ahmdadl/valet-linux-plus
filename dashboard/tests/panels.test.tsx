import '@testing-library/jest-dom/vitest';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { ActionModal } from '../src/components/ActionModal';
import { LogPane, StatCard } from '../src/components/panels';
import { ToastProvider } from '../src/lib/toast';
import type { ActionDefinition, Privileges } from '../src/types';

/** The providers a dialog needs to stand on its own. */
function Providers({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return (
    <QueryClientProvider client={client}>
      <ToastProvider>{children}</ToastProvider>
    </QueryClientProvider>
  );
}

function renderWithProviders(ui: ReactNode) {
  return render(<Providers>{ui}</Providers>);
}

const privileges: Privileges = {
  enabled: false,
  helper_path: '/usr/local/libexec/valet-dashboard-helper',
  helper_installed: false,
  helper_trusted: false,
  sudoers_path: '/etc/sudoers.d/valet-dashboard',
  sudoers_installed: false,
  granted_users: [],
  install_command: 'sudo valet dashboard:privileges install',
  verbs: [],
};

const siteSecure: ActionDefinition = {
  slug: 'site.secure',
  label: 'Secure site',
  tier: 'root',
  destructive: false,
  confirm: null,
  job: false,
  params: {
    name: { type: 'string', required: true, default: null, in: null },
  },
};

const dbDrop: ActionDefinition = {
  slug: 'db.drop',
  label: 'Drop database',
  tier: 'user',
  destructive: true,
  confirm: 'name',
  job: false,
  params: {
    name: { type: 'database', required: true, default: null, in: null },
  },
};

describe('the action modal', () => {
  it('asks for the value the API wants confirmed before enabling the button', async () => {
    const user = userEvent.setup();

    renderWithProviders(<ActionModal action={dbDrop} privileges={privileges} onClose={() => undefined} />);

    const submit = screen.getByRole('button', { name: /drop database/i });
    expect(submit).toBeDisabled();

    await user.type(screen.getByLabelText(/name \*/i), 'app_db');
    await user.type(screen.getByLabelText(/type .* to confirm/i), 'wrong');
    expect(submit).toBeDisabled();

    await user.clear(screen.getByLabelText(/type .* to confirm/i));
    await user.type(screen.getByLabelText(/type .* to confirm/i), 'app_db');
    expect(submit).toBeEnabled();
  });

  it('explains why a root action cannot run without the helper', () => {
    renderWithProviders(<ActionModal action={siteSecure} privileges={privileges} onClose={() => undefined} />);

    expect(screen.getByText(/privileged helper/i)).toBeInTheDocument();
    expect(screen.getByText(privileges.install_command)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /secure site/i })).toBeDisabled();
  });

  it('closes on Escape', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    renderWithProviders(<ActionModal action={dbDrop} privileges={privileges} onClose={onClose} />);

    await user.keyboard('{Escape}');

    expect(onClose).toHaveBeenCalled();
  });
});

describe('the log pane', () => {
  it('shows the lines the API returned', () => {
    render(<LogPane lines={['[nginx] 127.0.0.1 GET /', '[nginx] 127.0.0.1 GET /api/data']} />);

    expect(screen.getByText(/127\.0\.0\.1 GET \//)).toBeInTheDocument();
  });

  it('says so when there is nothing to show', () => {
    render(<LogPane lines={[]} />);

    expect(screen.getByText(/no log lines/i)).toBeInTheDocument();
  });
});

describe('stat cards', () => {
  it('renders a label, a value and a hint', () => {
    render(<StatCard label="Sites" value={12} hint="4 secured" />);

    expect(screen.getByText('Sites')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument();
    expect(screen.getByText('4 secured')).toBeInTheDocument();
  });
});
