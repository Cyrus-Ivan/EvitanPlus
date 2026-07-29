<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\HidesCostFromNonOwners;
use App\Models\Concerns\Immutable;
use App\Models\Concerns\PushedUpward;
use Database\Factories\SaleReturnItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of an after-sales event. Snapshots the captured selling_price / cost
 * from the source sold line. Cost is Owner-only (HidesCostFromNonOwners).
 * Append-only and immutable.
 */
class SaleReturnItem extends Model
{
    /** @use HasFactory<SaleReturnItemFactory> */
    use HasFactory, HasUuidPrimaryKey, HidesCostFromNonOwners, Immutable, PushedUpward;

    // Append-only / immutable line: no updated_at.
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'sale_return_id',
        'transaction_item_id',
        'variant_id',
        'quantity',
        'selling_price',
        'cost',
        'refund_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'selling_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'refund_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SaleReturn, $this>
     */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /**
     * The original sold line this reverses.
     *
     * @return BelongsTo<TransactionItem, $this>
     */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class, 'transaction_item_id');
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
