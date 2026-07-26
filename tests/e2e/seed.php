<?php

/**
 * E2E fixture seed — executed by tests/e2e/serve.sh (via artisan tinker)
 * against the ISOLATED runtime database, never the real one.
 *
 * Runs on top of `migrate --seed`, which has already created the settings
 * defaults and (locally) DemoDataSeeder's catalog, sales history and the
 * `owner`/`password` dev login. This file adds only what the specs pin on:
 * the per-role logins and one deterministic fixture product whose arithmetic
 * commerce.spec.ts asserts to the centavo.
 *
 * Logins (password "password"): owner (Owner), e2e-admin (Admin),
 * e2e-cashier (Cashier).
 * Fixture product: "E2E Smoke Coffee", unnamed variant, SKU E2ESMOKE,
 * ₱25.00 / cost ₱10.00, stock 50 — seeded through the ledger.
 *
 * Prints "e2e seed complete" ONLY after verifying its own work: tinker exits 0
 * even when this file throws, so that marker is the only signal serve.sh can
 * trust.
 */

use App\Enums\UserRole;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;

$admin = User::factory()->admin()->create(['username' => 'e2e-admin', 'name' => 'E2E Admin']);
User::factory()->cashier()->create(['username' => 'e2e-cashier', 'name' => 'E2E Cashier']);

// firstOrCreate, not firstOrFail: there is exactly one Owner at most, and
// whether it already exists depends on whether DemoDataSeeder ran. Creating a
// second Owner is blocked by the model guard, so reuse is mandatory.
$owner = User::query()->where('role', UserRole::Owner)->first()
    ?? User::factory()->owner()->create(['username' => 'owner']);

$owner->password = 'password';
$owner->name = 'E2E Owner';
$owner->save();

$item = Item::factory()->create(['name' => 'E2E Smoke Coffee']);
$variant = Variant::factory()->create([
    'item_id' => $item->id,
    'name' => null,
    'sku' => 'E2ESMOKE',
    'barcode' => null,
    'selling_price' => '25.00',
    'cost' => '10.00',
]);

app(StockLedger::class)->record($variant, 50, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

// Assert the fixtures the specs depend on, so the marker below means the
// database is actually usable — not merely that the script reached the end.
$checks = [
    'owner login' => User::query()->where('username', 'owner')->where('role', UserRole::Owner)->exists(),
    'admin login' => User::query()->where('username', 'e2e-admin')->exists(),
    'cashier login' => User::query()->where('username', 'e2e-cashier')->exists(),
    'fixture variant' => Variant::query()->where('sku', 'E2ESMOKE')->exists(),
    'fixture stock' => $variant->fresh()->stockSnapshot?->quantity === 50,
];

foreach ($checks as $label => $ok) {
    if (! $ok) {
        throw new RuntimeException("e2e seed check failed: {$label}");
    }
}

echo "e2e seed complete\n";
