import { BranchScopeSelect } from '@/components/branch-scope-select';
import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import { PendingRequestCard } from '@/components/inventory/pending-request-card';
import { OfflineNotice } from '@/components/offline-notice';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, PendingRequests } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ClipboardCheck, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Pending Stocks', href: '/inventory/stock-updates' },
];

// The sentinel for "both kinds", because a Select cannot hold an empty value.
const ANY = 'any';

/**
 * Pending Stocks.
 *
 * Every stock movement in the system starts here and waits for a person at the
 * affected branch to say it really happened, so this is the screen somebody has
 * to visit before stock moves at all.
 *
 * ## One list, of unfinished work only
 *
 * A card is a REQUEST — one queue row for an adjustment, two for a transfer —
 * and it is here because at least one of its legs is still waiting on somebody.
 * When the last leg is answered the card leaves, and the record of what happened
 * is on the ledger, in Stock History. That is a real change of address: a
 * refusal now writes a zero-quantity ledger row rather than nothing at all, and
 * Stock History takes a branch scope, so the branch that ASKED can finally read
 * the outcome. Before M11.8 neither was true, which is why this page had to
 * carry a second section for other branches' answered work.
 *
 * ## Both filters are on the URL
 *
 * Not in client state, so `back()` from an answer returns to the list the person
 * was looking at rather than to an unfiltered one.
 *
 * Everything here is read live from the business database and none of it is
 * stored on this device, so with no connection there is genuinely nothing to
 * show. That is the same precondition as issuing one in the first place.
 */
export default function StockUpdates({ requests, branches, online, filters }: PendingRequests) {
    const confirm = useConfirm();
    const [approvingAll, setApprovingAll] = useState(false);
    const approveForm = useForm({});

    // Only ever this branch's legs: the server decides `actionable`, and a leg
    // aimed elsewhere is not this device's to answer whatever is on screen.
    const answerable = requests.flatMap((request) => request.legs.filter((leg) => leg.actionable));

    const apply = (next: { scope?: string; kind?: string }) => {
        router.get(
            route('stock-updates.index'),
            {
                scope: (next.scope ?? filters.scope) === 'local' ? undefined : (next.scope ?? filters.scope),
                kind: (next.kind ?? filters.kind ?? ANY) === ANY ? undefined : (next.kind ?? filters.kind),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const approveAll = async () => {
        const agreed = await confirm({
            title: `Approve all ${answerable.length} waiting on this branch?`,
            description:
                'This moves the stock for every one of them at once, and each becomes a permanent ledger entry. ' +
                'Anything that would take a product below zero stops the whole batch, so nothing is half-applied.',
            confirmLabel: 'Approve all',
        });

        if (!agreed) {
            return;
        }

        setApprovingAll(true);
        // The ids actually on screen, not a server-side sweep: the page filters,
        // so "all" can only honestly mean all of what is being shown.
        approveForm.transform(() => ({
            adjustments: answerable.map((leg) => ({ id: leg.id, outcome: 'approve' })),
        }));
        approveForm.post(route('stock-updates.resolve'), {
            preserveScroll: true,
            onFinish: () => setApprovingAll(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pending Stocks" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Pending Stocks</h1>
                    <p className="text-muted-foreground text-sm">
                        Stock changes that have been asked for but have not moved yet. Nothing here is on the ledger until somebody at the branch
                        confirms it happened.
                    </p>
                </div>

                {!online && <OfflineNotice what="Pending Stocks" />}

                {online && (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <BranchScopeSelect
                                    scope={filters.scope}
                                    branches={branches}
                                    onChange={(scope) => apply({ scope })}
                                    label="Branches shown"
                                />
                                <Select value={filters.kind ?? ANY} onValueChange={(kind) => apply({ kind })}>
                                    <SelectTrigger className="w-44" aria-label="Kind of request">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Transfers and adjustments</SelectItem>
                                        <SelectItem value="transfer">Transfers only</SelectItem>
                                        <SelectItem value="adjustment">Adjustments only</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            {answerable.length > 0 && (
                                <Button size="sm" onClick={approveAll} disabled={approvingAll}>
                                    {approvingAll && <LoaderCircle className="mr-2 size-4 animate-spin" />}
                                    Approve all ({answerable.length})
                                </Button>
                            )}
                        </div>

                        {requests.length === 0 ? (
                            <EmptyState
                                icon={ClipboardCheck}
                                title="Nothing is waiting"
                                description="Stock movements appear here as soon as somebody asks for one, and leave once every branch involved has answered."
                            />
                        ) : (
                            <div className="space-y-3">
                                {requests.map((request) => (
                                    <PendingRequestCard key={request.id} request={request} />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
