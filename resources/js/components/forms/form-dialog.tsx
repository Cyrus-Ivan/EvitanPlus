import SubmitButton from '@/components/forms/submit-button';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { FormEventHandler, ReactNode } from 'react';

/**
 * The shape every modal form in this app takes: a title, a sentence saying what
 * the form is for, the fields, then Cancel and a submit.
 *
 * Twelve dialogs had written that out by hand and agreed on it every time,
 * which is the case for a component rather than a convention. What it fixes is
 * not the typing but the drift: one dialog's Cancel was `variant="ghost"`, one
 * put its footer inside the field group so the buttons scrolled with the form,
 * and the spinner came in two sizes (see {@see SubmitButton}).
 *
 * The `<form>` wraps the header too, not just the fields. That is deliberate and
 * load-bearing: a submit button inside a `<form>` responds to Enter from any
 * field in it, and the day somebody adds a field above the header, the keyboard
 * should keep working.
 *
 * Not a replacement for {@see useConfirm} — a confirmation has no fields and no
 * form, and its imperative promise API is a better fit for a guard than a piece
 * of state on the page. This is for dialogs that COLLECT something.
 */
export function FormDialog({
    open,
    onOpenChange,
    title,
    description,
    submitLabel = 'Save',
    cancelLabel = 'Cancel',
    processing,
    submitDisabled,
    onSubmit,
    className,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /**
     * What the form is for, in a sentence. Rendered `sr-only` when omitted so the
     * dialog keeps an accessible description either way — Radix warns without
     * one, and the warning is the only thing that ever catches a missing one.
     */
    description?: ReactNode;
    /**
     * `null` drops the submit button entirely, leaving only the cancel. For a
     * dialog that opens to explain why there is nothing to do — a transfer with
     * no other branch to send to — where a disabled button would invite the
     * reader to keep looking for the thing that would enable it.
     */
    submitLabel?: ReactNode | null;
    cancelLabel?: string;
    processing?: boolean;
    /**
     * Blocks submit for a reason that is not "in flight" — nothing selected, no
     * file chosen. Separate from `processing` because the two say different
     * things to the person waiting, and only one of them is temporary.
     */
    submitDisabled?: boolean;
    onSubmit: FormEventHandler;
    /** For the few dialogs that need to be wider than the default. */
    className?: string;
    children: ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={className}>
                <form onSubmit={onSubmit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription className={description ? undefined : 'sr-only'}>
                            {description ?? `Fill in the form, then ${typeof submitLabel === 'string' ? submitLabel.toLowerCase() : 'submit'}.`}
                        </DialogDescription>
                    </DialogHeader>

                    {children}

                    <DialogFooter>
                        {/* type="button" matters: a button in a form submits it by
                            default, so an untyped Cancel would save the thing it
                            was meant to abandon. */}
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            {cancelLabel}
                        </Button>
                        {submitLabel !== null && (
                            <SubmitButton processing={processing} disabled={submitDisabled}>
                                {submitLabel}
                            </SubmitButton>
                        )}
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The same dialog with nothing to submit: a record opened to be read.
 *
 * A sibling rather than a `footer={false}` flag on {@see FormDialog}, because
 * the difference is not one of decoration — there is no form, no processing
 * state and no Cancel, since there is nothing to cancel. Closing is the X and
 * Escape, both of which `DialogContent` already provides.
 *
 * Used by the drill-downs: one ledger entry, one employee's day, one day of
 * login events, and the send dialogs when there is no sender configured.
 *
 * Focus lands on the PANEL rather than on the first thing inside it. That is not
 * a preference — it follows from what this component is. Radix focuses the first
 * focusable child on open, which on a record with no controls draws a focus ring
 * around an arbitrary piece of text. Focus stays inside the dialog, so the trap,
 * Escape and tabbing are unaffected. Three dialogs had discovered this and
 * written the same handler.
 *
 * `title` and `description` are optional because the drill-downs render their
 * header from a record that is null while the dialog animates out — they guard
 * on it inside `children` and supply their own `DialogHeader`. Pass them when
 * the header does not depend on a record that can be missing.
 */
export function DetailDialog({
    open,
    onOpenChange,
    title,
    description,
    className,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title?: ReactNode;
    description?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn('focus:outline-hidden', className)}
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    (event.currentTarget as HTMLElement | null)?.focus();
                }}
            >
                {title !== undefined && (
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription className={description ? undefined : 'sr-only'}>
                            {description ?? 'Details of the selected record.'}
                        </DialogDescription>
                    </DialogHeader>
                )}

                {children}
            </DialogContent>
        </Dialog>
    );
}
