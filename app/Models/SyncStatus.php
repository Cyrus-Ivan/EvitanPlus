<?php

namespace App\Models;

use App\Support\Sync\Outbox;
use Illuminate\Database\Eloquent\Model;

/**
 * Why this device is, or is not, syncing (MULTIBRANCH-BRAINSTORM.md §11.6).
 *
 * A singleton, like {@see Instance}: current state, never history. What
 * happened over time is the console's business (M10's monitor reads
 * `branch_status`), and keeping a log here would be a second, worse copy of it.
 *
 * ## Four states, not two
 *
 * `unauthorized` (the console disabled or rotated this branch's role),
 * `version_blocked` (the §13 gate refused because the schema migrated ahead of
 * this build) and plain `offline` are three genuinely different situations that
 * are indistinguishable at the till, and only one of them is fixed by waiting.
 * Collapsing them to online/offline would leave the person standing at the
 * counter with no way to tell "the internet is down" from "someone revoked us".
 *
 * None of them stop a sale. A failed cycle has never stopped a sale.
 */
class SyncStatus extends Model
{
    /** The singleton's key. */
    public const ID = 1;

    public const ONLINE = 'online';

    public const OFFLINE = 'offline';

    public const UNAUTHORIZED = 'unauthorized';

    public const VERSION_BLOCKED = 'version_blocked';

    /** Error kinds, paired with the states above for the UI to speak plainly. */
    public const KIND_AUTH = 'auth';

    public const KIND_VERSION = 'version';

    public const KIND_NETWORK = 'network';

    /**
     * The connection worked and the data did not (M6.5).
     *
     * §11.6 names four error kinds and M6 shipped three, which left a till whose
     * sales cannot land — a duplicate serial, a constraint refusal — displaying
     * `offline`. That is the worst of the available lies: staff wait for a
     * connection they already have, while the backlog grows and nothing on
     * screen suggests anyone should be called. `conflict` pairs with
     * `connection_state = online`, because the branch IS online; what is stuck
     * is the data.
     */
    public const KIND_CONFLICT = 'conflict';

    protected $table = 'sync_status';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'last_sync_at',
        'last_probe_at',
        'connection_state',
        'last_error_kind',
        'last_error',
        'blocked_since',
    ];

    protected $attributes = [
        'connection_state' => self::OFFLINE,
    ];

    /**
     * Deliberately undocumented: an `@return array{...: string}` shape here
     * tells PHPStan the value is a plain string rather than the literal
     * `'datetime'`, and it then loses the cast — so `last_sync_at` reads as a
     * string and every Carbon call on it is an error. Inference from the literal
     * array below is what makes the casts visible, which is why the rest of this
     * codebase's models leave the method bare too.
     */
    protected function casts(): array
    {
        return [
            'last_sync_at' => 'datetime',
            'last_probe_at' => 'datetime',
            'blocked_since' => 'datetime',
        ];
    }

    /**
     * The row, created on first read.
     *
     * A device that has never attempted a cycle is `offline` by the column
     * default, which is the honest answer rather than an absent one.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => self::ID]);
    }

    /**
     * The shape shared with every Inertia page and returned by POST /sync.
     *
     * One definition for both, so the indicator cannot render one thing on load
     * and a differently-shaped thing after a manual sync.
     *
     * `last_sync_at` is handed over as the cast attribute, not formatted here:
     * Laravel serialises a datetime cast to ISO-8601 with an offset on the way
     * into JSON, which is what every other timestamp this app sends the client
     * already does, and what `new Date(...)` on the other side expects.
     *
     * @return array{state: string, last_sync_at: mixed, unsynced_count: int, error_kind: string|null, error: string|null}
     */
    public static function snapshot(): array
    {
        $status = static::query()->find(self::ID);
        $unsynced = app(Outbox::class)->unsyncedCount();

        // No row: a device that has never attempted a cycle. `offline` is the
        // honest answer rather than an absent one, and the backlog is still
        // real — it is counted from the outbox, not from this table.
        if ($status === null) {
            return [
                'state' => self::OFFLINE,
                'last_sync_at' => null,
                'unsynced_count' => $unsynced,
                'error_kind' => null,
                'error' => null,
            ];
        }

        return [
            'state' => (string) $status->connection_state,
            'last_sync_at' => $status->last_sync_at,
            'unsynced_count' => $unsynced,
            'error_kind' => $status->last_error_kind,
            'error' => $status->last_error,
        ];
    }
}
