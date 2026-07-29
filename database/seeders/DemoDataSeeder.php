<?php

namespace Database\Seeders;

use App\Enums\LoginEventType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Item;
use App\Models\LoginHistory;
use App\Models\SaleReturn;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\StockLedger;
use App\Support\Sales\ProcessSaleReturn;
use App\Support\Sales\TransactionNumberGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Rich, invariant-respecting demo data for development: a retail catalog with
 * stock, ~6 weeks of sales, returns/defects, login history and audit trails.
 *
 * Everything funnels through the real write paths — StockLedger for every
 * stock movement, TransactionNumberGenerator for the gapless serial,
 * ProcessSaleReturn for after-sales events — so seeded history obeys the same
 * rules as live data. History is backdated with Carbon::setTestNow, which
 * keeps created_at, ledger order, serial order and daily return sequences
 * mutually coherent.
 *
 * Skips itself when catalog or sales data already exists (append-only tables
 * must not be doubled up); `migrate:fresh --seed` gives a clean slate.
 */
class DemoDataSeeder extends Seeder
{
    /** How far back the seeded history starts. */
    private const HISTORY_DAYS = 45;

    private User $owner;

    private User $admin;

    private User $cashier;

    /** All created variants keyed by SKU. @var array<string, Variant> */
    private array $variants = [];

    /** Opening stock per SKU, written to the ledger after the catalog. @var array<string, int> */
    private array $openingStock = [];

    /** Variants the sales loop may sell. @var Collection<int, Variant> */
    private Collection $sellable;

    /** Past sales still eligible for a return/defect. @var Collection<int, Transaction> */
    private Collection $returnableSales;

    /**
     * The wall clock at the start of the run, captured before the first
     * `setTestNow` — after that, `now()` is whichever backdated instant the
     * seeder is currently pretending it is. Nothing seeded for TODAY may be
     * stamped later than this.
     */
    private Carbon $realNow;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly TransactionNumberGenerator $numbers,
        private readonly ProcessSaleReturn $afterSales,
        private readonly AuditRecorder $audit,
    ) {
        $this->sellable = new Collection;
        $this->returnableSales = new Collection;
    }

    public function run(): void
    {
        if (Item::query()->exists() || Transaction::query()->exists()) {
            $this->command?->warn('Demo data skipped: catalog or sales data already exists.');

            return;
        }

        $today = today();
        $start = $today->copy()->subDays(self::HISTORY_DAYS);
        $this->realNow = now();

        try {
            Carbon::setTestNow($start->copy()->setTime(7, 30));
            $this->seedUsers();

            Carbon::setTestNow($start->copy()->setTime(8, 0));
            $this->seedCatalog();
            $this->seedOpeningStock();

            $this->seedHistory($start, $today);
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info(sprintf(
            'Demo data: %d items, %d variants, %d sales, %d after-sales events.',
            Item::query()->count(),
            Variant::query()->count(),
            Transaction::query()->count(),
            SaleReturn::query()->count(),
        ));
    }

    /**
     * The demo accounts, including the dev login owner/password. A production
     * install never runs this — its Owner is created by the setup wizard.
     * Never creates a second Owner.
     */
    private function seedUsers(): void
    {
        $this->owner = User::firstOrCreate(
            ['username' => 'owner'],
            ['name' => 'Owner', 'password' => Hash::make('password'), 'role' => UserRole::Owner],
        );

        $this->admin = User::firstOrCreate(
            ['username' => 'admin'],
            ['name' => 'Amelia Santos', 'password' => Hash::make('password'), 'role' => UserRole::Admin],
        );

        $this->cashier = User::firstOrCreate(
            ['username' => 'cashier'],
            ['name' => 'Carlo Mendoza', 'password' => Hash::make('password'), 'role' => UserRole::Cashier],
        );
    }

    private function seedCatalog(): void
    {
        foreach ($this->catalog() as $categoryName => $items) {
            $category = Category::create(['name' => $categoryName]);

            foreach ($items as $spec) {
                $this->createItem($category, $spec);
            }
        }

        // An archived category, so the Archived filter has something to show.
        Category::create(['name' => 'Seasonal', 'archived_at' => now()]);
    }

    /**
     * @param  array{name: string, unit: string, description?: string, archived?: bool, variants: list<array<string, mixed>>}  $spec
     */
    private function createItem(Category $category, array $spec): void
    {
        $item = Item::create([
            'name' => $spec['name'],
            'unit' => $spec['unit'],
            'category_id' => $category->getKey(),
            'description' => $spec['description'] ?? null,
            'archived_at' => ($spec['archived'] ?? false) ? now() : null,
        ]);

        foreach ($spec['variants'] as $v) {
            $variant = Variant::create([
                'item_id' => $item->getKey(),
                'name' => $v['name'] ?? null,
                'sku' => $v['sku'],
                'barcode' => ($v['barcode'] ?? true) ? fake()->unique()->ean13() : null,
                'selling_price' => $v['price'],
                'cost' => $v['cost'],
                'low_stock_threshold' => $v['threshold'] ?? 10,
                'archived_at' => ($v['archived'] ?? false) ? now() : null,
            ]);

            $this->variants[$v['sku']] = $variant;
            $this->openingStock[$v['sku']] = $v['stock'];

            $itemArchived = $spec['archived'] ?? false;
            $variantArchived = $v['archived'] ?? false;

            if ($v['stock'] > 0 && ! $itemArchived && ! $variantArchived && ($v['sellable'] ?? true)) {
                $this->sellable->push($variant);
            }
        }
    }

    private function seedOpeningStock(): void
    {
        foreach ($this->variants as $sku => $variant) {
            $stock = $this->openingStock[$sku];

            if ($stock <= 0) {
                continue;
            }

            $this->ledger->record(
                variant: $variant,
                quantity: $stock,
                type: 'stock_adjustment',
                saleType: null,
                referenceType: 'manual_adjustment',
                referenceId: null,
                performedBy: $this->owner,
                notes: 'Opening stock',
            );
        }
    }

    /**
     * Day-by-day history, oldest first, so the gapless transaction serial and
     * every ledger row line up with their backdated timestamps.
     */
    private function seedHistory(Carbon $start, Carbon $today): void
    {
        for ($offset = 1; $offset <= self::HISTORY_DAYS; $offset++) {
            $day = $start->copy()->addDays($offset);
            $isToday = $day->isSameDay($today);

            $this->seedLogins($day, $isToday, crashDay: $offset === 10);

            if ($offset === 18) {
                $this->restock($day);
            }

            if ($offset === 30) {
                $this->stockCorrection($day);
            }

            // A morning-only trickle today, a full trading day otherwise.
            $count = match (true) {
                $isToday => 2,
                $day->isWeekend() => fake()->numberBetween(5, 9),
                default => fake()->numberBetween(3, 7),
            };

            foreach ($this->saleTimes($day, $count, $isToday) as $at) {
                $this->makeSale($at);
            }

            // Occasional after-sales event against an earlier day's sale.
            if ($offset >= 3 && ! $isToday && fake()->boolean(18)) {
                $this->makeAfterSalesEvent($day->copy()->setTime(19, 45, fake()->numberBetween(0, 59)));
            }
        }
    }

    /**
     * A morning trickle today, a full trading day otherwise — and today's
     * window closes at the real clock, never at a fixed hour.
     *
     * Picking an hour in 8–10 regardless of the time of day seeds sales into
     * the FUTURE whenever the seeder runs before 11:00. Everything that orders
     * by `created_at` then ranks demo data above a sale the operator has just
     * made: the Sales list opens on a seeded row, and `commerce.spec.ts` —
     * which clicks "the newest row" — asserts against the wrong receipt. It
     * fails in the morning and passes after lunch, which is why it survived
     * this long.
     *
     * @return list<Carbon>
     */
    private function saleTimes(Carbon $day, int $count, bool $isToday): array
    {
        $opens = $day->copy()->setTime(8, 0);
        $closes = $day->copy()->setTime($isToday ? 10 : 19, 59, 59);

        if ($isToday && $closes->gt($this->realNow)) {
            $closes = $this->realNow->copy();
        }

        // Seeded before the shop opens: today simply has no sales yet.
        if ($closes->lt($opens)) {
            return [];
        }

        $window = (int) $opens->diffInSeconds($closes);
        $times = [];

        for ($i = 0; $i < $count; $i++) {
            $times[] = $opens->copy()->addSeconds(fake()->numberBetween(0, $window));
        }

        usort($times, fn (Carbon $a, Carbon $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $times;
    }

    /**
     * One sale through the same shape as PosController::persistSale — money in
     * integer centavos, price/cost snapshotted from the variant, stock deducted
     * via the ledger, and an audit entry. Skips silently if nothing is in stock.
     */
    private function makeSale(Carbon $at): void
    {
        Carbon::setTestNow($at);

        $lines = [];

        foreach ($this->sellable->shuffle()->take(fake()->numberBetween(1, 3)) as $variant) {
            $available = $this->ledger->currentStock($variant);

            if ($available < 1) {
                continue;
            }

            $quantity = min(fake()->numberBetween(1, 3), $available);
            $unit = $this->centavos($variant->selling_price);

            $lines[] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'line_total' => $unit * $quantity,
            ];
        }

        if ($lines === []) {
            return;
        }

        $subtotal = array_sum(array_column($lines, 'line_total'));

        // Mirror checkout's stored-discount rules: percentage keeps the percent
        // entered, amount keeps the effective (capped) pesos, null when none.
        [$discount, $discountType, $storedDiscount] = $this->rollDiscount($subtotal);
        $total = $subtotal - $discount;

        $paymentRoll = fake()->numberBetween(1, 100);
        $paymentType = match (true) {
            $paymentRoll <= 70 => 'cash',
            $paymentRoll <= 95 => 'cashless',
            default => 'others',
        };

        $cashReceived = null;
        $change = null;

        if ($paymentType === 'cash') {
            // Exact tender sometimes, otherwise rounded up to the next ₱50.
            $cashReceived = fake()->boolean(30) ? $total : (int) (ceil($total / 50_00) * 50_00);
            $change = $cashReceived - $total;
        }

        $seller = $this->rollSeller();

        $transaction = DB::transaction(function () use ($lines, $paymentType, $storedDiscount, $discountType, $total, $cashReceived, $change, $seller) {
            $transaction = Transaction::create([
                'transaction_number' => $this->numbers->next(),
                'payment_type' => $paymentType,
                'discount' => $storedDiscount,
                'discount_type' => $discountType,
                'total' => $this->pesos($total),
                'cash_received' => $cashReceived !== null ? $this->pesos($cashReceived) : null,
                'change' => $change !== null ? $this->pesos($change) : null,
                'comment' => fake()->boolean(5) ? fake()->randomElement([
                    'Suki — reserved items picked up.',
                    'Customer paid in coins.',
                    'For delivery to Sitio Uno.',
                ]) : null,
                'user_id' => $seller->getKey(),
            ]);

            foreach ($lines as $line) {
                /** @var Variant $variant */
                $variant = $line['variant'];

                TransactionItem::create([
                    'transaction_id' => $transaction->getKey(),
                    'variant_id' => $variant->getKey(),
                    'quantity' => $line['quantity'],
                    'selling_price' => $variant->selling_price,
                    'cost' => $variant->cost,
                    'discount' => '0.00',
                    'total' => $this->pesos($line['line_total']),
                ]);

                $this->ledger->record(
                    variant: $variant,
                    quantity: -$line['quantity'],
                    type: 'sale',
                    saleType: 'sale',
                    referenceType: 'transaction',
                    referenceId: $transaction->getKey(),
                    performedBy: $seller,
                );
            }

            return $transaction;
        });

        $this->audit->record(
            action: 'pos.sale.created',
            auditable: $transaction,
            description: "Recorded sale {$transaction->transaction_number}.",
            new: [
                'transaction_number' => $transaction->transaction_number,
                'total' => $transaction->total,
            ],
            actor: $seller,
        );

        $this->returnableSales->push($transaction);
    }

    /**
     * A return or defect against a random earlier sale, through the real
     * ProcessSaleReturn service (quota, discount-aware refund, ledger, audit).
     */
    private function makeAfterSalesEvent(Carbon $at): void
    {
        Carbon::setTestNow($at);

        $candidates = $this->returnableSales->filter(
            fn (Transaction $sale) => $sale->created_at->lt($at->copy()->startOfDay()),
        );

        if ($candidates->isEmpty()) {
            return;
        }

        /** @var Transaction $sale */
        $sale = $candidates->random();
        $this->returnableSales = $this->returnableSales->reject(fn (Transaction $t) => $t->is($sale));

        $soldLine = $sale->items()->inRandomOrder()->first();

        if ($soldLine === null) {
            return;
        }

        $isDefect = fake()->boolean(35);

        $this->afterSales->process(
            transaction: $sale,
            type: $isDefect ? 'defect' : 'return',
            lines: [[
                'transaction_item_id' => $soldLine->getKey(),
                'quantity' => fake()->numberBetween(1, min(2, $soldLine->quantity)),
            ]],
            reason: fake()->randomElement($isDefect
                ? ['Leaking pack', 'Crushed packaging', 'Expired stock discovered']
                : ['Customer changed their mind', 'Bought the wrong size', 'Duplicate purchase']),
            refundPaymentType: 'cash',
            performedBy: $isDefect ? $this->admin : $this->cashier,
        );
    }

    /**
     * A mid-history delivery topping up the fast movers.
     */
    private function restock(Carbon $day): void
    {
        Carbon::setTestNow($day->copy()->setTime(10, 0));

        $delivery = [
            'BEV-COLA-330' => 72,
            'BEV-WATR-500' => 96,
            'BEV-KAPE-3N1' => 120,
            'PCR-SHAM-12' => 100,
        ];

        foreach ($delivery as $sku => $quantity) {
            $this->ledger->record(
                variant: $this->variants[$sku],
                quantity: $quantity,
                type: 'stock_adjustment',
                saleType: null,
                referenceType: 'manual_adjustment',
                referenceId: null,
                performedBy: $this->admin,
                notes: 'Restock delivery',
            );
        }
    }

    /**
     * A negative manual adjustment, so stock history shows a correction.
     */
    private function stockCorrection(Carbon $day): void
    {
        Carbon::setTestNow($day->copy()->setTime(16, 30));

        $this->ledger->record(
            variant: $this->variants['SNK-CHIP-S'],
            quantity: -2,
            type: 'stock_adjustment',
            saleType: null,
            referenceType: 'manual_adjustment',
            referenceId: null,
            performedBy: $this->admin,
            notes: 'Stock count correction — two damaged packs pulled from the shelf',
        );
    }

    private function seedLogins(Carbon $day, bool $isToday, bool $crashDay): void
    {
        $this->logAuth($this->cashier, LoginEventType::Login, $day->copy()->setTime(7, fake()->numberBetween(50, 59)));

        if ($crashDay) {
            $crashAt = $day->copy()->setTime(13, 47);
            $this->logAuth($this->cashier, LoginEventType::ApplicationCrash, $crashAt);
            $this->logAuth($this->cashier, LoginEventType::Login, $crashAt->copy()->addMinutes(5));
        }

        if (fake()->boolean(50)) {
            $inAt = $day->copy()->setTime(9, fake()->numberBetween(0, 30));
            $this->logAuth($this->admin, LoginEventType::Login, $inAt);

            if (! $isToday) {
                $this->logAuth($this->admin, LoginEventType::Logout, $day->copy()->setTime(17, fake()->numberBetween(0, 30)));
            }
        }

        if (fake()->boolean(25)) {
            $this->logAuth($this->owner, LoginEventType::Login, $day->copy()->setTime(18, fake()->numberBetween(0, 20)));

            if (! $isToday) {
                $this->logAuth($this->owner, LoginEventType::Logout, $day->copy()->setTime(18, fake()->numberBetween(30, 55)));
            }
        }

        if (! $isToday) {
            $this->logAuth($this->cashier, LoginEventType::Logout, $day->copy()->setTime(20, fake()->numberBetween(0, 15)));
        }
    }

    /**
     * Today's fixed clock times (an 09:xx admin login, an 18:xx owner login)
     * are in the future for most of the working day, and login history is
     * append-only — a row that has not happened yet cannot be corrected later.
     * An event past the wall clock is simply one that has not happened.
     */
    private function logAuth(User $user, LoginEventType $type, Carbon $at): void
    {
        if ($at->gt($this->realNow)) {
            return;
        }

        Carbon::setTestNow($at);

        LoginHistory::create([
            'user_id' => $user->getKey(),
            'event_type' => $type,
            'occurred_at' => $at,
            'notes' => null,
        ]);
    }

    /**
     * @return array{0: int, 1: ?string, 2: string} [discount centavos, stored type, stored discount]
     */
    private function rollDiscount(int $subtotal): array
    {
        $roll = fake()->numberBetween(1, 100);

        if ($roll <= 10) {
            $percent = fake()->randomElement([5, 10]);
            $discount = (int) round($subtotal * $percent / 100);

            if ($discount > 0) {
                return [$discount, 'percentage', number_format($percent, 2, '.', '')];
            }
        } elseif ($roll <= 18) {
            $discount = min(fake()->randomElement([10_00, 20_00, 50_00]), $subtotal);

            if ($discount > 0) {
                return [$discount, 'amount', $this->pesos($discount)];
            }
        }

        return [0, null, '0.00'];
    }

    private function rollSeller(): User
    {
        $roll = fake()->numberBetween(1, 100);

        return match (true) {
            $roll <= 70 => $this->cashier,
            $roll <= 90 => $this->admin,
            default => $this->owner,
        };
    }

    /**
     * The demo catalog: a small-format Philippine retail store. Deliberately
     * exercises every catalog shape — multi-variant and single-variant
     * (unnamed) items, a costless variant, a barcode-less variant, low-stock
     * and never-stocked products, and archived rows at every level.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function catalog(): array
    {
        return [
            'Beverages' => [
                ['name' => 'Cola Soda', 'unit' => 'bottle', 'variants' => [
                    ['name' => '330mL Can', 'sku' => 'BEV-COLA-330', 'price' => 25, 'cost' => 18, 'stock' => 150],
                    ['name' => '1L Bottle', 'sku' => 'BEV-COLA-1L', 'price' => 60, 'cost' => 45, 'stock' => 80],
                    // Archived variant on an active item.
                    ['name' => '2L Bottle (Old Label)', 'sku' => 'BEV-COLA-2L', 'price' => 95, 'cost' => 70, 'stock' => 0, 'archived' => true],
                ]],
                ['name' => 'Bottled Water', 'unit' => 'bottle', 'variants' => [
                    ['name' => '500mL', 'sku' => 'BEV-WATR-500', 'price' => 15, 'cost' => 8, 'stock' => 200],
                    ['name' => '1L', 'sku' => 'BEV-WATR-1L', 'price' => 25, 'cost' => 14, 'stock' => 120],
                ]],
                ['name' => '3-in-1 Coffee Sachet', 'unit' => 'sachet', 'variants' => [
                    ['sku' => 'BEV-KAPE-3N1', 'price' => 12, 'cost' => 8.5, 'stock' => 300],
                ]],
                ['name' => 'Iced Tea Powder 25g', 'unit' => 'sachet', 'variants' => [
                    ['sku' => 'BEV-TEA-25G', 'price' => 18, 'cost' => 12.5, 'stock' => 90],
                ]],
                // Archived item (still visible via the Archived filter and in history).
                ['name' => 'Energy Drink (Discontinued)', 'unit' => 'can', 'archived' => true, 'variants' => [
                    ['sku' => 'BEV-NRGY-250', 'price' => 45, 'cost' => 33, 'stock' => 0],
                ]],
            ],
            'Snacks' => [
                ['name' => 'Potato Chips', 'unit' => 'pack', 'variants' => [
                    ['name' => 'Small', 'sku' => 'SNK-CHIP-S', 'price' => 20, 'cost' => 14, 'stock' => 100],
                    ['name' => 'Party Size', 'sku' => 'SNK-CHIP-XL', 'price' => 95, 'cost' => 70, 'stock' => 40],
                ]],
                ['name' => 'Chocolate Bar', 'unit' => 'pc', 'variants' => [
                    ['sku' => 'SNK-CHOC-BAR', 'price' => 35, 'cost' => 26, 'stock' => 80],
                ]],
                // Barcode-less product: must still be sellable via SKU/search.
                ['name' => 'Soda Crackers', 'unit' => 'pack', 'variants' => [
                    ['sku' => 'SNK-CRKR-STD', 'price' => 8, 'cost' => 5, 'stock' => 150, 'barcode' => false],
                ]],
                // Kept out of the sales loop so it stays low-stock (3 < threshold 10).
                ['name' => 'Banana Chips', 'unit' => 'pack', 'variants' => [
                    ['sku' => 'SNK-BNNA-CHP', 'price' => 30, 'cost' => 21, 'stock' => 3, 'sellable' => false],
                ]],
            ],
            'Canned Goods' => [
                ['name' => 'Corned Beef', 'unit' => 'can', 'variants' => [
                    ['name' => '150g', 'sku' => 'CAN-CBEF-150', 'price' => 42, 'cost' => 33, 'stock' => 60],
                    ['name' => '380g', 'sku' => 'CAN-CBEF-380', 'price' => 95, 'cost' => 76, 'stock' => 30],
                ]],
                ['name' => 'Sardines in Tomato Sauce', 'unit' => 'can', 'variants' => [
                    ['sku' => 'CAN-SRDN-155', 'price' => 28, 'cost' => 21, 'stock' => 100],
                ]],
            ],
            'Household' => [
                ['name' => 'Dishwashing Liquid', 'unit' => 'bottle', 'variants' => [
                    ['name' => '250mL', 'sku' => 'HSH-DISH-250', 'price' => 35, 'cost' => 25, 'stock' => 45],
                    ['name' => '500mL', 'sku' => 'HSH-DISH-500', 'price' => 60, 'cost' => 44, 'stock' => 30],
                ]],
                ['name' => 'Powder Detergent', 'unit' => 'pack', 'variants' => [
                    ['name' => '500g', 'sku' => 'HSH-DTRG-500', 'price' => 45, 'cost' => 32, 'stock' => 50],
                    ['name' => '1kg', 'sku' => 'HSH-DTRG-1KG', 'price' => 82, 'cost' => 60, 'stock' => 35],
                ]],
                // Cost unknown: Owner-only cost column shows a blank, not a zero.
                ['name' => 'Trash Bags', 'unit' => 'roll', 'variants' => [
                    ['sku' => 'HSH-TRSH-ROL', 'price' => 55, 'cost' => null, 'stock' => 25],
                ]],
                // Never stocked: visible but disabled on the POS grid.
                ['name' => 'Butane Canister', 'unit' => 'can', 'variants' => [
                    ['sku' => 'HSH-BUTN-220', 'price' => 75, 'cost' => 58, 'stock' => 0],
                ]],
            ],
            'Personal Care' => [
                ['name' => 'Shampoo Sachet', 'unit' => 'sachet', 'variants' => [
                    ['sku' => 'PCR-SHAM-12', 'price' => 8, 'cost' => 5.5, 'stock' => 250],
                ]],
                ['name' => 'Bath Soap', 'unit' => 'pc', 'variants' => [
                    ['name' => 'Classic', 'sku' => 'PCR-SOAP-CLS', 'price' => 22, 'cost' => 16, 'stock' => 70],
                    ['name' => 'Whitening', 'sku' => 'PCR-SOAP-WHT', 'price' => 26, 'cost' => 19, 'stock' => 60],
                ]],
                ['name' => 'Toothpaste', 'unit' => 'pc', 'variants' => [
                    ['name' => 'Small', 'sku' => 'PCR-TPST-S', 'price' => 35, 'cost' => 26, 'stock' => 50],
                    ['name' => 'Family', 'sku' => 'PCR-TPST-FAM', 'price' => 89, 'cost' => 68, 'stock' => 30],
                ]],
            ],
        ];
    }

    private function centavos(string|float|int $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
