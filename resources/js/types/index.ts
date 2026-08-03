import { LucideIcon } from 'lucide-react';

export type Permission =
    | 'access-reports'
    | 'access-audit-logs'
    | 'manage-users'
    | 'access-settings'
    | 'access-backup'
    | 'view-item-cost'
    | 'view-consolidated-reports';

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

export interface SharedData {
    name: string;
    auth: Auth;
    // Business currency symbol (Business Settings; default ₱).
    currency: string;
    flash: { toast?: FlashToast | null; restart_required?: boolean; credentials_path?: string | null };
    // Null on an install whose sync tables do not exist yet (pre-migration).
    sync: SyncSnapshot | null;
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
    // Current on-hand from the stock snapshot; present where the controller
    // eager-loads it (e.g. the inventory list). Absent elsewhere.
    current_stock?: number;
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

/** A row of `inventory_update_queue`, named and localised server-side (M8). */
export interface QueuedAdjustment {
    id: string;
    // Branch CODE, not id — the roster resolves it before this reaches the client.
    branch: string;
    item: string;
    variant: string | null;
    // Signed: positive adds stock at that branch, negative removes it.
    quantity: number;
    notes: string | null;
    status: 'pending' | 'accepted' | 'rejected';
    // Set only on rejection, and written by the branch that turned it down.
    rejection_reason: string | null;
    issued_by: string;
    created_at: string;
    resolved_at: string | null;
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
    quantity: number;
    resulting_snapshot: number;
    type: 'sale' | 'stock_adjustment';
    sale_type: 'sale' | 'return' | 'defect' | null;
    reference_type: string;
    notes: string | null;
    created_at: string;
    variant: { id: string; name: string | null; item: string | null } | null;
    performed_by: string | null;
}

// A row in the Sales history list (Phase 9, append-only transactions).
// PaymentType / DiscountType are defined with the POS types below.
export interface TransactionListRow {
    id: string;
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
export interface ReportBranch {
    id: string;
    code: string;
    name: string;
}

// Which branches a report covers: 'local' = this device's own SQLite mirror
// (works offline), 'all' = every branch, or a single branch id. Anything but
// 'local' is Owner-only and online-only.
export type ReportScope = 'local' | 'all' | string;

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
