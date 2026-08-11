<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Exceptions\CannotDeleteOwnerException;
use App\Exceptions\MultipleOwnersException;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who signs in.
 *
 * `role` is annotated because static analysis cannot read it off `casts()`: that
 * method returns the cast CLASS name, so `role: class-string<UserRole>` is a
 * true statement about the array and a false one about the attribute, and every
 * `$user->role->value` in the app reads as "property on a string" without this.
 *
 * @property UserRole $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuidPrimaryKey, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'password',
        'role',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array{password: string, role: class-string<UserRole>, must_change_password: string}
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Guard the Owner invariants at the model layer, so no code path can breach
     * them — not just the HTTP forms:
     *  - "exactly one Owner": reject creating or promoting a second Owner
     *    (the `saving` hook fires on both insert and update);
     *  - "the Owner can never be archived or deleted": reject any soft/force
     *    delete of the Owner.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->role === UserRole::Owner
                && static::query()
                    ->where('role', UserRole::Owner)
                    // On create the row has no key yet; on update, exclude the
                    // Owner saving itself (name/password changes) so it isn't
                    // mistaken for a second Owner.
                    ->when($user->exists, fn ($query) => $query->whereKeyNot($user->getKey()))
                    ->exists()) {
                throw new MultipleOwnersException;
            }
        });

        static::deleting(function (User $user): void {
            if ($user->role === UserRole::Owner) {
                throw new CannotDeleteOwnerException;
            }
        });
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isCashier(): bool
    {
        return $this->role === UserRole::Cashier;
    }

    /**
     * Whether this user's role clears a minimum-role requirement, honouring the
     * Owner > Admin > Cashier hierarchy.
     */
    public function hasRoleAtLeast(UserRole $role): bool
    {
        return $this->role->atLeast($role);
    }
}
