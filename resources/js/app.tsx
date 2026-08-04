import '../css/app.css';

import { ConfirmProvider } from '@/components/confirm-dialog';
import { ErrorBoundary } from '@/components/error-boundary';
import { Toaster } from '@/components/ui/sonner';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <ConfirmProvider>
                    <App {...props} />
                    <Toaster />
                </ConfirmProvider>
            </ErrorBoundary>,
        );
    },
    // Inertia's own progress component, in its usual place along the top edge,
    // but styled by us — see the #nprogress block at the end of
    // resources/css/app.css. Declining the injected stylesheet leaves the
    // engine, the trickle and the timing Inertia's and the paint ours, which is
    // what lets the bar be thicker and legible in dark mode; `color` is
    // deliberately absent, since it exists only to build the stylesheet this
    // declines.
    //
    // 150ms rather than the default 250: a lagging screen has to speak up
    // before it reads as frozen, which is the whole complaint. Still long
    // enough that an ordinary SQLite-local navigation finishes without a flash.
    progress: {
        includeCSS: false,
        delay: 150,
    },
});

// This will set light / dark mode on load...
initializeTheme();
