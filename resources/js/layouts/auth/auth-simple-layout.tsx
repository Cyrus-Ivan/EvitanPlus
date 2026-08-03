import AppLogoIcon from '@/components/app-logo-icon';
import { BranchIdentity } from '@/components/branch-identity';
import { SyncIndicator } from '@/components/sync-indicator';
import { Link } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

/**
 * The pre-login shell — and from M11.5 a syncing one (see routes/web.php).
 *
 * {@link SyncIndicator} mounts the sync triggers, so a device sitting on this
 * screen with a connection runs a cycle on its own: the pull brings down users
 * created at other branches, which is what lets them log in HERE without
 * anybody logging in first. It also drains whatever backlog the last session
 * left. The button is the manual control for talking a store through it.
 *
 * The triggers' one-mount rule is not broken by this: this layout and the app
 * header are mutually exclusive, so exactly one indicator is ever mounted.
 *
 * {@link BranchIdentity} is here for a plainer reason — a device you cannot log
 * into is exactly when you need to know which branch it is.
 */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="bg-background relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="absolute top-4 right-4 flex items-center gap-3">
                <BranchIdentity />
                <SyncIndicator />
            </div>

            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link href={route('home')} className="flex flex-col items-center gap-2 font-medium">
                            <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-9 object-contain" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-muted-foreground text-center text-sm">{description}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
