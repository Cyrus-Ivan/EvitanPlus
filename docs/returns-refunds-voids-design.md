# Returns, Refunds & Defects — Authoritative Design

> **Product:** written for **Evitan POS** (Phase 10) and still the design of record for
> **Evitan Plus**, whose after-sales rules are unchanged. One thing does differ upstream:
> `return_number` is unique **per branch**, not globally — the Postgres table carries a
> `branch_id` and a `(branch_id, return_number)` unique index, because each branch mints
> its own sequence. The `RET-YYYYMMDD-000001` format itself is unchanged and deliberately
> carries no branch prefix, unlike the transaction serial. See `docs/glossary.md` §1 on
> the two products.
>
> **Status:** Design of record — **implemented** (Phase 10, commit `e24d844`, 2026-07-12,
> plus audit wiring in Phase 15). The schema and rules below match the shipped code;
> any future change to after-sales behavior must keep this document in sync.
> Referenced from `ROADMAP.md`.
>
> **Void removed.** A void was a full reversal of an *untouched* transaction — which is
> functionally identical to unselecting the item from the cart at the POS **before**
> checkout, something the POS page already supports. Post-checkout, the only after-sales
> events are **return** and **defect**. (The filename keeps "voids" only because it is
> referenced across the codebase.)

## Why this exists

Returns and defects were originally folded **into** `transaction_items` via a `line_type`
(`sale|return|defect`) column and a self-referencing `source_transaction_item_id`.

That breaks the core invariant that **a completed sale is immutable** (`CLAUDE.md` →
"Immutable business history"). Adding return/defect rows to the original transaction's
item set changes *the set of lines on that receipt* — so reprinting it later would no
longer match the original sale.

**Design goal:** `transactions` + `transaction_items` are a frozen, append-only record of
the **sale only**. All after-sales activity (returns, refunds, defects) lives in a
dedicated table family that **references** the original sale without ever mutating it.

---

## Concepts & taxonomy

| Type | Stock | Money | Scope | Notes |
|------|-------|-------|-------|-------|
| **Return** | Restored (`+qty` ledger) | Refund at original captured price | Partial + multiple | Cumulative per line ≤ sold qty |
| **Defect** | **Not** restored | Write-off (reduces net sales) | Partial + multiple | 0-movement ledger entry for audit |
| **Refund** | — | The monetary result of a return | — | Not a separate type; an attribute of the event |

> A "cancel the whole sale" need before checkout is handled at the POS by unselecting
> items from the cart; after checkout, reversing everything is just a return of every line.

---

## Schema

Two new tables. **Naming note:** do **not** name the model `Return` — `return` is a PHP
reserved word and will not compile. Use table `sale_returns` / model `SaleReturn` and
table `sale_return_items` / model `SaleReturnItem`.

### `sale_returns` — event header (one per return/defect event)

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid pk | |
| `return_number` | string, **unique** | Human-readable `RET-YYYYMMDD-000001`, sequence resets daily (unlike `transaction_number`, which is a never-resetting BIR-shaped serial). |
| `transaction_id` | uuid FK → `transactions` | `restrictOnDelete` |
| `type` | string | `return \| defect` |
| `reason` | text, nullable | |
| `refund_payment_type` | string, nullable | `cash \| cashless \| others` — how money was returned; nullable for defect / no-cash |
| `total_refund` | decimal(12,2), default 0 | Snapshot sum of line `refund_amount`. Convenience only — reports **recompute** from lines. |
| `performed_by` | uuid FK → `users` | `restrictOnDelete` |
| `created_at` | timestamp | **`created_at` only** (`UPDATED_AT = null`) — append-only / immutable, matching `inventory_ledger`. |

Indexes: `transaction_id`, `type`, `created_at`, `return_number`.

### `sale_return_items` — lines

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid pk | |
| `sale_return_id` | uuid FK → `sale_returns` | `cascadeOnDelete` |
| `transaction_item_id` | uuid FK → `transaction_items` | `restrictOnDelete` — the original sold line |
| `variant_id` | uuid FK → `variants` | `restrictOnDelete` — denormalized (mirrors `transaction_items`); convenient for ledger + reporting |
| `quantity` | integer | positive, ≥ 1 |
| `selling_price` | decimal(12,2) | **Snapshot copied from source `transaction_item.selling_price`** — returns always use the original price, never the current variant price. |
| `cost` | decimal(12,2), nullable | Snapshot copied from source. **Owner-only** — use `HidesCostFromNonOwners`. |
| `refund_amount` | decimal(12,2) | Money reversed for this line (see algorithm below). |
| `created_at` | timestamp | **`created_at` only** (`UPDATED_AT = null`) |

Indexes: `sale_return_id`, `transaction_item_id`, `variant_id`.

`restores_stock` is **not** stored — it is derived from `sale_returns.type`
(`return` restores, `defect` does not).

---

## Core validation — the quantity quota

For every line being written against original sold line `S` (a `transaction_items` row):

```
already = SUM(sale_return_items.quantity
              WHERE transaction_item_id = S.id)   -- across ALL prior events, any type
require  new_qty >= 1
require  already + new_qty <= S.quantity
```

This single rule covers partial returns, multiple returns, and defects — the
**cumulative** returned/defected quantity per original line can never exceed what was sold.

The whole write (validation → header → lines → ledger → snapshot update → audit log) runs
inside **one DB transaction** so it is atomic. Re-check `already` **inside** the
transaction to stay race-safe even though the app is single-user.

---

## Refund amount algorithm (discount-correct, cent-exact)

Refunds must reflect what the customer **actually paid** — including line- and
transaction-level discounts — so returning every line refunds exactly `transaction.total`.

1. Each sale line's paid share of the transaction total:
   ```
   line_paid(S) = round(transaction.total * S.total / SUM(all sale lines' total), 2)
   ```
   This apportions any transaction-level discount across lines. Reconcile the last line
   so `SUM(line_paid) == transaction.total` exactly (assign the rounding remainder to the
   final line).
2. **Line refund, computed cumulatively** — what this line is owed *in total* once this
   event is included, minus what it has already been given:
   ```
   owed_after(S)   = round(line_paid(S) * (already + new_qty) / S.quantity)
   refund_amount   = owed_after(S) - SUM(prior refund_amounts for S)
   ```
   `already` is the quantity of `S` already returned **or** written off as defective —
   both consume sold quantity.

Result: fully returning every line of a transaction refunds exactly `transaction.total`,
and a line returned in stages refunds `line_paid(S)` in total however it is split.

Three properties come out of the cumulative form directly, and they are the reason for it:
`refund_amount` is **monotone** in `already + new_qty`, is **never negative**, and **never
exceeds `line_paid(S)`** — because `owed_after` is bounded by `line_paid(S)` at
`already + new_qty == S.quantity` and the prior refunds sum to a previous `owed_after`.

> *(This section previously described a per-unit algorithm: `unit_paid = line_paid / quantity`,
> `refund_amount = round(unit_paid * new_qty, 2)`, plus a fourth step that reconciled cents on
> the **final** unit only. It could over-refund and then write a **negative** refund. Every
> intermediate event ignored what had already been given back, so when the paid share is
> smaller than the sold quantity — 6 × ₱1.00 under a deep percentage discount leaves
> `line_paid` at 3c against `S.quantity` 6 — `round()` pushed each intermediate unit up, the
> running total passed the share, and the final true-up went negative on a column with no
> `CHECK`. The cumulative form above trues up on **every** event rather than the last one,
> which is what makes the separate final-unit step redundant. Corrected in the pre-release
> hardening sweep; `tests/Feature/Sales/SaleReturnTest.php` and
> `tests/Feature/Sales/MoneyFixtureTest.php` pin it.)*

---

## Inventory ledger integration

`inventory_ledger` already carries `type` (`sale|stock_adjustment`), `sale_type`
(`sale|return|defect`), `reference_type`, `reference_id`.

- **Extend allowed `reference_type`** to include `return` (alongside
  `transaction | manual_adjustment | csv_import`). This is a string column, so only the
  migration **comment** and validation change — no structural migration.
- Per `sale_return_item`:
  - **return** → insert a ledger row: `quantity = +qty`,
    `resulting_snapshot = prev + qty`, `type = 'sale'`, `sale_type = 'return'`,
    `reference_type = 'return'`, `reference_id = sale_returns.id`, `performed_by`.
  - **defect** → insert a ledger row with `quantity = 0` (stock unchanged),
    `sale_type = 'defect'`, `resulting_snapshot` unchanged, same reference fields. This
    satisfies `BRAINSTORM.md` ("defects generate a ledger entry") while honoring "defects
    do not restore stock" — the 0-movement row is a pure audit trail.
- The stock snapshot (Phase 6) is updated from these ledger inserts as normal.
  Returns can only **increase** stock, so the never-negative rule is never at risk here.

---

## Changes to the existing sale tables (Phase 9)

Because there is **no production data** (pre-release; the dev DB is re-creatable via
`migrate:fresh`), edit the existing migrations **in place** rather than layering alters:

- `transaction_items` migration: **drop** `line_type` and `source_transaction_item_id`
  (+ its FK/index). It becomes purely sale lines.
- Make the sale record immutable to match the ledger/returns convention: set
  `UPDATED_AT = null` and drop `updated_at` on **both** `transactions` and
  `transaction_items` (they are append-only; the current `->timestamps()` implies a
  mutability that must not happen).
- `TransactionItem` model: remove `line_type` / `source_transaction_item_id` from
  `$fillable`; remove `sourceItem()` / `relatedItems()`; add
  `returnItems(): hasMany(SaleReturnItem::class, 'transaction_item_id')`.
- `inventory_ledger` migration: update the `reference_type` comment to include `return`.

---

## New models (Phase 10)

- **`SaleReturn`** — `HasUuidPrimaryKey`, `UPDATED_AT = null`, `$table = 'sale_returns'`.
  Relations: `transaction()`, `performedBy()`, `items()` (hasMany `SaleReturnItem`).
- **`SaleReturnItem`** — `HasUuidPrimaryKey`, `HidesCostFromNonOwners`,
  `UPDATED_AT = null`. Relations: `saleReturn()`, `sourceItem()` (belongsTo
  `TransactionItem`), `variant()`.
- A single **action/service** (e.g. `ProcessSaleReturn`) wraps validation + header + lines
  + ledger + snapshot + audit log in one DB transaction. Follow the Actions conventions
  established in Phase 5.

---

## Permissions (as implemented)

- Process **return**: Cashier and up (they work the POS).
- Process **defect**: Admin and up (inventory write-off) — elevated inside the controller by submitted `type`.
- `cost` on `sale_return_items` stays **Owner-only** via `HidesCostFromNonOwners`.

---

## Reporting & audit

- Reports **recompute**: `net sales = sales − returns − defects`, summing
  `sale_return_items` (never a single stored total), per `CLAUDE.md` → "Reporting".
- Every return / defect writes an **`audit_logs`** entry
  (`sale.return.created` / `sale.defect.created`, wired in Phase 15 inside the atomic transaction).

---

## Edge cases covered (all implemented — see `tests/Feature/Sales/SaleReturnTest.php`)

- [x] Multiple partial returns against one line never exceed sold quantity (cumulative quota).
- [x] Returns/defects share the **same** per-line quota (they both consume sold qty).
- [x] Full line return refunds exactly `line_paid(S)` (no cent drift); returning every line refunds exactly `transaction.total`.
- [x] Returns use the **captured** selling price/cost, never the current variant values.
- [x] Transaction-level discount apportioned into refunds.
- [x] Defect restores no stock but still writes a 0-movement ledger row.
- [x] `cost` hidden from non-Owners everywhere, including return records and reports.
- [x] All writes atomic (single DB transaction); quota re-checked inside it.
- [x] `sale_returns` / `sale_return_items` are append-only (no `updated_at`); no edit/delete path.
