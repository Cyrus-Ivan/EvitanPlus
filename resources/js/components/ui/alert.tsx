import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

// shadcn's `alert`, plus a `warning` variant it does not ship. The amber is
// hardcoded rather than tokenised because it is the ONE tone in the app that
// carries meaning no palette slot holds: `destructive` says "this failed", and
// every banner here says "this still works, but less of it than usual" — an
// offline till that can still sell, a consolidated read that fell back to one
// branch. Both light and dark are covered by the /50 and /10 alphas over
// whatever `bg-background` currently is.
const alertVariants = cva('flex items-start gap-2 rounded-md border p-3 text-sm [&>svg]:mt-0.5 [&>svg]:size-4 [&>svg]:shrink-0', {
    variants: {
        variant: {
            default: 'bg-background text-foreground',
            warning: 'border-amber-500/50 bg-amber-500/10',
            destructive: 'border-destructive/50 text-destructive dark:border-destructive',
        },
    },
    defaultVariants: {
        variant: 'default',
    },
});

const Alert = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement> & VariantProps<typeof alertVariants>>(
    ({ className, variant, ...props }, ref) => (
        // `role="alert"` is on every hand-rolled banner this replaces, and stays:
        // these appear mid-page after a state change (a connection dropped, a
        // consolidated read failed), which is exactly the case a screen reader
        // has to be told about without being asked.
        <div ref={ref} role="alert" className={cn(alertVariants({ variant }), className)} {...props} />
    ),
);
Alert.displayName = 'Alert';

const AlertTitle = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLHeadingElement>>(({ className, ...props }, ref) => (
    <span ref={ref} className={cn('font-medium', className)} {...props} />
));
AlertTitle.displayName = 'AlertTitle';

const AlertDescription = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLParagraphElement>>(({ className, ...props }, ref) => (
    <p ref={ref} className={cn('text-sm', className)} {...props} />
));
AlertDescription.displayName = 'AlertDescription';

export { Alert, AlertDescription, AlertTitle, alertVariants };
