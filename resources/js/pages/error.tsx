import AppLogoIcon from '@/components/layout/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';

// Rendered by the exception handler in bootstrap/app.php for non-debug
// 403/404/500/503 responses. Standalone layout on purpose: a 404 can fire
// before the web middleware runs, so auth/flash shared props (and therefore
// the app shell) may not be available.
const messages: Record<number, { title: string; description: string }> = {
    403: {
        title: 'Access denied',
        description: 'Your account does not have permission to view this page.',
    },
    404: {
        title: 'Page not found',
        description: 'The page you are looking for does not exist or has moved.',
    },
    500: {
        title: 'Something went wrong',
        description: 'An unexpected error occurred. Your data is safe — please try again.',
    },
    503: {
        title: 'Temporarily unavailable',
        description: 'The app is briefly unavailable. Please try again in a moment.',
    },
};

export default function ErrorPage({ status }: { status: number }) {
    const { title, description } = messages[status] ?? messages[500];

    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <Head title={title} />
            <div className="flex w-full max-w-sm flex-col items-center gap-6 text-center">
                <div className="flex h-9 w-9 items-center justify-center rounded-md">
                    <AppLogoIcon className="size-9 object-contain" />
                </div>
                <div className="space-y-2">
                    <p className="text-muted-foreground text-sm font-medium">{status}</p>
                    <h1 className="text-xl font-medium">{title}</h1>
                    <p className="text-muted-foreground text-sm">{description}</p>
                </div>
                <Button asChild>
                    <Link href={route('home')}>Back to POS</Link>
                </Button>
            </div>
        </div>
    );
}
