/**
 * Toasts.
 *
 * Actions answer in one of three ways: an immediate envelope, a `job` id, or a
 * refusal. All three are surfaced here so the user never has to guess whether
 * something happened, and a refusal is never silent.
 */

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

export type ToastTone = 'success' | 'error' | 'info';

export interface Toast {
  id: number;
  tone: ToastTone;
  title: string;
  description?: string;
}

interface ToastApi {
  toasts: Toast[];
  push: (toast: Omit<Toast, 'id'>) => void;
  dismiss: (id: number) => void;
}

const ToastContext = createContext<ToastApi | null>(null);

/** How long a toast stays on screen, in milliseconds. */
const TOAST_TTL = 6000;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const nextId = useRef(1);

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
  }, []);

  const push = useCallback(
    (toast: Omit<Toast, 'id'>) => {
      const id = nextId.current++;

      setToasts((current) => [...current.slice(-3), { ...toast, id }]);

      window.setTimeout(() => dismiss(id), TOAST_TTL);
    },
    [dismiss],
  );

  const value = useMemo(() => ({ toasts, push, dismiss }), [toasts, push, dismiss]);

  return <ToastContext.Provider value={value}>{children}</ToastContext.Provider>;
}

export function useToast(): ToastApi {
  const context = useContext(ToastContext);

  if (context === null) {
    throw new Error('useToast must be used inside <ToastProvider>.');
  }

  return context;
}
