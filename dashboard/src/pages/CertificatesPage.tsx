/**
 * Certificates.
 *
 * Which sites have a certificate, how long it has left, and the two root-tier
 * operations that keep them trusted: trusting the CA once and renewing when a
 * certificate is close to expiry.
 */

import { Link } from 'react-router-dom';

import { QuickAction } from '../components/QuickAction';
import { CertificateRows } from '../components/panels';
import {
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorNote,
  PageHeader,
  Spinner,
} from '../components/ui';
import { useReadAction } from '../lib/hooks';
import type { CertificatesResult } from '../types';

export function CertificatesPage() {
  const certs = useReadAction<CertificatesResult>('certs.list');
  const rows = certs.data?.certificates ?? [];
  const expiring = rows.filter(
    (row) => row.days_remaining !== null && row.days_remaining <= 30,
  );

  return (
    <div className="grid gap-4">
      <PageHeader
        title="Certificates"
        description="Valet issues its own CA and signs a certificate per secured site."
        actions={
          <>
            <Button
              size="sm"
              onClick={() => void certs.refetch()}
              disabled={certs.isFetching}
              title="Re-read the certificates"
            >
              {certs.isFetching ? <Spinner className="size-3.5" /> : '↻'}
            </Button>
            <QuickAction slug="trust.ca" variant="primary" size="sm">
              Trust the CA
            </QuickAction>
          </>
        }
      />

      {expiring.length > 0 && (
        <div className="rounded-lg border border-warn/30 bg-warn/10 px-3 py-2 text-[13px] text-warn dark:border-warn/40 dark:bg-warn/15">
          {expiring.length} certificate{expiring.length === 1 ? '' : 's'} expire within 30 days.
          Force-renew everything below to replace them all at once.
        </div>
      )}

      <ErrorNote error={certs.error} />

      <Card>
        <CardHeader
          title="Issued certificates"
          subtitle={
            certs.isFetching
              ? 'Reading the certificate store…'
              : `${rows.length} secured site${rows.length === 1 ? '' : 's'}`
          }
        />

        {certs.isLoading ? (
          <div className="grid place-items-center py-10">
            <Spinner className="size-5" />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            title="No certificates yet"
            hint="Securing a site issues one. Until then the site is served over plain HTTP."
            action={
              <Link to="/sites">
                <Button variant="primary" size="sm">
                  Go to sites
                </Button>
              </Link>
            }
          />
        ) : (
          <CertificateRows rows={rows} />
        )}
      </Card>

      <Card>
        <CardHeader
          title="Renew"
          subtitle="Only certificates that are expired or nearly expired are re-issued"
        />
        <div className="grid gap-2 p-3">
          <QuickAction slug="cert.renew" values={{ site: '' }} className="justify-start">
            Renew any certificate that needs it
          </QuickAction>
          <QuickAction
            slug="cert.renew"
            values={{ site: '', force: true }}
            variant="ghost"
            className="justify-start"
            title="Re-issue every certificate, even the ones that are still valid"
          >
            Force-renew everything
          </QuickAction>
        </div>
        <p className="border-t border-line px-3 py-2 text-[11px] text-muted dark:border-night-line dark:text-night-muted">
          Renewing is root-tier: it writes into the shared certificate store. The CA is only
          trusted once per machine.
        </p>
      </Card>
    </div>
  );
}
