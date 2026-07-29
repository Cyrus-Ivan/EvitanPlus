<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\Immutable;
use App\Models\Concerns\PushedUpward;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUuidPrimaryKey, Immutable, PushedUpward;

    // Append-only: a completed sale is immutable business history.
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'transaction_number',
        'payment_type',
        'discount',
        'discount_type',
        'total',
        'cash_received',
        'change',
        'comment',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'cash_received' => 'decimal:2',
            'change' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return HasMany<TransactionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * After-sales events (returns / defects) recorded against this sale.
     * They reference the sale without ever mutating it.
     *
     * @return HasMany<SaleReturn, $this>
     */
    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }
}
