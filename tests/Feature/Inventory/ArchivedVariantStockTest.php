<?php

namespace Tests\Feature\Inventory;

use App\Models\Instance;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

/**
 * Stock may not be sent to a product that has been withdrawn from sale.
 *
 * Archiving is this app's delete (`CLAUDE.md` → "Never delete business
 * records"): the row stays for history and disappears from every list. So stock
 * arriving on an archived variant is stock nobody can sell, that shows on no
 * screen — `/inventory` filters to active items — and that still counts in the
 * ledger sum. It is a quantity with nowhere to go.
 *
 * The rule was stated in three places and missing from four. `CheckoutRequest`
 * scopes its `exists` with `->whereNull('archived_at')`, and both importers
 * refuse archived rows explicitly with their own messages — but every path that
 * ISSUES a movement used a bare `exists:variants,id`, so the one direction that
 * puts stock ON a shelf was the one that did not check whether the shelf was
 * still in use.
 *
 * An ARCHIVED ITEM counts too: a variant of an archived item is not on sale
 * whatever its own `archived_at` says, which is the same reading `Variant::active()`
 * and the POS grid already take.
 */
class ArchivedVariantStockTest extends TestCase
{
    use FakesThePendingQueue, RefreshDatabase;

    private const OTHER_BRANCH = '00000000-0000-0000-0000-00000000e15e';

    /** A variant whose own row is archived. */
    private function archivedVariant(): Variant
    {
        return Variant::factory()->create(['archived_at' => now()]);
    }

    /** A live variant of an archived item — archived by inheritance. */
    private function variantOfArchivedItem(): Variant
    {
        $item = Item::factory()->create(['archived_at' => now()]);

        return Variant::factory()->for($item)->create();
    }

    /** @return array<string, array{0: string}> */
    public static function archivedShapes(): array
    {
        return [
            'the variant itself' => ['archivedVariant'],
            'its item' => ['variantOfArchivedItem'],
        ];
    }

    #[DataProvider('archivedShapes')]
    public function test_a_manual_adjustment_cannot_target_an_archived_product(string $shape): void
    {
        $this->fakeBusinessQueue();
        $variant = $this->{$shape}();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock.adjust', $variant), ['mode' => 'add', 'value' => 5])
            ->assertNotFound();
    }

    #[DataProvider('archivedShapes')]
    public function test_a_queued_adjustment_cannot_target_an_archived_product(string $shape): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->{$shape}();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-updates.store'), [
                'branch_id' => self::OTHER_BRANCH,
                'variant_id' => $variant->id,
                'mode' => 'add',
                'quantity' => 5,
            ])
            ->assertSessionHasErrors('variant_id');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    #[DataProvider('archivedShapes')]
    public function test_a_transfer_cannot_move_an_archived_product(string $shape): void
    {
        $remote = $this->fakeBusinessQueue();
        $variant = $this->{$shape}();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-transfers.store'), [
                'from_branch_id' => (string) Instance::current()->branch_id,
                'to_branch_id' => self::OTHER_BRANCH,
                'variant_id' => $variant->id,
                'quantity' => 3,
            ])
            ->assertSessionHasErrors('variant_id');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    #[DataProvider('archivedShapes')]
    public function test_a_stock_batch_cannot_carry_an_archived_product(string $shape): void
    {
        $remote = $this->fakeBusinessQueue();
        $live = Variant::factory()->create();
        $variant = $this->{$shape}();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-batches.store'), [
                'branch_id' => (string) Instance::current()->branch_id,
                'kind' => 'adjustment',
                'notes' => 'Delivery',
                'lines' => [
                    ['variant_id' => $live->id, 'mode' => 'add', 'quantity' => 2],
                    ['variant_id' => $variant->id, 'mode' => 'add', 'quantity' => 2],
                ],
            ])
            ->assertSessionHasErrors('lines.1.variant_id');

        // All-or-nothing: the live line must not have been queued either.
        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }
}
