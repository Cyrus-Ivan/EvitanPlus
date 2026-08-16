<?php

declare(strict_types=1);

/**
 * The manual rig's year of trading — 365 days of sales, returns and defects,
 * written straight into a business schema.
 *
 * Required by `tests/manual/pg.php`, which owns the connection, the catalog and
 * the transaction this runs inside; it also owns {@see uuid()} and
 * {@see quoteIdentifier()}, which this file uses and deliberately does not
 * redeclare. Same constraints as its host: no Laravel, no console code, nothing
 * autoloaded and nothing shipped.
 *
 * ## Why the rig needs a year at all
 *
 * Every rig used to start at zero sales, so `/sales` was empty and `/reports`
 * was flat in every period. Ringing up a few by hand gets you a few — never the
 * twelve monthly bars of the yearly view, never a per-branch consolidated table
 * with different numbers in it, never a second page of anything. The rig is for
 * *looking* (docs/glossary.md §1), and there was nothing to look at.
 *
 * ## Stock is DERIVED from the sales, never asserted beside them
 *
 * Upstream, a snapshot IS its ledger sum: `RemoteStock::recompute()` re-sums
 * Postgres's own `inventory_ledger` after every push. A `stock_snapshots` row
 * that disagrees with the ledger behind it is the M7 rig defect — the first
 * pushed sale restates the till's stock, or trips
 * `stock_snapshots_non_negative` and takes the branch's whole sync cycle down
 * permanently, reporting `online` + `conflict`, correctly and forever.
 *
 * So the caller works backwards. {@see rigNetMovements()} totals a planned year,
 * the opening quantity is `today's figure − that total`, and
 * {@see rigWriteYear()} walks the same events in order carrying a running
 * `resulting_snapshot`. The year ends on exactly the per-branch figure
 * `pg.php`'s catalog table already names, so the rig's stock states — CEB out of
 * Party Size chips, under threshold on Dish Soap — survive the arithmetic
 * untouched.
 *
 * Two guards, because "it worked when I ran it" is not a property of a random
 * generator:
 *
 *  - **No after-sales event against a variant whose branch figure is 0.** A
 *    return puts stock back, so a return *after* the low point of a variant that
 *    ends at zero is the one shape that can drive the running snapshot negative.
 *  - **{@see rigWriteYear()} throws** the moment a running snapshot would go
 *    below zero. A dev-only seeder that can hand a till an unsyncable schema
 *    must fail here, not two minutes later on the tenth screen as "Sync stuck".
 *
 * ## It mirrors the app's own arithmetic, because reports recompute
 *
 * `SalesFigures` derives everything from these columns, so the money conventions
 * are copied from `PosController::store` and `ProcessSaleReturn` rather than
 * invented: a line's `discount` is always `0.00` and its `total` is
 * `selling_price × quantity`; `transactions.discount` stores the PERCENT for a
 * percentage discount and the peso amount otherwise; `cash_received` / `change`
 * exist for cash only; a returned line's refund is its proportional share of
 * what was actually paid, with the last line absorbing the rounding remainder.
 * Get one of those wrong and the seeded year still renders — it just does not
 * add up, which is the least useful thing a perception tool can be.
 */

/**
 * The generator's seed. Fixed, so every rig run tells the same story and a
 * screenshot from last week still matches what you are looking at. The UUIDs
 * are not affected (they come from `random_bytes`) and should not be: they are
 * real identifiers of rows that really are new each run.
 */
const RIG_SALES_SEED = 20260812;

/** Days of history, ending today. */
const RIG_SALES_DAYS = 365;

/** Share of sales that get an after-sales event, as a percentage. */
const RIG_RETURN_RATE = 2;

/** Of those, the share that are returns rather than defects, as a percentage. */
const RIG_RETURN_SHARE = 60;

/**
 * Rows per multi-row INSERT.
 *
 * The year is ~50,000 rows across five tables and every flush is a network round
 * trip to Supabase, so this is the difference between one minute of seeding and
 * four. 1,000 × 12 columns is 12,000 binds, comfortably inside Postgres's 65,535.
 */
const RIG_INSERT_CHUNK = 1000;

/**
 * Monday…Sunday. Weekends are busier; Tuesday is the dead day.
 *
 * These average a little above 1.0, as do the months below, so a branch set to
 * 12 a day lands nearer 13 — the shape is what matters here, not the mean.
 */
const RIG_DOW_FACTORS = [1 => 0.90, 2 => 0.85, 3 => 0.92, 4 => 1.00, 5 => 1.20, 6 => 1.40, 7 => 1.25];

/** January…December. December carries Christmas, January is the hangover. */
const RIG_MONTH_FACTORS = [
    1 => 0.85, 2 => 0.90, 3 => 0.95, 4 => 1.00, 5 => 1.05, 6 => 1.00,
    7 => 0.95, 8 => 1.00, 9 => 1.00, 10 => 1.05, 11 => 1.10, 12 => 1.35,
];

/**
 * DAY trading hours, weighted for the lunch and after-work peaks. The nights a
 * branch stays open past these draw from {@see RIG_NIGHT_HOUR_WEIGHTS} instead.
 */
const RIG_HOUR_WEIGHTS = [
    8 => 4, 9 => 6, 10 => 8, 11 => 12, 12 => 14, 13 => 10,
    14 => 7, 15 => 7, 16 => 8, 17 => 11, 18 => 12, 19 => 7,
];

/**
 * ISO days (Monday = 1) whose NIGHT a branch trades through. Weekends, which is
 * also where {@see RIG_DOW_FACTORS} already puts the busy days.
 */
const RIG_NIGHT_NIGHTS = [5, 6];

/**
 * Late-night trading hours.
 *
 * Keys past 23 are hours of the NEXT calendar day — 24 is midnight, 27 is 03:00.
 * That is what keeps one night's sales one night: they are planned from the
 * evening they started, so `tests/manual/attendance.php` can derive a single
 * shift over the pair.
 */
const RIG_NIGHT_HOUR_WEIGHTS = [20 => 10, 21 => 12, 22 => 10, 23 => 8, 24 => 6, 25 => 4, 26 => 3, 27 => 2];

/** A night's sales, as a percentage of that day's daytime count. */
const RIG_NIGHT_SHARE = 45;

/** Distinct variants on one receipt. */
const RIG_LINE_WEIGHTS = [1 => 30, 2 => 32, 3 => 20, 4 => 12, 5 => 6];

/** Units of one variant on one line. */
const RIG_QTY_WEIGHTS = [1 => 60, 2 => 25, 3 => 10, 4 => 5];

const RIG_PAYMENT_WEIGHTS = ['cash' => 70, 'cashless' => 25, 'others' => 5];

/** Percentage of sales carrying a discount. */
const RIG_DISCOUNT_RATE = 10;

/** Percentage of sales carrying a comment. */
const RIG_COMMENT_RATE = 3;

const RIG_COMMENTS = ['Regular customer', 'Delivery order', 'Senior citizen', 'Reserved pickup'];

const RIG_RETURN_REASONS = ['Wrong item handed over', 'Customer changed their mind', 'Damaged packaging'];

const RIG_DEFECT_REASONS = ['Expired stock', 'Water damage in storage', 'Torn packaging, unsellable'];

/**
 * The columns each buffered insert writes, in bind order.
 *
 * Also the FLUSH order ({@see rigFlush()}): parents before children, because
 * these are ordinary immediate foreign keys and a buffered line can only ever
 * reference a transaction buffered in the same pass or an earlier one.
 * `inventory_ledger.reference_id` is a bare uuid column, not an FK, but it goes
 * last anyway — it is the row that describes the others.
 */
const RIG_COLUMNS = [
    'transactions' => [
        'id', 'branch_id', 'transaction_number', 'payment_type', 'discount', 'discount_type',
        'total', 'cash_received', 'change', 'user_id', 'comment', 'created_at',
    ],
    'transaction_items' => [
        'id', 'transaction_id', 'variant_id', 'quantity', 'selling_price', 'cost',
        'discount', 'total', 'created_at',
    ],
    'sale_returns' => [
        'id', 'branch_id', 'return_number', 'transaction_id', 'type', 'reason',
        'refund_payment_type', 'total_refund', 'performed_by', 'created_at',
    ],
    'sale_return_items' => [
        'id', 'sale_return_id', 'transaction_item_id', 'variant_id', 'quantity',
        'selling_price', 'cost', 'refund_amount', 'created_at',
    ],
    'inventory_ledger' => [
        'id', 'branch_id', 'variant_id', 'quantity', 'resulting_snapshot', 'type', 'sale_type',
        'reference_id', 'reference_type', 'notes', 'performed_by', 'created_at',
    ],
];

/**
 * A branch's year, planned but not written.
 *
 * Planning and writing are separate passes because the opening stock cannot be
 * known until the whole year is: it is the closing figure minus everything the
 * year moves. The plan is deliberately compact — variant INDEXES and integers,
 * no rendered rows — so holding two branches of it costs a few megabytes.
 *
 * The caller seeds the RNG, once, so that the two branches draw from one stream
 * and do not come out as the same day repeated twice with different totals.
 *
 * This is also where the branch's NIGHT ROTA lives, because who is on has to be
 * decided before who sold: on the nights named by {@see RIG_NIGHT_NIGHTS} the
 * branch trades past midnight, and the one person working it
 * ({@see rigNightUser()}) rings the night's sales and none of that day's.
 *
 * @param  array{per_day: float}  $branch
 * @param  list<array{price: int, cost: int, weight: int, final: int}>  $variants  Indexed as pg.php's catalog.
 * @param  list<array{id: string, weight: int, role: string}>  $users
 * @return list<array<string, mixed>> Sales and after-sales events, chronological.
 */
function rigPlanYear(array $branch, array $variants, array $users, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $sales = [];
    $nightUser = rigNightUser($users);

    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $count = max(1, (int) round(
            $branch['per_day']
            * RIG_DOW_FACTORS[(int) $day->format('N')]
            * RIG_MONTH_FACTORS[(int) $day->format('n')]
            * (mt_rand(75, 125) / 100)
        ));

        $night = $nightUser !== null && in_array((int) $day->format('N'), RIG_NIGHT_NIGHTS, true);

        for ($i = 0; $i < $count; $i++) {
            $at = $day->setTime((int) rigPick(RIG_HOUR_WEIGHTS), mt_rand(0, 59), mt_rand(0, 59));

            // Today is a part-day: the window ends at the moment of seeding, and
            // a receipt from this evening would be a sale that has not happened.
            if ($at > $end) {
                continue;
            }

            // Whoever is on nights tonight rings NOTHING today. Let them sell at
            // lunch as well and their day's events run 08:00 to 04:00, which
            // derives as one twenty-hour shift rather than a night shift — see
            // tests/manual/attendance.php.
            $sales[] = rigReceipt($at, rigPickUser($users, $night ? $nightUser : null), $variants);
        }

        if (! $night) {
            continue;
        }

        for ($i = 0, $n = (int) ceil($count * RIG_NIGHT_SHARE / 100); $i < $n; $i++) {
            $hour = (int) rigPick(RIG_NIGHT_HOUR_WEIGHTS);

            // Past 23 is tomorrow morning, and it belongs to tonight.
            $at = $day->modify('+'.intdiv($hour, 24).' day')
                ->setTime($hour % 24, mt_rand(0, 59), mt_rand(0, 59));

            if ($at > $end) {
                continue;
            }

            $sales[] = rigReceipt($at, $nightUser, $variants);
        }
    }

    usort($sales, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

    $events = $sales;

    // Each sale's ordinal is its index in the sorted list, and it stays valid:
    // after-sales events are appended and the merge below is a stable sort on a
    // strictly later timestamp, so nothing reorders the sales among themselves.
    foreach (array_keys($sales) as $ordinal) {
        $event = rigAfterSale($sales, $ordinal, $variants, $users, $nightUser, $end);

        if ($event !== null) {
            $events[] = $event;
        }
    }

    usort($events, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

    return $events;
}

/**
 * One receipt, ready to be sorted into the year.
 *
 * @param  list<array{price: int, cost: int, weight: int, final: int}>  $variants
 * @return array<string, mixed>
 */
function rigReceipt(DateTimeImmutable $at, string $user, array $variants): array
{
    $discount = match (true) {
        mt_rand(1, 100) > RIG_DISCOUNT_RATE => [null, 0.0],
        mt_rand(1, 2) === 1 => ['percentage', (float) [5, 10, 15][mt_rand(0, 2)]],
        default => ['amount', (float) [10, 20, 50][mt_rand(0, 2)]],
    };

    return [
        'kind' => 'sale',
        'at' => $at,
        'user' => $user,
        'payment_type' => (string) rigPick(RIG_PAYMENT_WEIGHTS),
        'discount_type' => $discount[0],
        'discount_input' => $discount[1],
        'comment' => mt_rand(1, 100) <= RIG_COMMENT_RATE
            ? RIG_COMMENTS[mt_rand(0, count(RIG_COMMENTS) - 1)]
            : null,
        'lines' => rigLines($variants),
    ];
}

/**
 * A return or a defect against one sale, or null — most sales have neither, and
 * some cannot have one at all.
 *
 * @param  list<array<string, mixed>>  $sales
 * @param  list<array{price: int, cost: int, weight: int, final: int}>  $variants
 * @param  list<array{id: string, weight: int, role: string}>  $users
 * @param  string|null  $nightUser  Whoever works this branch's nights, so a night they
 *                                  are on cannot also hand them an afternoon.
 * @return array<string, mixed>|null
 */
function rigAfterSale(array $sales, int $ordinal, array $variants, array $users, ?string $nightUser, DateTimeImmutable $end): ?array
{
    if (mt_rand(1, 100) > RIG_RETURN_RATE) {
        return null;
    }

    $at = $sales[$ordinal]['at']
        ->modify('+'.mt_rand(1, 5).' days')
        ->setTime((int) rigPick(RIG_HOUR_WEIGHTS), mt_rand(0, 59));

    // A return of a sale from four days ago, four days from now, is not a thing
    // that has happened yet.
    if ($at > $end) {
        return null;
    }

    // Never against a variant this branch ends the year with none of: putting
    // stock back after the last movement of a line that finishes at zero is the
    // one way this generator can produce a negative running snapshot. See the
    // file docblock.
    $candidates = array_values(array_filter(
        array_keys($sales[$ordinal]['lines']),
        static fn (int $line): bool => $variants[$sales[$ordinal]['lines'][$line]['v']]['final'] > 0,
    ));

    if ($candidates === []) {
        return null;
    }

    $line = $candidates[mt_rand(0, count($candidates) - 1)];
    $sold = $sales[$ordinal]['lines'][$line]['qty'];
    $isReturn = mt_rand(1, 100) <= RIG_RETURN_SHARE;

    return [
        'kind' => $isReturn ? 'return' : 'defect',
        'at' => $at,
        // Always rung in daylight (the hours above), so the day rota applies
        // unchanged: on a night they are working, the night person is not here
        // in the afternoon to hand a refund over.
        'user' => rigPickUser($users, $nightUser !== null
            && in_array((int) $at->format('N'), RIG_NIGHT_NIGHTS, true) ? $nightUser : null),
        // The sale's ORDINAL, not its position in the merged list — the two stop
        // agreeing the moment these events are folded in. It indexes the sales in
        // the order they are written, which is the order they were sorted into.
        'sale' => $ordinal,
        'line' => $line,
        'v' => $sales[$ordinal]['lines'][$line]['v'],
        // Whole line most of the time, so cumulative refunds land exactly on the
        // line's paid share the way ProcessSaleReturn::lineRefund() guarantees.
        'quantity' => ($sold > 1 && mt_rand(1, 100) > 70) ? mt_rand(1, $sold - 1) : $sold,
        'reason' => $isReturn
            ? RIG_RETURN_REASONS[mt_rand(0, count(RIG_RETURN_REASONS) - 1)]
            : RIG_DEFECT_REASONS[mt_rand(0, count(RIG_DEFECT_REASONS) - 1)],
    ];
}

/**
 * The lines of one receipt: distinct variants, weighted by how well each sells.
 *
 * Distinct because the POS merges a repeated variant into one line, so two lines
 * of the same variant is a shape the app cannot produce — and it would make the
 * return arithmetic ambiguous.
 *
 * @param  list<array{price: int, cost: int, weight: int, final: int}>  $variants
 * @return list<array{v: int, qty: int}>
 */
function rigLines(array $variants): array
{
    $weights = [];

    foreach ($variants as $index => $variant) {
        $weights[$index] = $variant['weight'];
    }

    $lines = [];

    for ($i = 0, $n = min((int) rigPick(RIG_LINE_WEIGHTS), count($weights)); $i < $n; $i++) {
        $index = (int) rigPick($weights);
        unset($weights[$index]);

        $lines[] = ['v' => $index, 'qty' => (int) rigPick(RIG_QTY_WEIGHTS)];
    }

    return $lines;
}

/**
 * The net signed movement the planned year makes, per variant index.
 *
 * What the caller subtracts from the closing figure to get the opening one.
 * Defects move nothing: the stock left when it was sold and the write-off is an
 * audit row of quantity 0 (CLAUDE.md — "defects do not restore stock").
 *
 * @param  list<array<string, mixed>>  $events
 * @return array<int, int>
 */
function rigNetMovements(array $events): array
{
    $net = [];

    foreach ($events as $event) {
        if ($event['kind'] === 'sale') {
            foreach ($event['lines'] as $line) {
                $net[$line['v']] = ($net[$line['v']] ?? 0) - $line['qty'];
            }
        } elseif ($event['kind'] === 'return') {
            $net[$event['v']] = ($net[$event['v']] ?? 0) + $event['quantity'];
        }
    }

    return $net;
}

/**
 * Write a planned year, and return where each variant's stock actually ended.
 *
 * The caller compares that against the figure it sized the opening stock from;
 * they agree by construction, and the assertion is there because "by
 * construction" is a claim about code that can be edited.
 *
 * @param  array{id: string, code: string, per_day: float}  $branch
 * @param  list<array<string, mixed>>  $events
 * @param  list<array{price: int, cost: int, weight: int, final: int}>  $variants
 * @param  list<string>  $variantIds  Indexed as $variants.
 * @param  array<int, int>  $opening  Variant index => opening quantity.
 * @return array<int, int> Variant index => closing quantity.
 */
function rigWriteYear(PDO $pdo, array $branch, array $events, array $variants, array $variantIds, array $opening): array
{
    $buffers = array_map(static fn (): array => [], RIG_COLUMNS);

    $stock = $opening;
    $serial = 0;
    $returnCounters = [];
    $sold = [];

    foreach ($events as $event) {
        if ($event['kind'] === 'sale') {
            $sold[] = rigWriteSale($branch, $event, $variants, $variantIds, ++$serial, $stock, $buffers);
        } else {
            rigWriteAfterSale($branch, $event, $sold[$event['sale']], $variantIds, $returnCounters, $stock, $buffers);
        }

        if (count($buffers['inventory_ledger']) >= RIG_INSERT_CHUNK) {
            rigFlush($pdo, $buffers);
        }
    }

    rigFlush($pdo, $buffers);

    return $stock;
}

/**
 * One sale: its header, its lines, and one ledger row per line.
 *
 * @param  array{id: string, code: string, per_day: float}  $branch
 * @param  array<string, mixed>  $event
 * @param  list<array{price: int, cost: int, weight: int, final: int}>  $variants
 * @param  list<string>  $variantIds
 * @param  array<int, int>  $stock
 * @param  array<string, list<list<mixed>>>  $buffers
 * @return array{id: string, payment_type: string, lines: list<array{id: string, v: int, qty: int, share: int, price: int, cost: int}>}
 */
function rigWriteSale(array $branch, array $event, array $variants, array $variantIds, int $serial, array &$stock, array &$buffers): array
{
    $at = rigStamp($event['at']);
    $id = uuid();

    // PosController::store, in the same order and with the same caps.
    $subtotal = 0;

    foreach ($event['lines'] as $line) {
        $subtotal += $variants[$line['v']]['price'] * $line['qty'];
    }

    $discount = match ($event['discount_type']) {
        'percentage' => (int) round($subtotal * min($event['discount_input'], 100) / 100),
        'amount' => min((int) round($event['discount_input'] * 100), $subtotal),
        default => 0,
    };

    $total = $subtotal - $discount;

    $cashReceived = null;
    $change = null;

    if ($event['payment_type'] === 'cash') {
        // What a customer actually hands over: the exact amount, or the next
        // round note up.
        $cashReceived = match (rigPick([1 => 30, 2 => 45, 3 => 25])) {
            1 => $total,
            2 => (int) ceil($total / 5000) * 5000,
            default => (int) ceil($total / 10000) * 10000,
        };

        $change = $cashReceived - $total;
    }

    $buffers['transactions'][] = [
        $id,
        $branch['id'],
        sprintf('%s-%010d', $branch['code'], $serial),
        $event['payment_type'],
        // The percent is what a percentage discount stores; a peso amount stores
        // the pesos. Both go in the same column, which is why discount_type is
        // null whenever nothing was applied.
        $discount > 0 && $event['discount_type'] === 'percentage'
            ? number_format($event['discount_input'], 2, '.', '')
            : rigPesos($discount),
        $discount > 0 ? $event['discount_type'] : null,
        rigPesos($total),
        $cashReceived === null ? null : rigPesos($cashReceived),
        $change === null ? null : rigPesos($change),
        $event['user'],
        $event['comment'],
        $at,
    ];

    $lines = [];
    $assigned = 0;
    $last = count($event['lines']) - 1;

    foreach ($event['lines'] as $index => $line) {
        $variant = $variants[$line['v']];
        $lineTotal = $variant['price'] * $line['qty'];
        $itemId = uuid();

        // The line's share of what was actually paid, with the final line
        // absorbing the remainder — ProcessSaleReturn::paidShares(), because
        // that is what a refund is a proportion of.
        if ($index === $last) {
            $share = $total - $assigned;
        } else {
            $share = $subtotal > 0 ? (int) round($total * $lineTotal / $subtotal) : 0;
            $assigned += $share;
        }

        $buffers['transaction_items'][] = [
            $itemId,
            $id,
            $variantIds[$line['v']],
            $line['qty'],
            rigPesos($variant['price']),
            rigPesos($variant['cost']),
            // A discount is a property of the sale, never of a line.
            '0.00',
            rigPesos($lineTotal),
            $at,
        ];

        $buffers['inventory_ledger'][] = rigLedgerRow(
            $branch, $line['v'], $variantIds, -$line['qty'], $stock,
            'sale', 'transaction', $id, $event['user'], $at,
        );

        $lines[] = [
            'id' => $itemId,
            'v' => $line['v'],
            'qty' => $line['qty'],
            'share' => $share,
            // Carried, not looked up again: a return snapshots the price and cost
            // the line was SOLD at, never the variant's current values.
            'price' => $variant['price'],
            'cost' => $variant['cost'],
        ];
    }

    return ['id' => $id, 'payment_type' => $event['payment_type'], 'lines' => $lines];
}

/**
 * One return or defect against an already-written sale.
 *
 * @param  array{id: string, code: string, per_day: float}  $branch
 * @param  array<string, mixed>  $event
 * @param  array{id: string, payment_type: string, lines: list<array{id: string, v: int, qty: int, share: int, price: int, cost: int}>}  $sale
 * @param  list<string>  $variantIds
 * @param  array<string, int>  $counters
 * @param  array<int, int>  $stock
 * @param  array<string, list<list<mixed>>>  $buffers
 */
function rigWriteAfterSale(array $branch, array $event, array $sale, array $variantIds, array &$counters, array &$stock, array &$buffers): void
{
    $at = rigStamp($event['at']);
    $id = uuid();
    $line = $sale['lines'][$event['line']];
    $isReturn = $event['kind'] === 'return';

    // RET-YYYYMMDD-000001: a per-day sequence, per branch, exactly as
    // SaleReturnNumberGenerator mints it. No branch prefix — Postgres scopes
    // uniqueness to (branch_id, return_number) and two branches issuing the same
    // number on the same day is expected.
    $day = $event['at']->format('Ymd');
    $counters[$day] = ($counters[$day] ?? 0) + 1;

    $refund = $event['quantity'] === $line['qty']
        ? $line['share']
        : (int) round($line['share'] * $event['quantity'] / $line['qty']);

    $buffers['sale_returns'][] = [
        $id,
        $branch['id'],
        sprintf('RET-%s-%06d', $day, $counters[$day]),
        $sale['id'],
        $event['kind'],
        $event['reason'],
        // No money goes back for a defect, so a payment type would be a lie. The
        // written-off value is still recorded, and reports still subtract it.
        $isReturn ? $sale['payment_type'] : null,
        rigPesos($refund),
        $event['user'],
        $at,
    ];

    $buffers['sale_return_items'][] = [
        uuid(),
        $id,
        $line['id'],
        $variantIds[$line['v']],
        $event['quantity'],
        rigPesos($line['price']),
        rigPesos($line['cost']),
        rigPesos($refund),
        $at,
    ];

    $buffers['inventory_ledger'][] = rigLedgerRow(
        $branch, $line['v'], $variantIds,
        // A return restores stock; a defect writes a 0-movement audit row.
        $isReturn ? $event['quantity'] : 0,
        $stock, $event['kind'], 'return', $id, $event['user'], $at,
    );
}

/**
 * One ledger row, advancing the running snapshot it reports.
 *
 * The only place `resulting_snapshot` is computed, and the only place the year
 * can be caught driving stock negative — which upstream is a check constraint
 * and downstream is a branch that can never sync again.
 *
 * @param  array{id: string, code: string, per_day: float}  $branch
 * @param  list<string>  $variantIds
 * @param  array<int, int>  $stock
 * @return list<mixed>
 */
function rigLedgerRow(
    array $branch,
    int $index,
    array $variantIds,
    int $quantity,
    array &$stock,
    string $saleType,
    string $referenceType,
    string $referenceId,
    string $userId,
    string $at,
): array {
    $stock[$index] += $quantity;

    if ($stock[$index] < 0) {
        throw new RuntimeException(
            "The planned year drives {$variantIds[$index]} to {$stock[$index]} at branch {$branch['code']}. "
            .'Opening stock was sized from the same events, so this is a generator bug, not a data one.'
        );
    }

    return [
        uuid(),
        $branch['id'],
        $variantIds[$index],
        $quantity,
        $stock[$index],
        'sale',
        $saleType,
        $referenceId,
        $referenceType,
        null,
        $userId,
        $at,
    ];
}

/**
 * Write every buffer and empty it, parents first.
 *
 * @param  array<string, list<list<mixed>>>  $buffers
 */
function rigFlush(PDO $pdo, array &$buffers): void
{
    foreach (RIG_COLUMNS as $table => $columns) {
        if ($buffers[$table] === []) {
            continue;
        }

        $row = '('.implode(',', array_fill(0, count($columns), '?')).')';

        $sql = 'insert into '.quoteIdentifier($table)
            .' ('.implode(',', array_map('quoteIdentifier', $columns)).') values '
            .implode(',', array_fill(0, count($buffers[$table]), $row));

        $pdo->prepare($sql)->execute(array_merge(...$buffers[$table]));

        $buffers[$table] = [];
    }
}

/**
 * A `timestamptz` literal carrying its offset.
 *
 * The offset is the load-bearing part: without it Postgres reads the string in
 * the session's timezone, which is UTC, and the whole year lands eight hours
 * early — consistently enough to sort correctly, hydrate cleanly and report
 * wrong. Whole seconds because every timestamp column here is
 * `timestamp(0) with time zone`.
 */
function rigStamp(DateTimeImmutable $at): string
{
    return $at->format('Y-m-d H:i:sP');
}

/** Centavos as a `decimal(12,2)` literal. Integer arithmetic throughout, so no float drift. */
function rigPesos(int $centavos): string
{
    return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
}

/**
 * One key from a weight map, drawn in proportion.
 *
 * @param  array<int|string, int>  $weights
 */
function rigPick(array $weights): int|string
{
    $roll = mt_rand(1, max(1, array_sum($weights)));

    foreach ($weights as $key => $weight) {
        $roll -= $weight;

        if ($roll <= 0) {
            return $key;
        }
    }

    return array_key_first($weights);
}

/**
 * One of this branch's people, weighted by how much of the till they work.
 *
 * $exclude is the night rota: the person on tonight is not also on today. It
 * costs no extra draw either way — {@see rigPick()} takes exactly one whatever
 * the map holds — so excluding nobody leaves the stream where it was.
 *
 * @param  list<array{id: string, weight: int, role: string}>  $users
 */
function rigPickUser(array $users, ?string $exclude = null): string
{
    $weights = [];

    foreach ($users as $index => $user) {
        if ($user['id'] !== $exclude) {
            $weights[$index] = $user['weight'];
        }
    }

    // A branch with nobody left on days is not a roster pg.php can produce, but
    // rigPick() on an empty map returns a null key and the fatal lands two
    // frames away from the cause.
    if ($weights === []) {
        return $users[0]['id'];
    }

    return $users[rigPick($weights)]['id'];
}

/**
 * The person who works this branch's nights: its cashier.
 *
 * Null when the branch has none, which pg.php's roster never produces — the
 * caller then simply plans no night trading rather than pressing the Owner into
 * a 20:00 start.
 *
 * @param  list<array{id: string, weight: int, role: string}>  $users
 */
function rigNightUser(array $users): ?string
{
    foreach ($users as $user) {
        if ($user['role'] === 'cashier') {
            return $user['id'];
        }
    }

    return null;
}
