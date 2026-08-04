<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How far down each pulled table this device has read
 * (MULTIBRANCH-BRAINSTORM.md §11.6, docs/glossary.md §5).
 *
 * Keyed by table name rather than by an id: there is exactly one cursor per
 * pulled table and the table name IS the identity. Per table, not one global
 * watermark, so a failure on one table costs only that table's progress.
 *
 * ## `pulled_through` is Postgres's string, not a date
 *
 * It is stored and returned verbatim, and nothing on this side ever parses it.
 * The value is a `timestamptz` with microseconds and an offset; this app's
 * datetime convention is a naive second-resolution local string, so any
 * conversion would move the cursor off the row it was read from — by up to a
 * second, in a direction that either re-reads or skips. Keeping it opaque is
 * what makes the round trip exact. Hence no cast below, deliberately.
 */
class SyncCursor extends Model
{
    protected $table = 'sync_cursor';

    protected $primaryKey = 'table_name';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['table_name', 'pulled_through', 'last_pulled_at'];

    /**
     * The watermark for $table, or null if it has never been pulled.
     *
     * Null means "read everything", which is both the first-cycle case and the
     * repair path when a device's bookmark has to be rewound.
     */
    public static function positionOf(string $table): ?string
    {
        $value = static::query()->where('table_name', $table)->value('pulled_through');

        return $value === null ? null : (string) $value;
    }

    /**
     * Record that $table has been read through $watermark.
     *
     * $watermark is null when the pull found nothing new — the position does not
     * move, but the run is still recorded, because "pulled and there was
     * nothing" and "never pulled" are different answers.
     */
    public static function advance(string $table, ?string $watermark): void
    {
        static::query()->updateOrInsert(
            ['table_name' => $table],
            array_filter(
                ['pulled_through' => $watermark, 'last_pulled_at' => now()],
                fn (mixed $value): bool => $value !== null,
            ),
        );
    }
}
