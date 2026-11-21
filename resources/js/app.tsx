import '../css/app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';

const appName = import.meta.env.VITE_APP_NAME || 'WhatsApp Business Platform';

createInertiaApp({
    title: (title) => title ? `${title} · ${appName}` : appName,
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        const page = pages[`./Pages/${name}.tsx`] as { default: React.ComponentType };
        if (!page) throw new Error(`Page not found: ${name}`);
        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#2563eb' },
});
