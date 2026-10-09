/**
 * Entry point.
 *
 * The providers are ordered by dependency: the toast context sits outside the
 * action provider because the action dialog reports through it, and the query
 * client is shared by everything that reads state.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { App } from './App';
import { ActionProvider } from './lib/actionContext';
import { ToastProvider } from './lib/toast';
import './index.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // The dashboard polls on its own schedule; a failed read should stay
      // quiet rather than hammer the server.
      retry: 1,
      retryDelay: 2000,
      refetchOnWindowFocus: true,
    },
  },
});

const container = document.getElementById('root');

if (container === null) {
  throw new Error('The dashboard root element is missing.');
}

createRoot(container).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <ActionProvider>
          <App />
        </ActionProvider>
      </ToastProvider>
    </QueryClientProvider>
  </StrictMode>,
);
