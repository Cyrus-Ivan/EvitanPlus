import type { FormDataConvertible, VisitOptions } from '@inertiajs/core';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * `useForm` for a form that lives in a dialog, which is to say a form that gets
 * opened more than once.
 *
 * A page form is mounted with the values it will submit. A dialog form is
 * mounted once and reopened against a different record — or against no record,
 * for the create case — so it has to be refilled every time it opens, or the
 * second edit shows the first one's values and the errors from the attempt
 * before. Eleven dialogs had discovered this separately and written the same
 * effect, each with its own copy of the eslint disable. It lives here now.
 *
 * `initial` is a FUNCTION, not an object: it is re-run on every open, so it can
 * read the record the dialog was reopened against. Passing values directly would
 * capture whatever the record was on the render that mounted the dialog.
 *
 * `deps` is what makes "reopened against a different record" detectable —
 * normally `[entity?.id]`. It is a parameter rather than something derived
 * because only the caller knows which of the props it closes over identify the
 * record and which are incidental.
 */
export function useDialogForm<TForm extends Record<string, FormDataConvertible>>(open: boolean, initial: () => TForm, deps: unknown[] = []) {
    const form = useForm<TForm>(initial());
    const { setData, setDefaults, clearErrors } = form;

    useEffect(() => {
        if (!open) return;

        const values = initial();

        // Defaults as well as data, so `reset()` inside the dialog returns to the
        // record being edited rather than to whatever the dialog was first
        // mounted with — and so `isDirty` means "changed since it opened".
        setDefaults(values);
        setData(values);
        clearErrors();
        // `initial` is a fresh closure every render and would re-run this on each
        // one; the caller's `deps` are what actually identify the record.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, ...deps]);

    return form;
}

/**
 * The visit options a dialog submit wants: close on success, don't jump the page
 * underneath.
 *
 * `preserveScroll` is the one that matters and the one that is easy to forget. A
 * dialog is opened from a row partway down a long list; without it the redirect
 * that follows a successful save lands the reader back at the top, looking for
 * where they were.
 *
 * A failed submit deliberately leaves the dialog OPEN — that is Inertia's
 * default and worth stating, because the errors it just returned are rendered
 * inside it.
 */
export function closeOnSuccess(onOpenChange: (open: boolean) => void, options: Omit<VisitOptions, 'data'> = {}): Omit<VisitOptions, 'data'> {
    return {
        preserveScroll: true,
        ...options,
        onSuccess: (page) => {
            options.onSuccess?.(page);
            onOpenChange(false);
        },
    };
}
