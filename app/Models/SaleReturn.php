<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\Immutable;
use App\Models\Concerns\PushedUpward;
use Database\Factories\SaleReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * After-sales event (return / defect). Append-only and immutable — it
 * references the original sale without mutating it. See
 * docs/returns-refunds-voids-design.md.
 */
class SaleReturn extends Model
{
    /** @use HasFactory<SaleReturnFactory> */
    use HasFactory, HasUuidPrimaryKey, Immutable, PushedUpward;

    // Append-only / immutable: no updated_at (matches inventory_ledger).
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'return_number',
        'transaction_id',
        'type',
        'reason',
        'refund_payment_type',
        'total_refund',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'total_refund' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by')->withTrashed();
    }

    /**
     * @return HasMany<SaleReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
