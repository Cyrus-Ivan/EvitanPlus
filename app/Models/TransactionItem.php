<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\HidesCostFromNonOwners;
use App\Models\Concerns\Immutable;
use App\Models\Concerns\PushedUpward;
use Database\Factories\TransactionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionItem extends Model
{
    /** @use HasFactory<TransactionItemFactory> */
    use HasFactory, HasUuidPrimaryKey, HidesCostFromNonOwners, Immutable, PushedUpward;

    // Append-only sale line: immutable business history.
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'transaction_id',
        'variant_id',
        'quantity',
        'selling_price',
        'cost',
        'discount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'selling_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * The display label for this sold line: item name, plus the variant name
     * when the item has a named variant (a single-product item keeps an
     * unnamed variant and falls back to the item name). The single source for
     * every surface that names a sold line — sales pages, receipts, return
     * validation messages — so they can never drift apart.
     *
     * @param  string  $separator  Receipts pass ' - ' (the ESC/POS character
     *                             set has no em-dash); screens use the default.
     */
    public function displayLabel(string $separator = ' — '): string
    {
        $this->loadMissing('variant.item:id,name');

        $name = $this->variant?->item?->name ?? 'Unknown product';
        $variantName = $this->variant?->name;

        return trim($name.($variantName ? $separator.$variantName : ''));
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * After-sales lines (returns / defects) written against this sold
     * line. Their cumulative quantity can never exceed this line's quantity.
     *
     * @return HasMany<SaleReturnItem, $this>
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'transaction_item_id');
    }
}
