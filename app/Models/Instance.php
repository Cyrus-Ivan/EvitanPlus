<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which branch this device is (MULTIBRANCH-BRAINSTORM.md §11.6).
 *
 * A singleton: exactly one row, `id = 1`, written by the setup wizard from the
 * credentials JSON. Deliberately not a UUID-keyed business record — nothing
 * about this row ever leaves the device. `branch_id` is what gets stamped onto
 * pushed rows from M6 on, and it lives here because this is the one place a
 * till knows it.
 *
 * `hydrated_at` is the fresh-install signal (RequireInitialSetup). It is stamped
 * in the same SQLite transaction as the hydrated rows, so a half-hydrated device
 * is unrepresentable: either the baseline and this stamp are both there, or
 * neither is.
 */
class Instance extends Model
{
    /** The singleton's key. */
    public const ID = 1;

    protected $table = 'instance';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'business_id',
        'schema_name',
        'branch_id',
        'branch_code',
        'branch_name',
        'schema_version',
        'min_app_version',
        'hydrated_at',
        'transaction_number_floor',
        'return_number_floor',
    ];

    /**
     * @return array{schema_version: string, hydrated_at: string}
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'hydrated_at' => 'datetime',
        ];
    }

    /** The device's identity, or null on an install that has never been set up. */
    public static function current(): ?self
    {
        return static::query()->find(self::ID);
    }

    /**
     * Has this device completed hydration?
     *
     * Read on every request by RequireInitialSetup — one indexed primary-key
     * lookup on local SQLite, and no persisted flag anywhere else, so the answer
     * cannot drift from the database.
     */
    public static function isHydrated(): bool
    {
        return static::query()->whereNotNull('hydrated_at')->exists();
    }
}
