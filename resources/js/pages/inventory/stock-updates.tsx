import EmptyState from '@/components/data/empty-state';
import { BranchScopeSelect } from '@/components/filters/branch-scope-select';
import FilterBar from '@/components/filters/filter-bar';
import { useConfirm } from '@/components/forms/confirm-dialog';
import SubmitButton from '@/components/forms/submit-button';
import { PendingRequestCard } from '@/components/inventory/pending-request-card';
import PageShell, { PageHeader } from '@/components/page/page-shell';
import { OfflineNotice } from '@/components/sync/offline-notice';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useListFilters } from '@/hooks/use-list-filters';
import AppLayout from '@/layouts/app-layout';
import { ALL } from '@/lib/filters';
import type { BreadcrumbItem, PendingRequests } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Pending Stocks', href: '/inventory/stock-updates' },
];

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

    // No `only`: this page has few props and every one of them changes with the
    // filters, so a partial reload would list all of them.
    const { apply } = useListFilters({
        url: route('stock-updates.index'),
        values: { scope: filters.scope, kind: filters.kind ?? ALL },
        defaults: { scope: 'local', kind: ALL },
        only: [],
    });

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
            <PageShell>
                <PageHeader
                    title="Pending Stocks"
                    description="Stock changes that have been asked for but have not moved yet. Nothing here is on the ledger until somebody at the branch confirms it happened."
                />

                {!online && <OfflineNotice what="Pending Stocks" />}

                {online && (
                    <>
                        <FilterBar
                            actions={
                                answerable.length > 0 && (
                                    <SubmitButton type="button" size="sm" onClick={approveAll} processing={approvingAll}>
                                        Approve all ({answerable.length})
                                    </SubmitButton>
                                )
                            }
                        >
                            <BranchScopeSelect
                                scope={filters.scope}
                                branches={branches}
                                onChange={(scope) => apply({ scope })}
                                label="Branches shown"
                            />
                            <Select value={filters.kind ?? ALL} onValueChange={(kind) => apply({ kind })}>
                                <SelectTrigger className="w-44" aria-label="Kind of request">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Transfers and adjustments</SelectItem>
                                    <SelectItem value="transfer">Transfers only</SelectItem>
                                    <SelectItem value="adjustment">Adjustments only</SelectItem>
                                </SelectContent>
                            </Select>
                        </FilterBar>

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
            </PageShell>
        </AppLayout>
    );
}
