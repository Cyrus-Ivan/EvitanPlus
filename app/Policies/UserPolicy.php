<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for employee management (Phase 13).
 *
 * Every management action reduces to one relational rule: an actor may act on a
 * target only when the actor strictly outranks the target in the
 * Owner > Admin > Cashier hierarchy. This single check yields the whole matrix:
 * the Owner manages Admins and Cashiers; an Admin manages only Cashiers; nobody
 * manages the Owner (no role outranks it); and nobody may manage themselves
 * (equal rank is not strictly greater).
 */
class UserPolicy
{
    /**
     * Only Admins and the Owner may reach user management at all. (The route is
     * already gated by `role:admin`; this backs the `manage-users` gate.)
     */
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin() || $actor->isOwner();
    }

    /**
     * Whether the actor may manage (archive/restore/change role/reset password)
     * the target user.
     */
    public function manage(User $actor, User $target): bool
    {
        return $this->outranks($actor, $target);
    }

    /**
     * The core hierarchy check: strictly-greater rank. Because the Owner sits at
     * the top, an Owner target can never be outranked and is thus fully
     * protected here, on top of the model-layer delete guard.
     */
    private function outranks(User $actor, User $target): bool
    {
        return $actor->role->level() > $target->role->level();
    }
}
