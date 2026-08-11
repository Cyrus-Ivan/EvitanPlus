<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportHistory extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'import_history';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'filename',
        'mode',
        'status',
        'total_rows',
        'success_count',
        'error_count',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'success_count' => 'integer',
            'error_count' => 'integer',
            'errors' => 'array',
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
