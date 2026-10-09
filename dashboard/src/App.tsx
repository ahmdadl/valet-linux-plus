/**
 * Routes.
 *
 * Every path is a real URL under the dashboard host, so a page can be
 * bookmarked, reloaded or shared. PHP's front controller answers any GET that
 * is not /api/* with the built index.html, which is what makes these deep links
 * work.
 */

import { createBrowserRouter, RouterProvider } from 'react-router-dom';

import { AppShell } from './components/AppShell';
import { BackupsPage } from './pages/BackupsPage';
import { CertificatesPage } from './pages/CertificatesPage';
import { DatabasesPage } from './pages/DatabasesPage';
import { JobsPage } from './pages/JobsPage';
import { LogsPage } from './pages/LogsPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { OverviewPage } from './pages/OverviewPage';
import { PhpNodePage } from './pages/PhpNodePage';
import { ServicesPage } from './pages/ServicesPage';
import { SettingsPage } from './pages/SettingsPage';
import { SiteDetailPage } from './pages/SiteDetailPage';
import { SitesPage } from './pages/SitesPage';
import { SnapshotsPage } from './pages/SnapshotsPage';

const router = createBrowserRouter([
  {
    path: '/',
    element: <AppShell />,
    children: [
      { index: true, element: <OverviewPage /> },
      { path: 'sites', element: <SitesPage /> },
      { path: 'sites/:name', element: <SiteDetailPage /> },
      { path: 'services', element: <ServicesPage /> },
      { path: 'databases', element: <DatabasesPage /> },
      { path: 'php', element: <PhpNodePage /> },
      { path: 'snapshots', element: <SnapshotsPage /> },
      { path: 'backups', element: <BackupsPage /> },
      { path: 'certificates', element: <CertificatesPage /> },
      { path: 'logs', element: <LogsPage /> },
      { path: 'jobs', element: <JobsPage /> },
      { path: 'settings', element: <SettingsPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
]);

export function App() {
  return <RouterProvider router={router} />;
}
