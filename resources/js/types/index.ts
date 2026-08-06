import { LucideIcon } from 'lucide-react';

export type Permission = 'access-reports' | 'access-audit-logs' | 'manage-users' | 'access-settings' | 'view-item-cost' | 'view-consolidated-reports';

export interface Auth {
    // Null for guests (auth screens). Authenticated app pages run behind the
    // `auth` layout, so within them the user is always present.
    user: User | null;
    permissions: Record<Permission, boolean>;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    // Gate this item against a server-resolved ability (preferred — the client
    // never re-implements the rule). Use for items whose route maps to a named
    // Gate in Permissions::ABILITIES.
    permission?: Permission;
    // Fallback gate by minimum role, for items without a matching ability (e.g.
    // Import/Export). Omit both for Cashier-level (visible to everyone).
    minRole?: UserRole;
}

export type ToastType = 'success' | 'info' | 'warning' | 'error';

export interface FlashToast {
    type: ToastType;
    message: string;
}

// Four values, not two: a branch whose credential was revoked, one running a
// build the business database refuses, and one whose internet is down look
// identical at the till and need three different responses. Only `offline` is
// fixed by waiting. None of them stop the till selling.
export type SyncState = 'online' | 'offline' | 'unauthorized' | 'version_blocked';

export interface SyncSnapshot {
    state: SyncState;
    last_sync_at: string | null;
    // Rows waiting to push. Derived from the outbox on every read, never a
    // stored counter, so it cannot drift from the rows it describes.
    unsynced_count: number;
    // `conflict` pairs with state `online`: the connection works and the
    // business database is refusing the data, so the backlog will not clear on
    // its own — the one failure that waiting does not fix.
    error_kind: 'auth' | 'version' | 'network' | 'conflict' | null;
    error: string | null;
}

/**
 * Which business and which branch this device is (M11.5).
 *
 * Two sources: `business` is a business-wide setting that every sync pulls
 * down, `branch`/`code` come from the local `instance` singleton and are
 * written once at setup. Shared on every page, the login screen included.
 */
export interface Identity {
    business: string | null;
    branch: string | null;
    // The branch code that prefixes every receipt serial this till prints.
    code: string | null;
}

export interface SharedData {
    name: string;
    auth: Auth;
    // Business currency symbol (Business Settings; default ₱).
    currency: string;
    flash: { toast?: FlashToast | null; credentials_path?: string | null };
    // Null on an install whose sync tables do not exist yet (pre-migration).
    sync: SyncSnapshot | null;
    // Null on a device that has never been hydrated — it has no branch to name.
    identity: Identity | null;
    [key: string]: unknown;
}

export type UserRole = 'owner' | 'admin' | 'cashier';

export interface Category {
    id: string;
    name: string;
    archived_at: string | null;
    items_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Variant {
    id: string;
    item_id: string;
    // Null for a single-product ("no variant") item; falls back to the item name.
    name: string | null;
    // Mandatory and stored uppercase.
    sku: string;
    barcode: string | null;
    selling_price: string;
    // Present only for the Owner — stripped server-side for everyone else.
    cost?: string | null;
    low_stock_threshold: number;
    // Current on-hand from THIS device's stock snapshot; present where the
    // controller eager-loads it (e.g. the inventory list). Absent elsewhere.
    current_stock?: number;
    // The on-hand figure for the one branch the scope names (M12). Absent in the
    // local scope and after a failed cross-branch read, where `current_stock` is
    // the answer. A separate key rather than an override of `current_stock`,
    // which is a get-only cached accessor server-side and silently ignores a
    // write — the local figure would render under another branch's heading.
    scoped_stock?: number;
    archived_at: string | null;
    created_at: string;
    updated_at: string;
}

/**
 * One branch of this business, read live from the business database (M8).
 *
 * Never stored on this device: the till knows which branch it *is* (from
 * `instance`), not the roster, so anything holding one of these got it from an
 * online read and should not cache it.
 */
export interface Branch {
    id: string;
    code: string;
    name: string;
    is_this_branch: boolean;
}

/** One branch as a pending request names it. */
export interface BranchRef {
    id: string;
    code: string;
    name: string;
}

/** A person as a pending request names them: who, and in what role. */
export interface RequestActor {
    name: string;
    role: UserRole | null;
}

/**
 * One answer somebody owes on a pending request, or has already given.
 *
 * An adjustment has exactly one (`target`). A transfer has two — `send` at the
 * branch the stock leaves and `receive` at the branch it arrives at — because
 * stock going out of one door and arriving at another are two physical claims
 * made by two different people, and either can be false on its own.
 */
export interface RequestLeg {
    id: string;
    role: 'target' | 'send' | 'receive';
    // The branch that must answer this leg, and whose stock moves when it does.
    branch: BranchRef | null;
    // Signed. On a receiving leg this is what the sender confirmed actually
    // left, which is not always what was originally asked for.
    quantity: number;
    // What was agreed, once answered. Equal to `quantity` on a plain approval,
    // smaller in magnitude on a partial one, null while pending AND on a
    // rejection — nothing was agreed, so there is no number.
    approved_quantity: number | null;
    status: 'pending' | 'accepted' | 'rejected';
    // Why it was turned down, or why only part of it was approved.
    resolution_reason: string | null;
    resolved_by: RequestActor | null;
    resolved_at: string | null;
    // Whether THIS device may answer it: the leg is aimed at this branch and is
    // still pending. The server decides it; the client only draws the buttons.
    actionable: boolean;
}

/**
 * One card on the Pending Stocks page: a stock movement that has been asked for
 * and has not happened, with every answer it is still waiting on.
 *
 * A request is a GROUP of `inventory_update_queue` rows sharing `parent_id ?? id`
 * — one row for an adjustment, two for a transfer. It is on the page because at
 * least one of its legs is pending; when the last one is answered it leaves, and
 * the record of what happened is on the ledger, in Stock History.
 */
export interface PendingRequest {
    id: string;
    kind: 'transfer' | 'adjustment';
    // How it was asked for: manual_adjustment | csv_import | transfer.
    source: string;
    item: string;
    variant: string | null;
    notes: string | null;
    // Signed, and always what was ORIGINALLY asked for.
    requested: number;
    issued_by: RequestActor & { branch: BranchRef | null; at: string };
    // A transfer names both ends; an adjustment names the one branch whose stock
    // is going to move. Exactly one of these shapes is filled.
    sender: BranchRef | null;
    receiver: BranchRef | null;
    target: BranchRef | null;
    legs: RequestLeg[];
}

/**
 * The props behind the Pending Stocks page.
 *
 * `online` is false when the read failed — everything here is Postgres-only, so
 * there is then nothing to show rather than an empty list. `branches` is every
 * branch but this one, for the scope picker.
 */
export interface PendingRequests {
    requests: PendingRequest[];
    branches: RosterBranch[];
    online: boolean;
    filters: {
        scope: BranchScope;
        kind: 'transfer' | 'adjustment' | null;
    };
}

/**
 * What was ASKED for behind one ledger row, fetched when its dialog opens
 * (M11.8).
 *
 * The ledger entry and the queue row it came from share an id, so this is a
 * lookup rather than a join. Null for a sale, a return or a defect — which never
 * went through the queue — and null offline, where the row's own `notes` carry
 * the sentence version instead.
 *
 * Every field describes THIS leg. `parent` is the sending leg of a transfer and
 * describes that one: a different person, at a different branch, answering a
 * different question.
 */
export interface MovementRequest {
    id: string;
    status: 'pending' | 'accepted' | 'rejected';
    kind: 'transfer' | 'adjustment';
    source: string;
    requested: number;
    approved: number | null;
    resolution_reason: string | null;
    // What the person ASKING wrote, as against `resolution_reason`, which is what
    // the person ANSWERING wrote.
    notes: string | null;
    issued_by: RequestActor & { branch: BranchRef | null; at: string };
    resolved_by: RequestActor | null;
    resolved_at: string | null;
    branch: BranchRef | null;
    counterparty_branch: BranchRef | null;
    // The sending leg, on a receiving one — and the only place the original
    // requester can be read: a receiving leg's own `issued_by` is the person who
    // confirmed the stock left, and it carries no issuing branch at all.
    parent: MovementRequestLeg | null;
}

export interface MovementRequestLeg {
    requested: number;
    approved: number | null;
    resolution_reason: string | null;
    notes: string | null;
    issued_by: RequestActor & { branch: BranchRef | null; at: string };
    resolved_by: RequestActor | null;
    resolved_at: string | null;
    branch: BranchRef | null;
}

export interface Item {
    id: string;
    name: string;
    unit: string;
    category_id: string | null;
    description: string | null;
    archived_at: string | null;
    category?: Pick<Category, 'id' | 'name'> | null;
    variants?: Variant[];
    created_at: string;
    updated_at: string;
}

export type LoginEventType = 'login' | 'logout' | 'application_crash' | 'forced_logout';

// A row from the append-only login history (Phase 14, display only).
export interface LoginHistoryEntry {
    id: string;
    // Branch CODE (M11.5). In the local scope it is this device's, from
    // `instance`; in a cross-branch scope the roster resolves it server-side.
    branch: string | null;
    event_type: LoginEventType;
    occurred_at: string | null;
    created_at: string;
    notes: string | null;
    user: Pick<User, 'id' | 'name' | 'username'> | null;
}

// A row from the immutable audit log (Phase 15, display only). Cost-bearing keys
// in old_values/new_values are stripped server-side for non-Owner viewers.
export interface AuditLogEntry {
    id: string;
    action: string;
    description: string | null;
    auditable_type: string | null;
    auditable_id: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
    user: Pick<User, 'id' | 'name' | 'username'> | null;
}

// A row from the append-only inventory ledger (Phase 6, stock history view).
export interface StockLedgerEntry {
    id: string;
    // Branch CODE (M11.8). In the local scope it is this device's, from
    // `instance`; in a wider scope the roster resolves it server-side.
    branch: string | null;
    quantity: number;
    resulting_snapshot: number;
    type: 'sale' | 'stock_adjustment';
    sale_type: 'sale' | 'return' | 'defect' | null;
    // What kind of record `reference_id` points at. Closed set (docs/glossary.md
    // §4) — with `type` and `sale_type` it is what a movement kind is read from,
    // see @/lib/stock-movement.
    reference_type: 'transaction' | 'manual_adjustment' | 'csv_import' | 'return' | 'transfer';
    // The other branch involved, by CODE. Stored on the ledger row itself rather
    // than joined, because Stock History has to render with no connection and
    // `branches` is never pulled to a device.
    counterparty_branch_code: string | null;
    notes: string | null;
    created_at: string;
    variant: { id: string; name: string | null; item: string | null } | null;
    performed_by: string | null;
}

// A row in the Sales history list (Phase 9, append-only transactions).
// PaymentType / DiscountType are defined with the POS types below.
export interface TransactionListRow {
    id: string;
    // The branch CODE this sale was rung up at. In the local scope it is this
    // device's, stamped from `instance`; in a wider one it is resolved from the
    // roster. Null only when a branch cannot be named at all.
    branch: string | null;
    transaction_number: string;
    payment_type: PaymentType;
    discount: string;
    discount_type: DiscountType | null;
    total: string;
    created_at: string;
    items_count: number;
    user: Pick<User, 'id' | 'name' | 'username'> | null;
}

// A single sold line on a transaction detail. `cost` is present only for a
// viewer who may see Item Cost (Owner).
export interface TransactionItemLine {
    id: string;
    label: string;
    quantity: number;
    selling_price: string;
    discount: string;
    total: string;
    cost?: string | null;
    // How much of this line has already been returned/defected (Phase 10).
    returned_quantity: number;
}

// After-sales event types (Phase 10). See docs/returns-refunds-voids-design.md.
export type SaleReturnType = 'return' | 'defect';

// A line of an after-sales event. `cost` is Owner-only (absent otherwise).
export interface SaleReturnLine {
    id: string;
    label: string;
    quantity: number;
    refund_amount: string;
    cost?: string | null;
}

// A recorded return / defect against a transaction (Phase 10).
export interface SaleReturnEvent {
    id: string;
    return_number: string;
    type: SaleReturnType;
    reason: string | null;
    refund_payment_type: PaymentType | null;
    total_refund: string;
    created_at: string;
    performed_by: Pick<User, 'id' | 'name' | 'username'> | null;
    items: SaleReturnLine[];
}

// The full transaction detail (Phase 9 receipt-style view).
export interface TransactionDetail {
    id: string;
    transaction_number: string;
    payment_type: PaymentType;
    discount: string;
    discount_type: DiscountType | null;
    total: string;
    cash_received: string | null;
    change: string | null;
    comment: string | null;
    created_at: string;
    user: Pick<User, 'id' | 'name' | 'username'> | null;
    items: TransactionItemLine[];
    // After-sales history (Phase 10), newest first.
    returns: SaleReturnEvent[];
}

// Reports (Phase 12). Sales are recomputed from source tables as
// net = gross − returns − defects; cogs/profit are Owner-only (absent otherwise).
export type ReportPeriod = 'daily' | 'weekly' | 'monthly' | 'yearly';

// One bucket (a day for daily, a month for monthly). Money fields are "0.00" strings.
// cogs/profit/uncosted_sales are Owner-only (absent for everyone else). `profit`
// covers only items with a recorded cost; net sales from items with no cost are
// reported separately as `uncosted_sales` and excluded from profit.
export interface ReportRow {
    // The drill-down sub-bucket key: '2026-07-12 14' (hour) for daily,
    // '2026-07-12' (day) for weekly/monthly, '2026-07' (month) for yearly.
    bucket: string;
    transactions: number;
    gross_sales: string;
    returns: string;
    defects: string;
    net_sales: string;
    cogs?: string;
    profit?: string;
    uncosted_sales?: string;
}

// One branch's figures for the whole selected period (M11, consolidated scope
// only). Same money fields as a ReportRow — the per-branch rows and the
// per-bucket rows are the same recompute over a different GROUP BY, so they sum
// to the same totals. A branch that sold nothing is present with zeros, not
// absent: "sold nothing" and "no data" are different answers.
export interface ReportBranchRow {
    branch_id: string;
    code: string;
    name: string;
    transactions: number;
    gross_sales: string;
    returns: string;
    defects: string;
    net_sales: string;
    cogs?: string;
    profit?: string;
    uncosted_sales?: string;
}

// A branch in the roster, read live from the business database.
/**
 * One entry of the branch roster, read live from the business database
 * (`App\Support\Sync\BranchRoster`). Distinct from {@link Branch}, which carries
 * `is_this_branch` for the screens that must not aim work at themselves.
 */
export interface RosterBranch {
    id: string;
    code: string;
    name: string;
}

// Which branches a screen covers: 'local' = this device's own SQLite mirror
// (works offline), 'all' = every branch, or a single branch id. Anything but
// 'local' is online-only. Used by reports (Owner-only, M11), login history
// (Admin+, M11.5), stock history and pending stocks (M11.8), and sales and
// inventory stock (Admin+, M12) — one parameter, one meaning, six screens.
// Inventory stock is the exception that takes 'local' or a branch id and never
// 'all': the scope lands there on a figure rather than on a list of rows, and a
// business-wide total per variant is unreadable without a breakdown under it.
export type BranchScope = 'local' | 'all' | string;

// The chart's series dimension: what sold, or which branch sold it.
export type ReportChartBy = 'item' | 'branch';

// Period-wide totals for the summary cards.
export interface ReportTotals {
    transactions: number;
    gross_sales: string;
    returns: string;
    defects: string;
    net_sales: string;
    cogs?: string;
    profit?: string;
    uncosted_sales?: string;
}

// Stacked-chart legend entry: one per item (all its variants aggregated), plus a
// trailing '__other__' bucket for items beyond the top few. `key` matches the
// keys in ReportChartBucket.values.
export interface ReportChartItem {
    key: string;
    name: string;
}

// One bucket's stacked breakdown: item key → that item's net sales ("0.00"
// string, may be negative). Keys omitted from `values` are zero for that bucket.
export interface ReportChartBucket {
    bucket: string;
    values: Record<string, string>;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
}

export interface User {
    id: string;
    name: string | null;
    username: string;
    role: UserRole;
    // Set for accounts whose password someone else knows (admin reset); the
    // user is confined to the password page until they set their own.
    must_change_password: boolean;
    avatar?: string;
    // Present on managed-user listings (Phase 13); set when the user is archived.
    deleted_at?: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

// A user row as surfaced by the User Management listing (Phase 13).
export interface ManagedUser {
    id: string;
    name: string | null;
    username: string;
    role: UserRole;
    deleted_at: string | null;
    created_at: string;
}

// Point of Sale (Phase 8).
export type PaymentType = 'cash' | 'cashless' | 'others';
export type DiscountType = 'amount' | 'percentage';

// A sellable product tile in the POS grid / scan lookup. Cost is Owner-only and
// deliberately absent — the server never sends it here.
export interface PosProduct {
    id: string;
    label: string;
    sku: string | null;
    barcode: string | null;
    selling_price: string;
    current_stock: number;
    unit: string;
}

// A line in the client-side cart. unit_price and max_stock are UI-only; only
// variant_id + quantity are sent to the server, which recomputes all money.
export interface CartLine {
    variant_id: string;
    label: string;
    unit_price: number;
    quantity: number;
    max_stock: number;
}
