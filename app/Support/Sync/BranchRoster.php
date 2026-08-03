<?php

namespace App\Support\Sync;

use Illuminate\Database\Connection;

/**
 * The business's branches, read live from the business database.
 *
 * A till knows only which branch it IS — its own identity is in `instance` and
 * `branches` is deliberately not mirrored (see {@see OnlineRead}) — so every
 * screen that names another branch has to ask Postgres. This is that one query,
 * in one place, because the two screens using it want the same shape and, more
 * importantly, share a non-obvious decision about `disabled_at`.
 *
 * ## Why disabled branches are included by default
 *
 * A disabled credential stops a branch RECEIVING work; it does not unmake what
 * that branch already did. Its past sales are still the business's sales and its
 * past logins are still its logins, so a report or a history that hid them would
 * disagree with its own totals. The flag exists for the opposite case — the
 * inventory queue picks a branch to send an adjustment TO, and a disabled branch
 * cannot answer one.
 */
class BranchRoster
{
    /**
     * Every branch, keyed by id and ordered by code.
     *
     * @return array<string, array{id: string, code: string, name: string}>
     */
    public static function read(Connection $remote, bool $includeDisabled = true): array
    {
        return $remote->table('branches')
            ->when(! $includeDisabled, fn ($query) => $query->whereNull('disabled_at'))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (object $row): array => [(string) $row->id => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ]])
            ->all();
    }
}
