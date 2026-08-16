import Field from '@/components/forms/field';
import { FormDialog } from '@/components/forms/form-dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { closeOnSuccess, useDialogForm } from '@/hooks/use-dialog-form';
import { ROLE_LABEL } from '@/lib/roles';
import type { UserRole } from '@/types';
import { FormEventHandler } from 'react';

/**
 * Create a new employee. Name is immutable after creation, so it is only set
 * here. The role select offers only the roles the current actor may assign
 * (resolved server-side), and the password is confirmed inline.
 */
export default function UserDialog({
    open,
    onOpenChange,
    assignableRoles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignableRoles: UserRole[];
}) {
    // `role` is a plain string, not a `UserRole`: the Select hands back whatever
    // the reader picked and the server is the authority on whether they may
    // assign it. Narrowing it here would only move that check somewhere it
    // cannot be enforced.
    const form = useDialogForm<{ name: string; username: string; role: string; password: string; password_confirmation: string }>(open, () => ({
        name: '',
        username: '',
        // The lowest role the actor may assign, which is the safe default: an
        // account created by a slip of the Enter key should be a Cashier.
        role: assignableRoles[0] ?? '',
        password: '',
        password_confirmation: '',
    }));
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('users.store'), closeOnSuccess(onOpenChange));
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title="New user"
            description="Create an employee account. The name cannot be changed later."
            submitLabel="Create"
            processing={processing}
            onSubmit={submit}
        >
            <Field label="Name" htmlFor="user-name" error={errors.name}>
                <Input
                    id="user-name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    required
                    autoFocus
                    placeholder="e.g. Jane Dela Cruz"
                />
            </Field>

            <Field label="Username" htmlFor="user-username" error={errors.username}>
                <Input
                    id="user-username"
                    value={data.username}
                    onChange={(e) => setData('username', e.target.value)}
                    required
                    autoComplete="off"
                    placeholder="Used to sign in"
                />
            </Field>

            <Field label="Role" htmlFor="user-role" error={errors.role}>
                <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                    <SelectTrigger id="user-role">
                        <SelectValue placeholder="Select a role" />
                    </SelectTrigger>
                    <SelectContent>
                        {assignableRoles.map((role) => (
                            <SelectItem key={role} value={role}>
                                {ROLE_LABEL[role]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            <Field label="Password" htmlFor="user-password" error={errors.password}>
                <Input
                    id="user-password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    required
                    autoComplete="new-password"
                />
            </Field>

            <Field label="Confirm password" htmlFor="user-password-confirm" error={errors.password_confirmation}>
                <Input
                    id="user-password-confirm"
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
