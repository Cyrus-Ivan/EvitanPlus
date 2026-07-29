<?php

namespace Tests\Feature\Sync;

use App\Models\ImportHistory;
use App\Models\InventoryLedger;
use App\Models\User;
use App\Support\Inventory\InventoryCsvSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CSV import is online-only (MULTIBRANCH-BRAINSTORM.md §17), and this is the
 * behavioural change M6 owed the shipped v1.0.0 import screen.
 *
 * An import writes `type = 'stock_adjustment'` ledger rows. Those are
 * online-authoritative — pulled DOWN, never pushed up — so the outbox does not
 * carry them. Imported offline they would be stock movements that can never
 * reach Postgres, and this branch's stock would diverge from the business's
 * permanently, with nothing anywhere saying so. That is the exact failure the
 * whole sync design exists to prevent, so the refusal is the feature.
 *
 * The alternative, pushing them, was rejected: it would give `stock_adjustment`
 * rows two writers and break single-writer-per-table.
 */
class OfflineCsvImportTest extends TestCase
{
    use RefreshDatabase;

    /** The device under test is offline. */
    protected ?bool $connected = false;

    private function csv(): UploadedFile
    {
        $row = [
            'category' => 'Beverages',
            'product' => 'Bottled Water',
            'variant' => '500ml',
            'sku' => 'WATER-500',
            'barcode' => '',
            'selling_price' => '15.00',
            'cost' => '9.50',
            'stock' => '100',
            'unit' => 'pc',
            'description' => '',
            'low_stock_threshold' => '12',
            'mode' => 'replace_stock_count',
        ];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, InventoryCsvSchema::COLUMNS, escape: '');
        fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', InventoryCsvSchema::COLUMNS), escape: '');
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent('inventory.csv', $contents);
    }

    public function test_the_page_says_it_is_offline(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inventory.import'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('online', false));
    }

    public function test_an_offline_import_is_refused_and_writes_nothing(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('inventory.import.store'), ['file' => $this->csv()])
            ->assertSessionHasErrors('file');

        // Not merely rejected at the door: nothing partial, no ledger movement
        // and no history row that would later read as an import that happened.
        $this->assertSame(0, ImportHistory::query()->count());
        $this->assertSame(0, InventoryLedger::query()->count());
    }

    public function test_the_refusal_explains_itself(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->post(route('inventory.import.store'), ['file' => $this->csv()]);

        $errors = session('errors')->get('file');

        $this->assertStringContainsString('needs a connection', $errors[0]);
        $response->assertRedirect();
    }
}
