import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider } from 'antd';
import '@fontsource-variable/archivo/wdth.css';
import { registerSW } from 'virtual:pwa-register';
import App from './app/App';
import { queryClient } from '@/lib/queryClient';
import { appTheme } from '@/theme/tokens';
import './index.css';

// How often an open tab asks the server whether a newer service worker exists.
// A shift tab is opened at the start of the shift and left open for hours, so
// without polling an update deployed mid-shift is only picked up at the next
// cold start — which is exactly the "I still don't see those changes" report.
const UPDATE_CHECK_INTERVAL_MS = 60_000;

const container = document.getElementById('root');

if (!container) {
    throw new Error('Root element #root not found.');
}

createRoot(container).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <ConfigProvider theme={appTheme} tag={{ variant: 'solid' }}>
                <App />
            </ConfigProvider>
        </QueryClientProvider>
    </StrictMode>,
);

// Registering here — rather than letting the plugin inject its own one-line
// script — is what makes `registerType: 'autoUpdate'` actually update the app.
// The injected script only calls navigator.serviceWorker.register(): a new
// worker installs and (thanks to skipWaiting/clientsClaim) takes control, but
// the tab keeps running the JS bundle it already loaded, so the screen still
// shows the old build. This module's autoUpdate branch adds the missing half —
// it reloads the page once the new worker activates. In dev the virtual module
// resolves to a no-op stub, so `npm run dev` is unaffected.
//
// Pass nothing but `immediate` and `onRegisteredSW`: supplying an `onNeedReload`
// callback would replace the built-in `window.location.reload()` and put us back
// where we started.
registerSW({
    // Register as soon as this runs instead of waiting for window `load`, so a
    // pending update starts installing at the earliest possible moment.
    immediate: true,
    onRegisteredSW(_swUrl, registration) {
        if (!registration) {
            return;
        }

        setInterval(() => {
            // An install already in flight will finish on its own; asking again
            // mid-install just churns.
            if (registration.installing) {
                return;
            }

            // Deliberately not gated on tab visibility: a backgrounded factory
            // tab is precisely the one that goes stale.
            void registration.update().catch(() => {
                // Offline, or the server blipped. The next tick retries; there
                // is nothing here worth showing an operator.
            });
        }, UPDATE_CHECK_INTERVAL_MS);
    },
});
