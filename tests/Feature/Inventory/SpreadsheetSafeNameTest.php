<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use App\Support\Inventory\InventoryCsvSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 18 S8 (F-16) — names that would execute as spreadsheet formulas on
 * CSV export (leading =, +, - or @) are rejected at creation time, on the
 * forms and on CSV import alike.
 */
class SpreadsheetSafeNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_and_variant_names_reject_formula_triggers(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['=SUM(A1:A9)', '+cmd|calc', '-2+3', '@evil'] as $bad) {
            $this->actingAs($admin)
                ->post(route('items.store'), [
                    'name' => $bad,
                    'unit' => 'pcs',
                    'variants' => [['name' => null, 'sku' => 'SAFE-1', 'selling_price' => '10.00']],
                ])
                ->assertSessionHasErrors('name');
        }

        $this->actingAs($admin)
            ->post(route('items.store'), [
                'name' => 'Safe Product',
                'unit' => 'pcs',
                'variants' => [['name' => '=Small', 'sku' => 'SAFE-2', 'selling_price' => '10.00']],
            ])
            ->assertSessionHasErrors('variants.0.name');

        $this->assertSame(0, Item::count());
    }

    public function test_category_names_reject_formula_triggers(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('categories.store'), ['name' => '=Beverages'])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Category::count());
    }

    public function test_category_rename_rejects_formula_triggers(): void
    {
        $category = Category::factory()->create(['name' => 'Snacks']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('categories.update', $category), ['name' => '=Snacks'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Snacks', $category->fresh()->name);
    }

    public function test_csv_import_rejects_formula_trigger_names(): void
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, InventoryCsvSchema::COLUMNS);
        fputcsv($handle, ['', '=Injected Product', '', 'INJ-1', '', '10.00', '', 'pcs', '', '']);
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'inv').'.csv';
        file_put_contents($path, $content);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('items.import.store'), [
                'file' => new UploadedFile($path, 'inventory.csv', 'text/csv', null, true),
            ]);

        $this->assertSame(0, Item::count());
    }
}
