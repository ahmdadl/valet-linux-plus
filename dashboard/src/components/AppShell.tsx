/**
 * The application shell.
 *
 * Header, navigation, the privilege banner and the activity drawer live here
 * and survive every route change, so the dashboard keeps its identity the way
 * the old single-page build did — but with a real router underneath.
 */

import { useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';

import { ActivityDrawer } from '../components/ActivityDrawer';
import { JobWatcher, useHasRunningJobs } from '../components/JobWatcher';
import { Toaster } from '../components/Toaster';
import { DATA_POLL_MS, useData } from '../lib/hooks';
import { cx } from '../lib/format';
import { Badge, Button, Spinner } from '../components/ui';

interface NavItem {
  to: string;
  label: string;
  /** Short label used in the mobile bar. */
  short: string;
}

const NAV: NavItem[] = [
  { to: '/', label: 'Overview', short: 'Home' },
  { to: '/sites', label: 'Sites', short: 'Sites' },
  { to: '/services', label: 'Services', short: 'Svcs' },
  { to: '/databases', label: 'Databases', short: 'DBs' },
  { to: '/php', label: 'PHP & Node', short: 'PHP' },
  { to: '/snapshots', label: 'Snapshots', short: 'Snaps' },
  { to: '/backups', label: 'Backups', short: 'Bkp' },
  { to: '/certificates', label: 'Certificates', short: 'Certs' },
  { to: '/logs', label: 'Logs', short: 'Logs' },
  { to: '/jobs', label: 'Activity', short: 'Jobs' },
  { to: '/settings', label: 'Settings', short: 'Set' },
];

type Theme = 'light' | 'dark' | 'system';

function readTheme(): Theme {
  try {
    const stored = localStorage.getItem('valet-dashboard-theme');

    if (stored === 'light' || stored === 'dark') {
      return stored;
    }
  } catch {
    // Private browsing: fall back to the system preference.
  }

  return 'system';
}

/** Apply a theme choice to <html> and remember it. */
function applyTheme(theme: Theme) {
  const prefersDark = () => {
    // jsdom and some embedded webviews have no matchMedia; the OS preference
    // is simply unavailable there, which is the light default.
    if (typeof window.matchMedia !== 'function') {
      return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  };

  const dark = theme === 'dark' || (theme === 'system' && prefersDark());

  document.documentElement.classList.toggle('dark', dark);

  try {
    if (theme === 'system') {
      localStorage.removeItem('valet-dashboard-theme');
    } else {
      localStorage.setItem('valet-dashboard-theme', theme);
    }
  } catch {
    // Storage unavailable: the choice lasts for this page only.
  }
}

function ThemeToggle() {
  const [theme, setTheme] = useState<Theme>(readTheme);

  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  // Follow the OS while the user has not picked a side.
  useEffect(() => {
    if (theme !== 'system' || typeof window.matchMedia !== 'function') {
      return;
    }

    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const listener = () => applyTheme('system');

    query.addEventListener('change', listener);

    return () => query.removeEventListener('change', listener);
  }, [theme]);

  const next = theme === 'dark' ? 'light' : 'dark';

  return (
    <Button
      variant="ghost"
      size="sm"
      onClick={() => setTheme(next)}
      title={`Switch to ${next} mode`}
      aria-label={`Switch to ${next} mode`}
    >
      {theme === 'dark' ? '☀' : '☾'}
    </Button>
  );
}

/** The banner that explains why root-tier actions are unavailable. */
function PrivilegeBanner() {
  const { data } = useData();
  const privileges = data?.data.privileges;

  if (privileges === undefined || privileges.enabled) {
    return null;
  }

  return (
    <div className="border-b border-warn/30 bg-warn/10 dark:border-warn/30 dark:bg-warn/10">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-[13px] text-warn">
        <span className="font-medium">Root actions are disabled.</span>
        <span className="opacity-80">
          Securing sites, switching PHP, restarting services and changing ports need the
          privileged helper.
        </span>
        <code className="rounded bg-warn/15 px-1.5 py-0.5 font-mono text-[11px]">
          {privileges.install_command}
        </code>
      </div>
    </div>
  );
}

export function AppShell() {
  const { data, isFetching, refetch } = useData();
  const runningJobs = useHasRunningJobs();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const location = useLocation();

  const dashboard = data?.data.dashboard;

  // Close the drawer when navigating away from it.
  useEffect(() => {
    setDrawerOpen(false);
  }, [location.pathname]);

  return (
    <div className="min-h-full">
      <JobWatcher />

      <header className="sticky top-0 z-30 border-b border-line bg-paper/85 backdrop-blur-md dark:border-night-line dark:bg-night/85">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
          <div className="flex items-center gap-2.5">
            <span
              aria-hidden="true"
              className="grid size-8 place-items-center rounded-lg bg-accent text-sm font-bold text-white shadow-sm"
            >
              V
            </span>
            <div className="leading-tight">
              <p className="text-sm font-semibold tracking-tight">Valet</p>
              <p className="text-[11px] text-muted dark:text-night-muted">
                {dashboard === undefined ? 'connecting…' : `${dashboard.domain} · :${dashboard.port}`}
              </p>
            </div>
          </div>

          <div className="ml-auto flex items-center gap-2">
            {dashboard !== undefined && (
              <>
                <Badge tone="accent">PHP {dashboard.php_version}</Badge>
                <span className="hidden text-[11px] text-muted sm:inline dark:text-night-muted">
                  v{dashboard.valet_version}
                </span>
              </>
            )}

            {runningJobs && (
              <span className="flex items-center gap-1.5 text-[11px] text-accent">
                <Spinner className="size-3" /> job running
              </span>
            )}

            <Button
              variant="ghost"
              size="sm"
              onClick={() => void refetch()}
              disabled={isFetching}
              title={`Refresh (polls every ${DATA_POLL_MS / 1000}s)`}
              aria-label="Refresh"
            >
              {isFetching ? <Spinner className="size-3.5" /> : '↻'}
            </Button>

            <Button
              variant="ghost"
              size="sm"
              onClick={() => setDrawerOpen(true)}
              title="Activity"
              aria-label="Open activity drawer"
            >
              ☰
            </Button>

            <ThemeToggle />
          </div>
        </div>

        <nav aria-label="Dashboard sections" className="mx-auto max-w-7xl px-2">
          <ul className="flex gap-0.5 overflow-x-auto scroll-thin pb-1.5">
            {NAV.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  end={item.to === '/'}
                  className={({ isActive }) =>
                    cx(
                      'block rounded-lg px-2.5 py-1.5 text-[13px] font-medium whitespace-nowrap transition-colors',
                      isActive
                        ? 'bg-accent-soft text-accent-strong dark:bg-accent/15 dark:text-accent'
                        : 'text-muted hover:bg-line/60 hover:text-ink dark:text-night-muted dark:hover:bg-white/5 dark:hover:text-night-ink',
                    )
                  }
                >
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      </header>

      <PrivilegeBanner />

      <main className="mx-auto max-w-7xl px-4 py-5 pb-24">
        <Outlet />
      </main>

      <footer className="border-t border-line px-4 py-4 text-center text-[11px] text-muted dark:border-night-line dark:text-night-muted">
        valet-linux-plus · dashboard updates automatically
      </footer>

      <ActivityDrawer open={drawerOpen} onClose={() => setDrawerOpen(false)} />
      <Toaster />
    </div>
  );
}
