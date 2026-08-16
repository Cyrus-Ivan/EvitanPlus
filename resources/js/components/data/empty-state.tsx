import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

type EmptyStateProps = {
    icon?: LucideIcon;
    title: string;
    description?: string;
    /** Optional call-to-action, e.g. a button. */
    action?: ReactNode;
    className?: string;
};

/**
 * Neutral placeholder shown when a page or list has nothing to display.
 * Used across the app for "no results" and (during early phases) for
 * not-yet-built sections.
 */
export default function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div
            className={cn(
                'border-sidebar-border/70 dark:border-sidebar-border flex h-full flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-12 text-center',
                className,
            )}
        >
            {Icon && (
                <div className="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-full">
                    <Icon className="size-6" />
                </div>
            )}
            <div className="space-y-1">
                <h3 className="text-lg font-semibold tracking-tight">{title}</h3>
                {description && <p className="text-muted-foreground mx-auto max-w-md text-sm">{description}</p>}
            </div>
            {action}
        </div>
    );
}
