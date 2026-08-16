import { TableCell } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { ComponentProps, ReactNode } from 'react';

/**
 * A table in its card, or the empty state instead of it.
 *
 * Ten pages wrapped their table in `rounded-xl border` and nine of them put the
 * same ternary in front of it — `rows.length === 0 ? <EmptyState/> : <Table/>`.
 * The two go together: a bordered card wrapped around a table with no rows is a
 * thin empty box, which is why every page that has one also has the ternary.
 *
 * Deliberately NOT a `<DataTable columns={...}>`. The tables here have
 * expandable variant rows, a `colSpan` computed from which columns the reader's
 * role may see, a Branch column that appears only under a wider scope, and
 * currency columns that align differently from count columns. A configuration
 * API able to express all of that is harder to read than the JSX it replaces,
 * and the JSX is greppable. This component takes the wrapper and the ternary,
 * which is the part that was actually repeated, and leaves the columns alone.
 */
export default function TableCard({
    isEmpty = false,
    empty,
    className,
    children,
}: {
    /**
     * Omitted for a table that is only rendered when it has rows already — the
     * two breakdown tables on `/reports` sit inside a branch the page has
     * already guarded. Those get the card and skip the ternary.
     */
    isEmpty?: boolean;
    empty?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    if (isEmpty) {
        return <>{empty}</>;
    }

    return <div className={cn('rounded-xl border', className)}>{children}</div>;
}

/**
 * A cell holding a figure: right-aligned, and in tabular figures so a column of
 * them lines up on the decimal point.
 *
 * `tabular-nums` was on roughly 50 cells and missing from a handful of others in
 * the same columns, where the proportional digits made a column of prices look
 * ragged. Quantities, money, counts and hours all want it — anything a reader
 * scans down rather than reads across.
 */
export function NumericCell({ className, children, ...props }: ComponentProps<typeof TableCell>) {
    return (
        <TableCell className={cn('text-right tabular-nums', className)} {...props}>
            {children}
        </TableCell>
    );
}
