<?php

namespace App\Models;

use App\Enums\LoginEventType;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\Immutable;
use App\Models\Concerns\PushedUpward;
use Database\Factories\LoginHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only login history. Only created_at is tracked.
 */
class LoginHistory extends Model
{
    /** @use HasFactory<LoginHistoryFactory> */
    use HasFactory, HasUuidPrimaryKey, Immutable, PushedUpward;

    protected $table = 'login_history';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'event_type',
        'occurred_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => LoginEventType::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
