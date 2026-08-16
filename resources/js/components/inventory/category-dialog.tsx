import Field from '@/components/forms/field';
import { FormDialog } from '@/components/forms/form-dialog';
import { Input } from '@/components/ui/input';
import { closeOnSuccess, useDialogForm } from '@/hooks/use-dialog-form';
import type { Category } from '@/types';
import { FormEventHandler } from 'react';

/**
 * Create/edit dialog for a Category. Reused for both actions: pass a `category`
 * to edit, or omit it to create.
 *
 * The smallest dialog in the app, which makes it the one to read first: it is
 * `FormDialog` + `useDialogForm` + one `Field` and nothing else. Anything a
 * bigger dialog does beyond this shape, it does for a reason of its own.
 */
export default function CategoryDialog({
    open,
    onOpenChange,
    category,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    category?: Category | null;
}) {
    const isEdit = Boolean(category);
    const form = useDialogForm(open, () => ({ name: category?.name ?? '' }), [category?.id]);
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const options = closeOnSuccess(onOpenChange);

        if (isEdit && category) {
            form.patch(route('categories.update', category.id), options);
        } else {
            form.post(route('categories.store'), options);
        }
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={isEdit ? 'Edit category' : 'New category'}
            description="Categories group items in inventory and reports."
            submitLabel={isEdit ? 'Save' : 'Create'}
            processing={processing}
            onSubmit={submit}
        >
            <Field label="Name" htmlFor="category-name" error={errors.name}>
                <Input
                    id="category-name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    required
                    autoFocus
                    placeholder="e.g. Beverages"
                />
            </Field>
        </FormDialog>
    );
}
