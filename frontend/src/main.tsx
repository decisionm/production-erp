import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider } from 'antd';
import App from './app/App';
import { queryClient } from '@/lib/queryClient';
import './index.css';

const container = document.getElementById('root');

if (!container) {
    throw new Error('Root element #root not found.');
}

createRoot(container).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <ConfigProvider>
                <App />
            </ConfigProvider>
        </QueryClientProvider>
    </StrictMode>,
);
