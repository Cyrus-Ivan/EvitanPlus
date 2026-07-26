<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Project-wide UUID primary-key convention.
 *
 * Evitan Plus uses a UUID string primary key on EVERY table so the schema stays
 * sync-compatible (future multi-branch / cloud sync is out of MVP scope but must
 * not require a key migration). Apply this trait to every Eloquent model.
 *
 * The matching migration pattern for Phase 1+ is:
 *
 *     $table->uuid('id')->primary();
 *     // foreign keys:
 *     $table->foreignUuid('category_id')->constrained()->restrictOnDelete();
 *
 * Laravel's HasUuids generates ordered UUIDs (v7-style) which keep inserts
 * index-friendly. We force a string, non-incrementing key on top of it.
 */
trait HasUuidPrimaryKey
{
    use HasUuids;

    public function initializeHasUuidPrimaryKey(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }
}
