import Field from '@/components/forms/field';
import { FormDialog } from '@/components/forms/form-dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { closeOnSuccess, useDialogForm } from '@/hooks/use-dialog-form';
import { ROLE_LABEL } from '@/lib/roles';
import type { ManagedUser, UserRole } from '@/types';
import { FormEventHandler } from 'react';

/**
 * Change an existing employee's role. Only the role is editable — the name is
 * immutable. The select offers only roles the actor may assign.
 */
export default function RoleDialog({
    open,
    onOpenChange,
    user,
    assignableRoles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: ManagedUser | null;
    assignableRoles: UserRole[];
}) {
    const form = useDialogForm(open, () => ({ role: user?.role ?? '' }), [user?.id]);
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!user) {
            return;
        }
        form.patch(route('users.role', user.id), closeOnSuccess(onOpenChange));
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Change role${user ? ` — ${user.name ?? user.username}` : ''}`}
            description="Changes what this user can see and do."
            processing={processing}
            onSubmit={submit}
        >
            <Field label="Role" htmlFor="role-select" error={errors.role}>
                <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                    <SelectTrigger id="role-select" autoFocus>
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
        </FormDialog>
    );
}
