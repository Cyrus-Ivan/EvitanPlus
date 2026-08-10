<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\Immutable;
use App\Models\Concerns\PushedUpward;
use Database\Factories\InventoryLedgerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only, immutable inventory ledger. Rows are never edited or deleted;
 * corrections are new compensating entries. Only created_at is tracked.
 */
class InventoryLedger extends Model
{
    /** @use HasFactory<InventoryLedgerFactory> */
    use HasFactory, HasUuidPrimaryKey, Immutable, PushedUpward;

    protected $table = 'inventory_ledger';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'variant_id',
        'quantity',
        'resulting_snapshot',
        'type',
        'sale_type',
        'reference_id',
        'batch_id',
        'reference_type',
        'notes',
        'counterparty_branch_code',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'resulting_snapshot' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The one table BOTH sides insert into (docs/glossary.md §4).
     *
     * `type = 'sale'` rows — any sale_type: sale, return or defect — originate
     * at the till and push up. `type = 'stock_adjustment'` rows are
     * online-authoritative: they are issued online, mediated through
     * `inventory_update_queue` when subtractive (§10), and pulled down. Pushing
     * them would give that class of row two writers and break
     * single-writer-per-table, which is why there is no last-write-wins
     * anywhere in this system.
     *
     * Note there is no `restock` here to exclude — it is not a value anywhere in
     * the codebase, only a UI framing of a positive stock_adjustment.
     */
    public function shouldPush(): bool
    {
        return $this->type === 'sale';
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by')->withTrashed();
    }
}
