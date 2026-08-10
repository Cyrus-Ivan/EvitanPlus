<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\User;
use App\Support\Inventory\InventoryCsvSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 18 S5 — CSV import edges beyond the Phase 7 suite: weird-but-valid UTF-8
 * survives the import byte-identically.
 *
 * The round trip used to run through the CSV export, which M13 deleted along
 * with the stock columns that gave it a reason to exist. The equivalent claim
 * without it is the one that actually matters to a user: import the file, then
 * import it AGAIN as an update, and the multibyte name is still the same bytes —
 * which also exercises the `immutableFieldErrors` comparison, the one place a
 * mangled name would surface as a spurious "you cannot rename a product".
 *
 * (Formula-injection names are deliberately not pinned here: finding F-16
 * changes how leading =/+/@ names are handled, and its test lands with that
 * S8 fix.)
 */
class InventoryImportEdgeTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array<string, string>> $rows */
    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, InventoryCsvSchema::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column) => $row[$column] ?? '',
                InventoryCsvSchema::COLUMNS,
            ));
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'inv').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'inventory.csv', 'text/csv', null, true);
    }

    public function test_multibyte_utf8_names_survive_import_and_re_import(): void
    {
        $owner = User::factory()->owner()->create();

        $name = 'Ñandú — 100% Kapé ☕ (テスト)';
        $row = [
            'product' => $name,
            'variant' => 'Größe M',
            'sku' => 'UTF8-1',
            'selling_price' => '12.34',
            'unit' => 'pcs',
        ];

        $this->actingAs($owner)
            ->post(route('items.import.store'), ['file' => $this->csv([$row])])
            ->assertSessionHasNoErrors();

        $item = Item::query()->firstWhere('name', $name);
        $this->assertNotNull($item, 'multibyte item name was not stored verbatim');
        $this->assertSame('Größe M', $item->variants()->sole()->name);

        // The same file again, as an update. Every immutable field is compared
        // against what was stored, so a byte that did not survive the first
        // import fails here as "you cannot rename a product" rather than
        // silently.
        $this->actingAs($owner)
            ->post(route('items.import.store'), [
                'file' => $this->csv([['selling_price' => '13.00'] + $row]),
                'confirm_updates' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('13.00', Item::query()->firstWhere('name', $name)?->variants()->sole()->selling_price);
    }
}
