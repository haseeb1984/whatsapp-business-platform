import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';

const appName = import.meta.env.VITE_APP_NAME || 'WhatsApp Business Platform';
const pages = import.meta.glob('./Pages/**/*.tsx');

createInertiaApp({
    title: (title) => title ? `${title} · ${appName}` : appName,
    resolve: (name) => {
        // Load each Inertia page on demand so business and platform screens
        // are split into route-level chunks instead of one large entry bundle.
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) throw new Error(`Page not found: ${name}`);
        return page();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#2563eb' },
});
