<?php

namespace Provisioning\Support;

/**
 * The branch role's privilege matrix (MULTIBRANCH-BRAINSTORM.md §7).
 *
 * Pure data, deliberately separated from the DDL in BranchRole so that the
 * tests can assert the LIVE grants in Postgres equal this table exactly. A
 * grant added by hand, or a table added by a future migration and forgotten,
 * then fails a test instead of quietly widening what a leaked branch
 * credential can do.
 *
 * The shape of least privilege here follows §5's split:
 *  - everything is readable, because the till mirrors the catalog, all users,
 *    settings and its own history, and reads schema_meta on every sync to
 *    decide whether it may sync at all (§13);
 *  - only LOCAL-WRITE tables are insertable (§5.2) — what a till legitimately
 *    originates offline;
 *  - only genuinely mutable derived state is updatable (§5.3).
 *
 * Grants are enumerated per table rather than issued as
 * `GRANT ... ON ALL TABLES IN SCHEMA`, and ALTER DEFAULT PRIVILEGES is
 * deliberately NOT used: both would silently extend the branch's reach to any
 * table a future migration adds, which is the opposite of what a
 * least-privilege matrix is for. The cost is that M9's migrate-on-update must
 * call BranchRole::reapplyGrants() for every schema it touches.
 */
final class BranchGrants
{
    /**
     * Every table in a business schema, minus Laravel's own `migrations`
     * ledger, which the branch has no business reading and which is the only
     * table in the schema carrying a sequence.
     *
     * @var list<string>
     */
    public const SELECT = [
        'audit_logs',
        'branch_status',
        'branches',
        'categories',
        'import_history',
        'inventory_ledger',
        'inventory_update_queue',
        'items',
        'login_history',
        'sale_return_items',
        'sale_returns',
        'schema_meta',
        'settings',
        'stock_snapshots',
        'transaction_items',
        'transactions',
        'users',
        'variants',
    ];

    /**
     * The local-write tables (§5.2) plus the two derived-state tables the till
     * upserts (§5.3). Everything omitted here is online-authoritative and
     * reaches the till by pull, never by push:
     *
     *  - users, categories, items, variants, settings — maintained online.
     *  - branches, schema_meta — provisioning/console territory.
     *  - inventory_update_queue — the branch only RESOLVES rows here (see
     *    UPDATE_COLUMNS). NOTE: M8 must ADD this table to INSERT and re-run
     *    reapplyGrants(). Adjustments are issued by an Owner or Admin on any
     *    branch device targeting any branch, so without the grant the feature
     *    is unreachable from the screens meant to offer it — today only the
     *    console's admin connection can create a row.
     *  - import_history — CSV import is an ONLINE-ONLY operation, so the import
     *    ledger genuinely is online-authoritative and this omission is correct
     *    rather than incidental. Pushing imports would mean pushing their
     *    `type = 'stock_adjustment'` ledger rows too, giving that class of row
     *    two writers and breaking single-writer-per-table.
     *
     * @var list<string>
     */
    public const INSERT = [
        'audit_logs',
        'branch_status',
        'inventory_ledger',
        'login_history',
        'sale_return_items',
        'sale_returns',
        'stock_snapshots',
        'transaction_items',
        'transactions',
    ];

    /**
     * §7 names exactly these two as table-level updatable: the branch's own
     * heartbeat row and its reconciled stock. Both are current-state by
     * definition, which is why the immutability triggers skip them too.
     *
     * @var list<string>
     */
    public const UPDATE = [
        'branch_status',
        'stock_snapshots',
    ];

    /**
     * Column-level UPDATE — the queue row a branch is allowed to resolve (§10).
     *
     * §7 words this as "UPDATE only on inventory_update_queue.status", but
     * `status` ALONE is unusable: the table carries
     * `check ((status = 'pending') = (resolved_at is null))`, so any statement
     * moving status off 'pending' MUST set resolved_at in the same statement or
     * the constraint rejects it. Accepting a row additionally writes
     * ledger_entry_id (the traceability link that makes acceptance idempotent)
     * and rejecting one writes rejection_reason (what the in-app admin shows
     * the Owner). So the grant is the four columns that one resolution touches
     * — still strictly less than table-level UPDATE, which would also expose
     * quantity, variant_id, branch_id and issued_by.
     *
     * @var array<string, list<string>>
     */
    public const UPDATE_COLUMNS = [
        'inventory_update_queue' => ['status', 'resolved_at', 'rejection_reason', 'ledger_entry_id'],
    ];

    /**
     * Asserted to carry NO privilege for the branch role.
     *
     * @var list<string>
     */
    public const NEVER = [
        'migrations',
    ];
}
