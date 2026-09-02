import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider } from 'antd';
import { registerSW } from 'virtual:pwa-register';
import App from './app/App';
import { queryClient } from '@/lib/queryClient';
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
            <ConfigProvider
                theme={{
                    token: {
                        fontFamily: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                        colorPrimary: '#1d4ed8',
                        colorLink: '#1d4ed8',
                        colorLinkHover: '#2563eb',
                        borderRadius: 8,
                        borderRadiusLG: 12,
                        borderRadiusSM: 6,
                        colorBgLayout: '#f8fafc',
                        colorBgContainer: '#ffffff',
                        colorTextHeading: '#0f172a',
                        colorText: '#334155',
                        colorTextSecondary: '#64748b',
                        colorBorder: '#e2e8f0',
                        colorBorderSecondary: '#f1f5f9',
                        boxShadow: '0 1px 3px 0 rgba(15, 23, 42, 0.05), 0 1px 2px -1px rgba(15, 23, 42, 0.05)',
                        boxShadowSecondary: '0 4px 6px -1px rgba(15, 23, 42, 0.06), 0 2px 4px -2px rgba(15, 23, 42, 0.05)',
                    },
                    components: {
                        Button: {
                            borderRadius: 8,
                            fontWeight: 500,
                            controlHeight: 36,
                        },
                        Card: {
                            borderRadiusLG: 12,
                            boxShadowTertiary: '0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px 0 rgba(15, 23, 42, 0.02)',
                        },
                        Table: {
                            borderRadius: 12,
                            headerBg: '#f8fafc',
                            headerColor: '#1e293b',
                            headerSplitColor: '#e2e8f0',
                            rowHoverBg: '#f1f5f9',
                        },
                        Input: {
                            borderRadius: 8,
                            controlHeight: 36,
                        },
                        Select: {
                            borderRadius: 8,
                            controlHeight: 36,
                        },
                        Menu: {
                            itemBorderRadius: 8,
                            subMenuItemBorderRadius: 6,
                        },
                        Tabs: {
                            titleFontSize: 14,
                            lineType: 'solid',
                        },
                        Tag: {
                            borderRadiusSM: 6,
                        },
                    },
                }}
            >
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
