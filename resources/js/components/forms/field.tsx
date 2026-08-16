import InputError from '@/components/forms/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

/**
 * One labelled control, with room for its error — the `<Label>` + control +
 * `<InputError>` trio written out roughly 68 times across 20 files.
 *
 * `htmlFor` is required rather than optional, and that is the point of the
 * component as much as the markup is. Wiring a label to its control is the step
 * that gets skipped, and skipping it means clicking the label does nothing and a
 * screen reader announces an unnamed box. Making it a required prop means the
 * type checker asks the question every time.
 *
 * The control stays a child rather than becoming a `type` prop: the fields here
 * are Inputs, Selects, Textareas, checkbox rows, file pickers and radio groups,
 * and a component that could render all six would take more configuring than
 * writing the one you want.
 */
export default function Field({
    label,
    htmlFor,
    error,
    hint,
    className,
    children,
}: {
    label: ReactNode;
    /** The `id` of the control inside. Required — see above. */
    htmlFor: string;
    error?: string;
    /**
     * A note under the control: units, a format, why a field is disabled.
     * Rendered above the error, and both can show at once — the hint explains
     * what the field wants, the error what was wrong with what it got.
     */
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && <p className="text-muted-foreground text-sm">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}
