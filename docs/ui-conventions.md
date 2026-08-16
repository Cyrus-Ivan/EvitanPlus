# Evitan Plus — UI conventions

> **What this is.** Which shared component to reach for, what is deliberately *not* shared, and
> the house rules that keep both true. A companion to `docs/glossary.md` — that one is normative
> on what a **word** means, this one on what a **component** is for.
>
> **Why it exists.** The frontend grew screen by screen through twenty roadmap phases and the
> `M0–M18` initiative, and the same markup got copied forward each time. That is survivable
> right up until the copies start disagreeing, which they had: `MONTHS` was declared twice, one
> import apart; the payment badge was toned per type on the sales list and hardcoded on the sale
> detail, so one sale wore two different badges depending on where you opened it; `formatWhen`
> existed in six files and five of them had lost the null guard the sixth had. None of that was a
> design decision. This file is where the decision now lives.
>
> **Anchors, like the glossary.** Every rule names the file that implements it, so it can be
> checked rather than merely asserted.

---

## 1. Where things live

```
resources/js/components/
├── ui/          shadcn primitives. Generated, not written here.
├── layout/      app chrome — the sidebar, header, nav, logo. Drawn once around every page.
├── page/        structure inside one page — PageShell, PageHeader, the heading scales.
├── forms/       collecting input — Field, InputError, SubmitButton, FormDialog, useConfirm.
├── data/        displaying records — TableCard, Pagination, EmptyState, StatCard,
│                ArchivedBadge, MonthGrid.
├── filters/     narrowing a list — FilterBar and the controls that sit in it.
├── sync/        the connection and branch story — SyncIndicator, OfflineNotice, BranchIdentity.
├── settings/    reusable settings field groups, shared by the wizard and Business Settings.
└── inventory/ reports/ users/ sales/ login-history/
              feature components, grouped by the thing they belong to.
```

**Two axes, and which one applies is decided by reuse.** A component used by more than one feature
is filed by *what it is* (`data/`, `forms/`, …). A component belonging to one feature is filed under
that feature, even when it is large — `inventory/stock-adjust-dialog.tsx` is not a "form", it is
part of Inventory. If a feature component becomes genuinely shared, move it; do not copy it.

**No barrel files.** There is no `index.ts` in any of these folders and there should not be. Imports
name the file they come from, which is what makes a symbol greppable back to its definition.

## 2. Which component to reach for

| You need | Use | Path |
| --- | --- | --- |
| A modal that collects something | `<FormDialog>` + `useDialogForm` | `components/forms/form-dialog.tsx`, `hooks/use-dialog-form.ts` |
| A modal that shows a record | `<DetailDialog>` | `components/forms/form-dialog.tsx` |
| "Are you sure?" | `useConfirm()` | `components/forms/confirm-dialog.tsx` |
| One labelled form control | `<Field>` | `components/forms/field.tsx` |
| A form's submit button | `<SubmitButton>` | `components/forms/submit-button.tsx` |
| A list page's filter machinery | `useListFilters` + `useDebouncedFilter` | `hooks/use-list-filters.ts` |
| The row of controls above a list | `<FilterBar>` | `components/filters/filter-bar.tsx` |
| A search box | `<SearchInput>` | `components/filters/search-input.tsx` |
| Active / Archived / All | `<StatusFilter>` | `components/filters/status-filter.tsx` |
| One category | `<CategoryFilter>` | `components/filters/category-filter.tsx` |
| A From/To date window | `<DateRangeFilter>` | `components/filters/date-range-filter.tsx` |
| One month | `<MonthYearPicker>` | `components/filters/month-year-picker.tsx` |
| Which branches (**branch scope**, glossary §9) | `<BranchScopeSelect>` | `components/filters/branch-scope-select.tsx` |
| A page body inside `AppLayout` | `<PageShell>` | `components/page/page-shell.tsx` |
| A page title + description | `<PageHeader>` | `components/page/page-shell.tsx` |
| A table in its card, or the empty state | `<TableCard>` | `components/data/table-card.tsx` |
| A cell holding a figure | `<NumericCell>` | `components/data/table-card.tsx` |
| Nothing to show | `<EmptyState>` | `components/data/empty-state.tsx` |
| Paginator links | `<Pagination>` | `components/data/pagination.tsx` |
| A headline figure | `<StatCard>` | `components/data/stat-card.tsx` |
| A month as a calendar | `<MonthGrid>` | `components/data/month-grid.tsx` |
| Active / Archived pill | `<ArchivedBadge>` | `components/data/status-badge.tsx` |
| An amber advisory banner | `<Alert variant="warning">` | `components/ui/alert.tsx` |
| "This screen edits shared data and you are offline" | `<OfflineNotice>` | `components/sync/offline-notice.tsx` |
| Money | `useCurrency()` / `formatMoney` | `hooks/use-currency.ts`, `lib/money.ts` |
| A timestamp on a row | `formatWhen` | `lib/format.ts` |
| A duration in hours | `formatHours` | `lib/format.ts` |
| A payment type's label or tone | `paymentLabel` / `paymentVariant` | `lib/payment.ts` |
| A role's label | `roleLabel` | `lib/roles.ts` |
| A ledger movement's label or tone | `movementLabel` / `movementVariant` | `lib/stock-movement.ts` |
| A login event's label or dot | `eventLabel` / `EVENT_DOT` | `lib/login-events.ts` |
| A queue row's tone | `requestStatusVariant` | `lib/request-status.ts` |
| "Don't filter on this" | `ALL` | `lib/filters.ts` |

**Label maps live in `lib/`, never in a component.** `lib/stock-movement.ts` is the model: a
`Record` of the values, a `…Label()` that falls back to the raw value for one this build
predates, and a `…Variant()` for its tone. Every map that stayed in a component ended up
copied — payment types four times, roles four times.

## 3. What is deliberately not abstracted

Each of these is a decision, not an oversight. Do not "finish the job".

- **Tables are bespoke JSX.** There is no `<DataTable columns={…}>` and there should not be.
  The tables here have expandable variant rows (`pages/items/index.tsx`), a `colSpan` computed
  from which columns the reader's role may see, a Branch column that appears only under a wider
  scope, and currency columns that align differently from count columns. `<TableCard>` takes the
  wrapper and the empty-state ternary — the parts that really were repeated — and leaves the
  columns alone, where they are greppable.
- **The single/batch dialog pairs stay separate.** `stock-adjust-dialog` / `batch-adjust-dialog`
  and `stock-transfer-dialog` / `batch-transfer-dialog` are ~950 lines of near-clones and they
  stay that way. An adjustment names one branch and a direction; a transfer names two branches
  and no direction; the quantity on a line means a different thing in each. They were one dialog
  with a toggle for exactly one milestone.
- **The two import dialogs are not `<FormDialog>`s.** `import-items-dialog` and
  `import-opening-stock-dialog` have a tab bar inside the header, a scrolling body with the
  padding moved onto it, a footer on one tab only, and a submit that is a button rather than the
  form's own — the `<form>` in them belongs to "Check file", which writes nothing. Wrapping them
  would mean a prop per difference. They share `SubmitButton` and `useDialogForm`, which is
  where the real repetition was.
- **`pages/pos.tsx` is left alone.** It is the till. It takes `<PageShell>` and the payment
  labels and nothing else.
- **Most list pages have no heading.** The breadcrumb bar above them already names the page, and
  a title repeating it is the same word twice down the left edge. `<PageHeader>` is for the page
  that needs to say more than its name — `/inventory/stock-updates` explains what a pending row
  is before showing any.
- **Native `<input type="date">`, no calendar library.** The app runs in a Chromium shell, so
  the native picker is already keyboard-accessible, localised to the reader's date format, and
  typeable by somebody who would rather not open a calendar. `react-day-picker` would be a new
  dependency in a desktop bundle to arrive at the same place. `<DateRangeFilter>` and
  `<MonthYearPicker>` are the seam if that ever changes.
- **Approve / reject on `pending-request-card.tsx` do not go through `useConfirm`.** Every other
  destructive action does. Routing these through it would *add a confirmation step to a
  stock-approval flow*, which is a UX change rather than a refactor, and it has not been made.

## 4. House rules

- **Every shared component carries a JSDoc block saying why it exists**, not what it renders.
  The rationale comments in this codebase are its documentation — see `confirm-dialog.tsx`,
  `branch-scope-select.tsx`, `event-calendar.tsx`. When code moves into a shared component, its
  comment moves with it.
- **`<Field>` requires `htmlFor`.** Wiring a label to its control is the step that gets skipped,
  and skipping it means clicking the label does nothing and a screen reader announces an unnamed
  box. A required prop makes the type checker ask every time.
- **A group of controls is not a `<Field>`.** A `<label for>` cannot name a `ToggleGroup` or a
  list of checkboxes. Render the visible `<Label>` and give the group its own `aria-label` —
  see `stock-adjust-dialog.tsx`'s Mode toggle.
- **Every filter control gets an `aria-label`.** The `Select`s always had one and the date inputs
  never did, on the same toolbars.
- **`useListFilters` never defaults `only`.** The partial-reload keys are per-page and load
  bearing in both directions: too few and a prop goes stale, too many and every keystroke pays
  for a prop it did not need. `/sales` and `/inventory/stock-history` deliberately leave
  `branches` out, because the roster costs an **online read**.
- **Pass only the filters that belong in the URL.** Several pages' `filters` prop also carries
  the server's *answers* — a period label, the steppers' resolved destinations. Those are not the
  reader's questions and must not be echoed back; see `pages/login-history.tsx`.
- **`ui/*` is shadcn's.** Edit it only with a comment saying why. `dialog.tsx` carries such an
  edit (`max-h-[85vh] overflow-y-auto`, slide animations removed) and it must survive a
  re-generate. `alert.tsx` is this project's one addition to the set.
- **No new runtime dependency without a reason the bundle can justify.** This ships as an
  AppImage to a shop counter.
- **Prettier is configured unusually**: `tabWidth: 4`, `singleQuote`, `printWidth: 150`, plus
  `prettier-plugin-organize-imports` and `prettier-plugin-tailwindcss`. Run `npm run format`
  before judging a diff. `composer verify` runs `format:check` and will fail on an unformatted
  file.

## 5. Known asymmetries

- **`components/ui/table.tsx` is from a newer shadcn era than its 21 siblings** — plain
  functions with `data-slot` attributes, where the rest are `React.forwardRef` with
  `displayName`. It works; normalizing it is churn. Left as is.
- **`components/inventory/pending-request-card.tsx` has a local `Field`** that is a labelled
  block, unrelated to `components/forms/field.tsx`. So does `pages/sales/show.tsx`, where it has
  been renamed `Detail` for exactly this reason. The remaining one is the odd name out.
- **`pages/inventory/index.tsx`'s `OfflineStockNotice` is a compact line, not an `<Alert>`.**
  Deliberate, and documented at the site: when the device is offline that screen is *already*
  carrying `<OfflineNotice>`, and two amber boxes read as one warning repeated rather than as
  two different facts.
- **Tailwind v4 is CSS-first.** There is no `tailwind.config.js`; the tokens live in
  `@theme` in `resources/css/app.css`. `components.json`'s `tailwind.config` is `""` to match.
