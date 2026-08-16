import { Button } from '@/components/ui/button';
import React from 'react';

interface ErrorBoundaryState {
    hasError: boolean;
}

/**
 * Last-resort guard so a render crash never blanks the desktop window.
 * Catches render/lifecycle errors only (event-handler errors don't unmount
 * the tree, so they need no boundary). Recovery is a full page load — the
 * React tree is broken at this point, so Inertia navigation is not usable.
 */
export class ErrorBoundary extends React.Component<React.PropsWithChildren, ErrorBoundaryState> {
    state: ErrorBoundaryState = { hasError: false };

    static getDerivedStateFromError(): ErrorBoundaryState {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: React.ErrorInfo) {
        console.error('Unhandled render error:', error, info.componentStack);
    }

    render() {
        if (!this.state.hasError) {
            return this.props.children;
        }

        return (
            <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
                <div className="flex w-full max-w-sm flex-col items-center gap-6 text-center">
                    <div className="space-y-2">
                        <h1 className="text-xl font-medium">Something went wrong</h1>
                        <p className="text-muted-foreground text-sm">The screen hit an unexpected error. Your data is safe — reload to continue.</p>
                    </div>
                    <Button onClick={() => window.location.assign('/')}>Reload</Button>
                </div>
            </div>
        );
    }
}
