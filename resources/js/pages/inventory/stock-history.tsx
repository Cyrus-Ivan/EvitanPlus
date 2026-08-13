import { BranchScopeSelect } from '@/components/branch-scope-select';
import EmptyState from '@/components/empty-state';
import { StockEntryDialog } from '@/components/inventory/stock-entry-dialog';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { MOVEMENT_LABEL, movementLabel, movementVariant } from '@/lib/stock-movement';
import type { BranchScope, BreadcrumbItem, Paginated, RosterBranch, StockLedgerEntry } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ScrollText, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock History', href: '/inventory/stock-history' },
];

const ALL = 'all';

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

interface VariantOption {
    id: string;
    name: string | null;
    sku: string | null;
    item: string | null;
}

// A "no variant" product has a null name; show the parent item name instead.
function variantLabel(variant: { name: string | null; item: string | null }): string {
    if (variant.name && variant.item) {
        return `${variant.item} — ${variant.name}`;
    }
    return variant.name ?? variant.item ?? '—';
}

interface Filters {
    variant: string | null;
    movement: string | null;
    // A stock batch by name (`MNL-B-000001`), or any part of one — the search box
    // matches it as a substring, so the serial off a delivery note is enough. Two
    // ways in: typed, or filled in by following the batch link in the entry
    // dialog.
    batch: string | null;
    date_from: string | null;
    date_to: string | null;
}

/**
 * Stock History — the append-only ledger, as read.
 *
 * ## The branch scope (M11.8)
 *
 * `local` is this device's SQLite and works offline; `all` or a branch id is the
 * business database, read live, Admin+ only. It exists because a branch that
 * asks ANOTHER branch to move stock has never been able to see what came of it:
 * the pull filters the ledger to one branch. Now that a refusal writes a
 * zero-quantity ledger row rather than nothing at all, there is something
 * upstream to go and read, and this is the reading of it.
 *
 * A wider scope that could not be reached falls back to this branch and SAYS SO
 * — presenting one branch's movements under an "All branches" heading is the one
 * outcome worse than an error.
 */
export default function StockHistory({
    entries,
    variants,
    movements,
    filters,
    scope,
    canViewAll,
    branches,
    consolidatedOnline,
}: {
    entries: Paginated<StockLedgerEntry>;
    variants: VariantOption[];
    movements: string[];
    filters: Filters;
    scope: BranchScope;
    canViewAll: boolean;
    branches: RosterBranch[];
    consolidatedOnline: boolean | null;
}) {
    const [detail, setDetail] = useState<StockLedgerEntry | null>(null);
    const [variantSearch, setVariantSearch] = useState('');
    const [batchSearch, setBatchSearch] = useState(filters.batch ?? '');
    const applied = useRef(filters.batch);

    // The whole catalogue is already in the browser, so the search is a filter
    // over it. The selected variant stays mounted whatever the search says: Radix
    // renders the trigger's text from the selected SelectItem, and hiding it
    // blanks the trigger.
    const needle = variantSearch.trim().toLowerCase();
    const shownVariants = needle
        ? variants.filter(
              (variant) =>
                  variant.id === filters.variant || `${variant.item ?? ''} ${variant.name ?? ''} ${variant.sku ?? ''}`.toLowerCase().includes(needle),
          )
        : variants;

    const applyFilters = (next: Partial<Filters> & { scope?: string }) => {
        const merged = { ...filters, ...next };
        router.get(
            route('stock-history.index'),
            {
                variant: merged.variant && merged.variant !== ALL ? merged.variant : undefined,
                movement: merged.movement && merged.movement !== ALL ? merged.movement : undefined,
                batch: merged.batch || undefined,
                date_from: merged.date_from || undefined,
                date_to: merged.date_to || undefined,
                scope: (next.scope ?? scope) === 'local' ? undefined : (next.scope ?? scope),
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                // `branches` is deliberately out of this list: the roster costs an
                // online read, and stepping a date filter must not pay for it.
                only: ['entries', 'filters', 'scope', 'consolidatedOnline'],
            },
        );
    };

    // Debounce the batch box so we don't fire a request per keystroke. What the
    // effect compares against is the term the LIST was last asked for, held in a
    // ref rather than read off `filters.batch`, because the prop only catches up
    // when the response lands: the entry dialog's link sets the box and filters
    // in one go, and a guard waiting on the prop would fire a second, identical
    // request 300ms later whenever that read took longer than the debounce —
    // which is exactly when it is a cross-branch one. `|| null` on both sides so
    // an emptied box differs from a live batch, which '' vs '' would not.
    useEffect(() => {
        const term = batchSearch.trim() || null;
        if (term === applied.current) {
            return;
        }
        const timeout = setTimeout(() => {
            applied.current = term;
            applyFilters({ batch: term });
        }, 300);
        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [batchSearch]);

    // A wider scope names the branch on every row; in the local scope every row
    // is this device's by construction and the column would say one thing.
    const showBranch = scope !== 'local';

    // Nothing on this page reads the pending queue any more: that list is its own
    // screen (`/inventory/stock-updates`), which is where a movement that has not
    // happened yet is answered. This one is the ledger — what did.

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock History" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.variant ?? ALL}
                        onValueChange={(value) => applyFilters({ variant: value })}
                        onOpenChange={(open) => {
                            if (!open) {
                                setVariantSearch('');
                            }
                        }}
                    >
                        <SelectTrigger className="w-64">
                            <SelectValue placeholder="All variants" />
                        </SelectTrigger>
                        <SelectContent>
                            <div className="bg-popover sticky top-0 z-10 mb-1 border-b p-1">
                                <Input
                                    value={variantSearch}
                                    onChange={(event) => setVariantSearch(event.target.value)}
                                    onKeyDown={(event) => {
                                        // Radix Select claims every keystroke for its own
                                        // typeahead; let only the navigation keys through so
                                        // Escape, Enter and the arrows still work.
                                        if (!['Escape', 'Enter', 'ArrowDown', 'ArrowUp', 'Tab'].includes(event.key)) {
                                            event.stopPropagation();
                                        }
                                    }}
                                    placeholder="Search variants"
                                    aria-label="Search variants"
                                    className="h-8"
                                />
                            </div>
                            <SelectItem value={ALL}>All variants</SelectItem>
                            {shownVariants.map((variant) => (
                                <SelectItem key={variant.id} value={variant.id}>
                                    {variantLabel(variant)}
                                    {variant.sku && <span className="text-muted-foreground ml-2 text-xs">{variant.sku}</span>}
                                </SelectItem>
                            ))}
                            {shownVariants.length === 0 && <div className="text-muted-foreground px-2 py-3 text-sm">No variants match.</div>}
                        </SelectContent>
                    </Select>
                    <Select value={filters.movement ?? ALL} onValueChange={(value) => applyFilters({ movement: value })}>
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="All movements" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All movements</SelectItem>
                            {movements.map((movement) => (
                                <SelectItem key={movement} value={movement}>
                                    {MOVEMENT_LABEL[movement] ?? movement}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-2">
                        <span className="text-muted-foreground text-sm">From</span>
                        <Input
                            type="date"
                            value={filters.date_from ?? ''}
                            max={filters.date_to ?? undefined}
                            onChange={(e) => applyFilters({ date_from: e.target.value || null })}
                            className="w-40"
                        />
                        <span className="text-muted-foreground text-sm">To</span>
                        <Input
                            type="date"
                            value={filters.date_to ?? ''}
                            min={filters.date_from ?? undefined}
                            onChange={(e) => applyFilters({ date_to: e.target.value || null })}
                            className="w-40"
                        />
                    </div>
                    {/* Matched as a substring, so the serial alone is enough — a
                        delivery note in somebody's hand says `MNL-B-000012` and
                        the last six digits of it are the part they can read. Also
                        where the entry dialog's batch link lands, which is why it
                        is a box that holds the name rather than a chip that
                        clears one. */}
                    <Input
                        type="search"
                        value={batchSearch}
                        onChange={(e) => setBatchSearch(e.target.value)}
                        placeholder="Search batch"
                        aria-label="Search batch"
                        className="w-48"
                    />
                    {canViewAll && (
                        <BranchScopeSelect
                            scope={scope}
                            branches={branches}
                            onChange={(next) => applyFilters({ scope: next })}
                            label="Branches shown"
                        />
                    )}
                </div>

                {consolidatedOnline === false && (
                    <div role="alert" className="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        <span>
                            The business database could not be reached, so this is this branch&rsquo;s own stock history rather than the branches you
                            asked for.
                        </span>
                    </div>
                )}

                {entries.data.length === 0 ? (
                    <EmptyState
                        icon={ScrollText}
                        title="No stock movements"
                        description="Ledger entries appear here as stock is adjusted, sold, returned or defected."
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    {showBranch && <TableHead>Branch</TableHead>}
                                    <TableHead>Variant</TableHead>
                                    <TableHead>Movement</TableHead>
                                    <TableHead className="text-right">Change</TableHead>
                                    <TableHead className="text-right">On-hand</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.data.map((entry) => (
                                    <TableRow
                                        key={entry.id}
                                        className="cursor-pointer"
                                        onClick={() => setDetail(entry)}
                                        aria-label={`Movement details for ${entry.variant?.item ?? 'this product'}`}
                                    >
                                        <TableCell className="tabular-nums">{formatWhen(entry.created_at)}</TableCell>
                                        {showBranch && <TableCell className="font-medium">{entry.branch ?? '—'}</TableCell>}
                                        <TableCell>
                                            {entry.variant ? (
                                                <>
                                                    <span className="font-medium">{entry.variant.item ?? '—'}</span>
                                                    {entry.variant.name && <span className="text-muted-foreground"> · {entry.variant.name}</span>}
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={movementVariant(entry)}>{movementLabel(entry)}</Badge>
                                        </TableCell>
                                        <TableCell
                                            className={`text-right tabular-nums ${
                                                entry.quantity > 0
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : entry.quantity < 0
                                                      ? 'text-destructive'
                                                      : ''
                                            }`}
                                        >
                                            {entry.quantity > 0 ? `+${entry.quantity}` : entry.quantity}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{entry.resulting_snapshot}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={entries.links} />
            </div>

            <StockEntryDialog
                entry={detail}
                open={detail !== null}
                onOpenChange={(open) => !open && setDetail(null)}
                onFilterBatch={(reference) => {
                    // Close first: the list underneath is about to become a
                    // different list, and the row this dialog describes may not
                    // be in it.
                    setDetail(null);
                    // Filter now rather than 300ms from now — this was a click on
                    // a name, not a keystroke. The box is set to match, and the
                    // ref says so, which is what stops the debounce asking for
                    // the same list again.
                    applied.current = reference;
                    setBatchSearch(reference);
                    applyFilters({ batch: reference });
                }}
            />
        </AppLayout>
    );
}
