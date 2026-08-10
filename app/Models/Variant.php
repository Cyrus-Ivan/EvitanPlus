<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\HidesCostFromNonOwners;
use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory, HasUuidPrimaryKey, HidesCostFromNonOwners;

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'name',
        'sku',
        'barcode',
        'selling_price',
        'cost',
        'low_stock_threshold',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'low_stock_threshold' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * SKUs are canonically UPPERCASE (trimmed). This mutator is the persistence
     * backstop — entry points (ItemRequest, CsvInventoryImporter) normalize
     * before querying, and anything that slips through is normalized on save.
     * Null/blank passes through as null so the DB NOT NULL constraint, not the
     * mutator, is the failure point for a missing SKU.
     *
     * @return Attribute<never, ?string>
     */
    protected function sku(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => ($value === null || trim($value) === '')
                ? null
                : mb_strtoupper(trim($value)),
        );
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return HasMany<InventoryLedger, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(InventoryLedger::class);
    }

    /**
     * @return HasMany<TransactionItem, $this>
     */
    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * Denormalized current-stock cache, maintained by StockLedger.
     *
     * @return HasOne<StockSnapshot, $this>
     */
    public function stockSnapshot(): HasOne
    {
        return $this->hasOne(StockSnapshot::class);
    }

    /**
     * Current on-hand quantity from the snapshot (0 when never stocked). Reads
     * the eager-loaded relation when present to avoid an extra query per row.
     *
     * @return Attribute<int, never>
     */
    protected function currentStock(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) ($this->stockSnapshot?->quantity ?? 0),
        )->shouldCache();
    }

    /**
     * Qualified, because this scope is used on queries that JOIN `items` —
     * the Inventory list and the batch dialog's picker, both of which read
     * variant-first (M13). `items` carries an `archived_at` too, so an
     * unqualified column here is an ambiguous-column error at runtime.
     *
     * @param  Builder<Variant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('variants.archived_at');
    }
}
