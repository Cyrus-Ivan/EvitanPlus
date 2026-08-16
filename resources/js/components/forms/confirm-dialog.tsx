import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react';

export type ConfirmOptions = {
    title: string;
    description?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Style the confirm button as a destructive action (delete/archive). */
    destructive?: boolean;
};

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<ConfirmFn | null>(null);

/**
 * Imperative confirmation dialog. Wrap the app once in <ConfirmProvider>, then
 * from any component:
 *
 *   const confirm = useConfirm();
 *   if (await confirm({ title: 'Archive item?', destructive: true })) { ... }
 *
 * Used everywhere a destructive or irreversible action needs a guard (archive,
 * unarchive, reset password, record a defect, ...).
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState<ConfirmOptions | null>(null);
    const resolverRef = useRef<((value: boolean) => void) | null>(null);

    const confirm = useCallback<ConfirmFn>((opts) => {
        setOptions(opts);
        setOpen(true);
        return new Promise<boolean>((resolve) => {
            resolverRef.current = resolve;
        });
    }, []);

    const settle = useCallback((value: boolean) => {
        setOpen(false);
        resolverRef.current?.(value);
        resolverRef.current = null;
    }, []);

    const handleOpenChange = useCallback(
        (next: boolean) => {
            if (!next) {
                settle(false);
            }
        },
        [settle],
    );

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{options?.title}</DialogTitle>
                        {/* Always rendered so the dialog keeps an accessible description. */}
                        <DialogDescription className={options?.description ? undefined : 'sr-only'}>
                            {options?.description ?? 'Review the action above, then confirm or cancel.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => settle(false)}>
                            {options?.cancelLabel ?? 'Cancel'}
                        </Button>
                        <Button variant={options?.destructive ? 'destructive' : 'default'} onClick={() => settle(true)}>
                            {options?.confirmLabel ?? 'Confirm'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ConfirmContext.Provider>
    );
}

export function useConfirm(): ConfirmFn {
    const ctx = useContext(ConfirmContext);

    if (!ctx) {
        throw new Error('useConfirm must be used within a <ConfirmProvider>');
    }

    return ctx;
}
