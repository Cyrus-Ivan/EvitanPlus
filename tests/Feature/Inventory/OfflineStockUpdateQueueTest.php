<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issuing an adjustment for another branch, on a device with no connection.
 *
 * Its own class because `$connected` is decided in setUp. The refusal is sharper
 * here than for any other online write: a queued adjustment has no local
 * representation at all — the queue lives only in the business database — so
 * "save it and send it later" is not a thing that could be built, and offering
 * it would leave the operator believing another branch had been told something
 * it never was.
 */
class OfflineStockUpdateQueueTest extends TestCase
{
    use RefreshDatabase;

    protected ?bool $connected = false;

    public function test_issuing_is_refused_and_records_nothing(): void
    {
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-updates.store'), [
                'branch_id' => '00000000-0000-0000-0000-00000000e15e',
                'variant_id' => $variant->id,
                'mode' => 'remove',
                'quantity' => 5,
                'notes' => 'Written off at the other branch',
            ])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');

        // Not a partial success. The audit entry is the record of an adjustment
        // having been issued, and this one never was.
        $this->assertSame(0, AuditLog::query()->where('action', 'inventory.stock.queued')->count());
    }
}
