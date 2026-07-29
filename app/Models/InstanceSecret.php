<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The branch credential, sealed at rest (MULTIBRANCH-BRAINSTORM.md §9, §11.6).
 *
 * Split from {@see Instance} for exposure rather than normalization: branch
 * identity is read on every checkout, this row only when a sync cycle opens a
 * Postgres connection. Nothing here is readable without the per-install
 * APP_KEY, and the key itself is stripped from every build
 * (`nativephp.cleanup_env_keys`) and regenerated per install.
 *
 * No timestamps: `imported_at` and `rotated_at` are the two moments that matter
 * and both are explicit.
 */
class InstanceSecret extends Model
{
    /** The singleton's key. */
    public const ID = 1;

    protected $table = 'instance_secret';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'dsn_ciphertext',
        'fingerprint',
        'imported_at',
        'rotated_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'dsn_ciphertext',
    ];

    /**
     * @return array{imported_at: string, rotated_at: string}
     */
    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->find(self::ID);
    }
}
