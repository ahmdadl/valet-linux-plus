/** The toast viewport, bottom-right, above everything else. */

import { cx } from '../lib/format';
import { useToast, type ToastTone } from '../lib/toast';

const TONE_STYLES: Record<ToastTone, string> = {
  success: 'border-ok/40 bg-ok/10 text-ok dark:border-ok/40 dark:bg-ok/15',
  error: 'border-bad/40 bg-bad/10 text-bad dark:border-bad/40 dark:bg-bad/15',
  info: 'border-line bg-card text-ink dark:border-night-line dark:bg-night-card dark:text-night-ink',
};

export function Toaster() {
  const { toasts, dismiss } = useToast();

  return (
    <div
      aria-live="polite"
      aria-atomic="false"
      className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end"
    >
      {toasts.map((toast) => (
        <div
          key={toast.id}
          role="status"
          className={cx(
            'pointer-events-auto w-full max-w-sm rounded-xl border px-3.5 py-2.5 shadow-[var(--shadow-pop)]',
            TONE_STYLES[toast.tone],
          )}
        >
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-[13px] font-semibold">{toast.title}</p>
              {toast.description !== undefined && toast.description !== '' && (
                <p className="mt-0.5 text-[12px] opacity-80">{toast.description}</p>
              )}
            </div>
            <button
              type="button"
              onClick={() => dismiss(toast.id)}
              className="shrink-0 text-xs opacity-60 transition-opacity hover:opacity-100"
              aria-label="Dismiss"
            >
              ✕
            </button>
          </div>
        </div>
      ))}
    </div>
  );
}
