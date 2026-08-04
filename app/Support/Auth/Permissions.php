<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

class Permissions
{
    /**
     * The named abilities surfaced to the frontend as a resolved boolean map.
     * The Gate definitions live in AppServiceProvider::boot().
     *
     * @var list<string>
     */
    public const ABILITIES = [
        'access-reports',
        'access-audit-logs',
        'manage-users',
        'access-settings',
        'view-item-cost',
        'view-consolidated-reports',
    ];

    /**
     * Resolve every ability into a boolean map for the given user, so the
     * client never re-implements the role hierarchy.
     *
     * @return array<string, bool>
     */
    public static function forUser(?User $user): array
    {
        // Guests clear no ability. Return a full false map (not an empty array)
        // so the shared prop is always a keyed object matching the frontend
        // Record<Permission, boolean> type, never a bare JSON array.
        if (! $user) {
            return array_fill_keys(self::ABILITIES, false);
        }

        $gate = Gate::forUser($user);

        return collect(self::ABILITIES)
            ->mapWithKeys(fn (string $ability): array => [$ability => $gate->allows($ability)])
            ->all();
    }
}
