<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row waiting to push (MULTIBRANCH-BRAINSTORM.md §11.6, docs/glossary.md §5).
 *
 * The OUTBOX is not the QUEUE. This holds *this branch's* work going UP;
 * `inventory_update_queue` (M8) holds adjustments issued elsewhere coming DOWN.
 * They share no code and move in opposite directions — the bare word "queue" is
 * reserved for the latter.
 *
 * ## It is a pointer
 *
 * `table_name` + `row_id` name a row; the row itself is never copied here. Every
 * local-write table is immutable, so the row can be re-read at push time and
 * cannot have changed since it was queued. A copied payload would be a second
 * representation of the same sale, and the only thing two representations can do
 * over time is disagree.
 *
 * ## `row_id`, not `id`
 *
 * §11.6's ERD names this column `id`. It is `row_id` here because the key of
 * this table is `sequence`, and an Eloquent model whose `id` attribute is not
 * its key is a trap: `find()`, `$entry->id` and every relation would read as
 * though they meant the outbox entry when they mean the sale.
 */
class SyncOutboxEntry extends Model
{
    protected $table = 'sync_outbox';

    protected $primaryKey = 'sequence';

    /**
     * `created_at` only — an outbox entry is written once and then stamped
     * `synced_at`; there is no general-purpose update to record.
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'table_name',
        'row_id',
        'group_id',
        'attempts',
        'last_error',
        'last_attempt_at',
        'synced_at',
        'created_at',
    ];

    /**
     * @return array{attempts: string, last_attempt_at: string, synced_at: string, created_at: string}
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
