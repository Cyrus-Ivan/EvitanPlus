<?php

namespace Tests\Feature\Audit;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sales\ProcessSaleReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 15 — every significant write-path funnels one immutable audit_logs row
 * through AuditRecorder. These tests exercise the real controllers/services and
 * assert the row lands with the expected action.
 */
class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function sellable(float $price, ?float $cost, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);

        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    private function sell(User $cashier, Variant $variant, int $quantity): Transaction
    {
        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => $quantity]],
                'payment_type' => 'cashless',
                'cash_received' => null,
            ])
            ->assertSessionHasNoErrors();

        return Transaction::query()->orderByDesc('transaction_number')->firstOrFail();
    }

    public function test_user_lifecycle_actions_are_audited()
    {
        $owner = User::factory()->owner()->create();

        // created
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Jane Cashier',
            'username' => 'jane',
            'role' => UserRole::Cashier->value,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasNoErrors();

        $jane = User::where('username', 'jane')->firstOrFail();

        // role_changed
        $this->actingAs($owner)->patch(route('users.role', $jane), ['role' => UserRole::Admin->value])
            ->assertSessionHasNoErrors();

        // password_reset
        $this->actingAs($owner)->patch(route('users.reset-password', $jane), [
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertSessionHasNoErrors();

        // archived + restored
        $this->actingAs($owner)->patch(route('users.archive', $jane))->assertSessionHasNoErrors();
        $this->actingAs($owner)->patch(route('users.restore', $jane))->assertSessionHasNoErrors();

        foreach (['user.created', 'user.role_changed', 'user.password_reset', 'user.archived', 'user.restored'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'user_id' => $owner->id,
                'auditable_type' => (new User)->getMorphClass(),
                'auditable_id' => $jane->id,
            ]);
        }

        // The reset password is never captured in the payload.
        $reset = AuditLog::where('action', 'user.password_reset')->firstOrFail();
        $this->assertNull($reset->new_values);
        $this->assertNull($reset->old_values);

        // role_changed captures the old→new role.
        $roleChange = AuditLog::where('action', 'user.role_changed')->firstOrFail();
        $this->assertSame(['role' => UserRole::Cashier->value], $roleChange->old_values);
        $this->assertSame(['role' => UserRole::Admin->value], $roleChange->new_values);
    }

    public function test_category_creation_is_audited()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('categories.store'), ['name' => 'Drinks'])
            ->assertSessionHasNoErrors();

        $category = Category::where('name', 'Drinks')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.category.created',
            'user_id' => $admin->id,
            'auditable_id' => $category->id,
        ]);
    }

    public function test_item_creation_captures_price_and_cost()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Widget',
            'unit' => 'pc',
            'category_id' => null,
            'description' => null,
            'variants' => [
                ['name' => 'Default', 'sku' => 'W-1', 'barcode' => null, 'selling_price' => '10.00', 'low_stock_threshold' => 0, 'cost' => '3.50'],
            ],
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'inventory.item.created')->firstOrFail();
        $this->assertSame('10.00', $log->new_values['variants'][0]['selling_price']);
        $this->assertSame('3.50', $log->new_values['variants'][0]['cost']);
    }

    public function test_stock_adjustment_is_audited()
    {
        $admin = User::factory()->admin()->create();
        $variant = Variant::factory()->create();

        $this->actingAs($admin)->post(route('stock.adjust', $variant), [
            'mode' => 'add',
            'value' => 5,
            'notes' => 'restock',
        ])->assertSessionHasNoErrors();

        // `queued`, not `adjusted`: since schema 21 this screen asks for a stock
        // change rather than making one, and the audit trail says which of those
        // happened. Answering it is a separate row (`inventory.stock.resolved`).
        $log = AuditLog::where('action', 'inventory.stock.queued')->firstOrFail();
        $this->assertSame(5, $log->new_values['delta']);
    }

    public function test_sale_return_and_defect_are_audited()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $transaction = $this->sell($cashier, $variant, 3);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pos.sale.created',
            'user_id' => $cashier->id,
            'auditable_id' => $transaction->id,
        ]);

        $admin = User::factory()->admin()->create();

        app(ProcessSaleReturn::class)->process(
            $transaction, 'return',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            null, 'cashless', $admin,
        );
        app(ProcessSaleReturn::class)->process(
            $transaction, 'defect',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            'damaged', null, $admin,
        );

        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.return.created', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.defect.created', 'user_id' => $admin->id]);
    }

    public function test_business_settings_update_is_audited_with_a_diff()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->patch(route('business-settings.update'), [
            'business_name' => 'Evitan Store',
            'business_address' => '123 Main St',
            'tin' => '000-000-000',
            'receipt_footer' => 'Thank you!',
            'timezone' => 'Asia/Manila',
            'currency_symbol' => '₱',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'settings.updated')->firstOrFail();
        $this->assertArrayHasKey('business_name', $log->new_values);
    }

    public function test_audit_rows_are_immutable()
    {
        $log = AuditLog::factory()->create();

        $this->expectException(RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_rows_cannot_be_deleted()
    {
        $log = AuditLog::factory()->create();

        $this->expectException(RuntimeException::class);
        $log->delete();
    }
}
