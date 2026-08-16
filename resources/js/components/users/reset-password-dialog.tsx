import Field from '@/components/forms/field';
import { FormDialog } from '@/components/forms/form-dialog';
import { Input } from '@/components/ui/input';
import { closeOnSuccess, useDialogForm } from '@/hooks/use-dialog-form';
import type { ManagedUser } from '@/types';
import { FormEventHandler } from 'react';

/**
 * Administratively reset an employee's password. The target is signed out of any
 * active session on success, so they must sign back in with the new password.
 */
export default function ResetPasswordDialog({
    open,
    onOpenChange,
    user,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: ManagedUser | null;
}) {
    // Always blank, never prefilled from the user being reset — there is nothing
    // to prefill it with, and `useDialogForm` re-running this on every open is
    // what stops one reset's typing from being visible in the next one.
    const form = useDialogForm(open, () => ({ password: '', password_confirmation: '' }), [user?.id]);
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!user) {
            return;
        }
        form.patch(route('users.reset-password', user.id), closeOnSuccess(onOpenChange));
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Reset password${user ? ` — ${user.name ?? user.username}` : ''}`}
            description="The user will be signed out and must log in again with the new password."
            submitLabel="Reset password"
            processing={processing}
            onSubmit={submit}
        >
            <Field label="New password" htmlFor="reset-password" error={errors.password}>
                <Input
                    id="reset-password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    required
                    autoFocus
                    autoComplete="new-password"
                />
            </Field>

            <Field label="Confirm new password" htmlFor="reset-password-confirm" error={errors.password_confirmation}>
                <Input
                    id="reset-password-confirm"
                    type="password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    required
                    autoComplete="new-password"
                />
            </Field>
        </FormDialog>
    );
}
