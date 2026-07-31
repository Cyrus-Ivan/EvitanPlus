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
 *  - LOCAL-WRITE tables are insertable (§5.2) — what a till legitimately
 *    originates offline;
 *  - genuinely mutable derived state is updatable (§5.3);
 *  - ONLINE-AUTHORITATIVE tables are both (§5.1, added M6.5) — the in-app admin
 *    runs on a branch device and is what §5.1 and §6 mean by "edited online".
 *  - DELETE is granted on nothing, ever.
 *
 * ## What M6.5 costs, stated plainly
 *
 * §7 used to be able to say a leaked branch credential "can read/append within
 * one schema but cannot delete history, touch other businesses, or run DDL".
 * The middle clause is weaker now: such a credential can also rewrite that one
 * business's catalog, prices, staff accounts and settings. It still cannot
 * delete anything, reach another business, or run DDL.
 *
 * That is the price of the in-app admin being a real feature rather than a
 * sentence in a spec, and it is bounded the same way everything else is: one
 * schema, one business, and revocable in a single console command. It does not
 * widen what a *person* may do — permission roles are enforced at the app layer
 * and were never a grant concern (§6).
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
     * Everything a branch device may originate.
     *
     * Three groups, and the third arrived late enough to be worth explaining:
     *
     *  1. The local-write tables (§5.2) — what a till authors offline and pushes
     *     up: transactions, their items, sale_returns and their items,
     *     inventory_ledger, login_history, audit_logs.
     *  2. The derived-state tables the till upserts (§5.3): branch_status and
     *     stock_snapshots.
     *  3. The ONLINE-AUTHORITATIVE tables (§5.1) — categories, items, variants,
     *     users, settings, import_history — added in M6.5.
     *  4. inventory_update_queue (§10) — added in M8. Not a fourth category so
     *     much as the first one that is neither the branch's own history nor
     *     shared data: a row here is one branch device asking ANOTHER branch to
     *     move stock, and it is issued by an Owner or Admin sitting at a branch
     *     device, so the branch role is the only credential in existence that
     *     could write it.
     *
     * Group 3 is not a relaxation of §5.1; it is what §5.1 always required and
     * nobody had granted. That section says these are "edited only online
     * (Provisioning Console or in-app admin while online)", and §6 says users
     * are "managed online (in-app admin, when connected)". The in-app admin runs
     * on a branch device and authenticates as this role, so without INSERT and
     * UPDATE here the sentence describes an operation the credential cannot
     * perform — which is exactly what shipped: every catalog, user and settings
     * edit went to local SQLite and reached Postgres never. See
     * MULTIBRANCH-ROADMAP.md M6.5.
     *
     * There is no HQ and no main branch (docs/glossary.md §8 retired both
     * words). An Owner or Admin at ANY branch may edit shared data, so the grant
     * belongs to every branch role or to none.
     *
     * Still omitted, and correctly:
     *
     *  - branches, schema_meta — provisioning and console territory. A branch
     *    may not invent branches or move its own version floor.
     *
     * ## What group 4 costs, stated plainly
     *
     * INSERT on inventory_update_queue KNOWINGLY widens the cross-branch reach.
     * Grants stop at the schema boundary, so a credential able to queue an
     * adjustment for its OWN branch can queue one for ANY branch of the same
     * business — and a queued adjustment that the target accepts becomes a real
     * ledger entry there. That is the same exposure class §4's annotation
     * already records for forged branch_id values on history rows, reachable
     * through one more table; one business still cannot reach another's.
     *
     * Bounded three ways: the branch may only ever move the row to `pending`
     * (resolution is UPDATE_COLUMNS, and the target branch decides), an
     * adjustment that would drive stock negative is refused by the target rather
     * than applied, and nothing here can DELETE.
     *
     * ## Row-level security is NOT coming, so this matrix must not erode
     *
     * RLS between branches inside one business schema is an ACCEPTED RISK,
     * deliberately not planned — not a deferred item somebody intends to build
     * (docs/glossary.md §9 carries the reasoning and is normative). In short:
     * every branch of one business belongs to one Owner and runs on a device
     * that business controls, so a credential in the wrong hands already means
     * the till is in the wrong hands; the boundary that carries weight is
     * business-to-business, and that one is schema-level and enforced.
     *
     * The consequence lands here. This matrix is now the ONLY boundary of its
     * kind, so widening it is a real decision and never a convenience:
     *
     *  - enumerated per table, never GRANT ... ON ALL TABLES and never
     *    ALTER DEFAULT PRIVILEGES, so a table a future migration adds grants
     *    nothing until someone writes it down here;
     *  - DELETE on nothing, anywhere — note there is no DELETE constant in this
     *    class at all, and adding one would mean wiring it into BranchRole too;
     *  - inventory_update_queue stays COLUMN-level UPDATE on its four columns;
     *  - any future widening states its reason in that phase's note, the way
     *    M6.5 and M8 both did.
     *
     * BranchRoleGrantsTest::test_the_live_grants_match_the_documented_matrix
     * keeps live Postgres equal to this file, so nothing widens quietly.
     *
     * @var list<string>
     */
    public const INSERT = [
        'audit_logs',
        'branch_status',
        'categories',
        'import_history',
        'inventory_ledger',
        'inventory_update_queue',
        'items',
        'login_history',
        'sale_return_items',
        'sale_returns',
        'settings',
        'stock_snapshots',
        'transaction_items',
        'transactions',
        'users',
        'variants',
    ];

    /**
     * The tables a branch may change in place.
     *
     * §7 named exactly two — the branch's own heartbeat row and its reconciled
     * stock, both current-state by definition, which is why the immutability
     * triggers skip them. M6.5 adds the five online-authoritative tables, for
     * the reason set out on INSERT above: a catalog edit, an archive, a role
     * change and a soft delete are all UPDATEs, and the in-app admin performs
     * them.
     *
     * DELETE is granted on NOTHING, here or anywhere, and that is unchanged and
     * load-bearing. Archiving a category, item or variant is
     * `UPDATE archived_at`; removing a user is `UPDATE deleted_at`. The
     * never-delete-a-business-record invariant does not depend on withholding
     * UPDATE, and BranchRoleGrantsTest still proves every DELETE is refused.
     *
     * @var list<string>
     */
    public const UPDATE = [
        'branch_status',
        'categories',
        'items',
        'settings',
        'stock_snapshots',
        'users',
        'variants',
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
