import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/**
 * Where the PHP server serves the built dashboard from.
 *
 * The build output is committed to the repository so an end user who installs
 * the composer package never needs Node: `cli/templates/dashboard-dist` is a
 * plain static bundle that DashboardServer reads straight off disk.
 */
const outDir = new URL('../cli/templates/dashboard-dist', import.meta.url).pathname;

/**
 * The dashboard origin used by `npm run dev`.
 *
 * The dev server proxies /api to the real dashboard host so the CSRF cookie,
 * the loopback check and the same-origin Origin header all behave exactly as
 * they do in production. See the proxy `configure` hook below for how the
 * Origin check is kept intact.
 */
const devApiOrigin = process.env.VITE_DEV_API_ORIGIN || 'https://valet.test';

/**
 * The port `npm run dev` tries first.
 *
 * 5173 is a popular default, so it is often taken on a machine that runs other
 * Vite projects. Setting strictPort to false lets Vite walk up to the next free
 * port and print the one it picked instead of refusing to start.
 */
const devPort = Number(process.env.VITE_DEV_PORT) || 5173;

export default defineConfig({
  // Absolute paths: the SPA is served at the host root but under client-side
  // routes such as /sites/foo, where relative asset URLs would resolve wrong.
  base: '/',

  plugins: [react(), tailwindcss()],

  build: {
    outDir,
    emptyOutDir: true,
    // The PHP side only ever asks for /assets/*, so keep the default layout.
    assetsDir: 'assets',
    sourcemap: false,
    rollupOptions: {
      output: {
        // A single, stable entry keeps the committed bundle small and greppable.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          query: ['@tanstack/react-query'],
        },
      },
    },
  },

  server: {
    host: '127.0.0.1',
    port: devPort,
    // A busy port is a routine dev-box condition, not an error. Vite reports
    // the port it actually bound to, so `npm run dev` just works.
    strictPort: false,
    proxy: {
      '/api': {
        target: devApiOrigin,
        changeOrigin: true,
        secure: false,
        // The API sets its CSRF cookie without a Domain attribute, so the
        // browser scopes it to 127.0.0.1 and the SPA can read it back for the
        // double-submit header.
        cookieDomainRewrite: '127.0.0.1',
        configure(proxy) {
          // Rewriting the Host (changeOrigin above) is what lets server.php
          // recognise the request as valet.<domain> at all. But the browser
          // then sends Origin: http://127.0.0.1:<dev port>, and
          // DashboardRequest::hasValidOrigin() compares that against the Host
          // PHP saw — valet.<domain> — so every user- and root-tier mutation
          // would come back "Missing or invalid CSRF token".
          //
          // Re-point both browser-supplied headers at the API origin so a
          // mutation issued from the dev server passes exactly the checks it
          // will face in production. This only affects this dev server, which
          // binds to 127.0.0.1.
          const apiOrigin = new URL(devApiOrigin).origin;

          proxy.on('proxyReq', (proxyReq) => {
            proxyReq.setHeader('origin', apiOrigin);
            proxyReq.setHeader('referer', apiOrigin + '/');
          });
        },
      },
    },
  },

  preview: {
    host: '127.0.0.1',
    port: 4173,
  },

  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['tests/setup.ts'],
    include: ['tests/**/*.test.{ts,tsx}'],
  },
});
