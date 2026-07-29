<?php

namespace App\Http\Controllers;

use App\Http\Requests\Users\ResetUserPasswordRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRoleRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\SessionManager;
use App\Support\Sync\OnlineWrite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee management (Phase 13). Every write is authorized against UserPolicy,
 * which enforces the Owner > Admin > Cashier hierarchy: an actor may only act on
 * users they strictly outrank, and may only assign roles below their own.
 *
 * Staff accounts are SHARED business data, and more sharply than the catalog is:
 * §6 requires that **any user can log in at any branch**, which is only true
 * while every device holds every account. So a user created, renamed, re-roled,
 * archived or password-reset on one till has to reach the business database —
 * otherwise the account works on one machine and nowhere else, or worse, an old
 * password keeps working everywhere the new one never arrived.
 *
 * Every write here is therefore an ONLINE WRITE ({@see OnlineWrite}; M6.5).
 * Logging IN stays entirely offline, which is the whole point of caching the
 * hashes locally.
 */
class UserController extends Controller
{
    public function __construct(private readonly OnlineWrite $online) {}

    /**
     * Paginated employee list with search and an active/archived/all filter.
     * Archived (soft-deleted) users remain visible via the filter, per the
     * "never delete business records" invariant.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', '')) ?: null;
        $status = $this->normalizeStatus($request->query('status'));

        $users = User::query()
            ->when($status === 'active', fn ($query) => $query->whereNull('deleted_at'))
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->when($search, fn ($query) => $query->whereLikeAny(['name', 'username'], $search))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
                'deleted_at' => $user->deleted_at,
                'created_at' => $user->created_at,
            ]);

        return Inertia::render('users/index', [
            'users' => $users,
            'online' => $this->online->possible(),
            'assignableRoles' => $this->assignableRoles($request->user()),
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function store(StoreUserRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validated();

        $this->online->run(function () use ($data, $audit): void {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'role' => $data['role'],
                'password' => Hash::make($data['password']),
            ]);

            $this->online->mirror($user);

            $audit->record(
                action: 'user.created',
                auditable: $user,
                description: "Created {$user->role->value} account \"{$user->username}\".",
                new: ['name' => $user->name, 'username' => $user->username, 'role' => $user->role->value],
            );
        }, 'Staff');

        return to_route('users')->with('toast', [
            'type' => 'success',
            'message' => 'User created.',
        ]);
    }

    /**
     * Change an employee's role. The name is immutable, so only the role moves.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $oldRole = $user->role->value;

        $this->online->run(function () use ($request, $user, $audit, $oldRole): void {
            $user->update(['role' => $request->validated()['role']]);

            $this->online->mirror($user);

            $audit->record(
                action: 'user.role_changed',
                auditable: $user,
                description: "Changed role of \"{$user->username}\" from {$oldRole} to {$user->role->value}.",
                old: ['role' => $oldRole],
                new: ['role' => $user->role->value],
            );
        }, 'Staff');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Role updated.',
        ]);
    }

    /**
     * Administratively reset an employee's password, then force-log-out their
     * active session so they must sign in again with the new credentials.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user, SessionManager $sessions, AuditRecorder $audit): RedirectResponse
    {
        Gate::authorize('manage', $user);

        // Online because the hash has to reach every branch: any user may log in
        // at any till (§6), so a password changed on one device and nowhere else
        // would let the old one keep working everywhere else.
        $this->online->run(function () use ($request, $user, $sessions, $audit): void {
            $user->update([
                'password' => Hash::make($request->validated()['password']),
                // The admin knows this password; the user must replace it at
                // their next login (RequirePasswordChange).
                'must_change_password' => true,
            ]);

            $this->online->mirror($user);

            $sessions->forceLogout($user, 'Password reset by an administrator.');

            // The new password is never captured — only the fact of the reset.
            $audit->record(
                action: 'user.password_reset',
                auditable: $user,
                description: "Reset password for \"{$user->username}\" and signed out their session.",
            );
        }, 'Staff');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Password reset. The user has been signed out.',
        ]);
    }

    /**
     * Archive (soft delete) an employee. The Owner is protected by both the
     * policy (nothing outranks it) and the model-layer delete guard.
     */
    public function archive(User $user, AuditRecorder $audit): RedirectResponse
    {
        Gate::authorize('manage', $user);

        // A soft delete: `deleted_at` moves, the row stays, and the branch role
        // needs UPDATE rather than DELETE to mirror it.
        $this->online->run(function () use ($user, $audit): void {
            $user->delete();

            $this->online->mirror($user);

            $audit->record(
                action: 'user.archived',
                auditable: $user,
                description: "Archived user \"{$user->username}\".",
            );
        }, 'Staff');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'User archived.',
        ]);
    }

    /**
     * Restore an archived employee. Bound with trashed models via the route.
     */
    public function restore(User $user, AuditRecorder $audit): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $this->online->run(function () use ($user, $audit): void {
            $user->restore();

            $this->online->mirror($user);

            $audit->record(
                action: 'user.restored',
                auditable: $user,
                description: "Restored user \"{$user->username}\".",
            );
        }, 'Staff');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'User restored.',
        ]);
    }

    /**
     * The role string values the actor may assign — surfaced to the create/role
     * dialogs so the client offers only permitted choices.
     *
     * @return list<string>
     */
    private function assignableRoles(User $actor): array
    {
        return array_map(fn ($role) => $role->value, $actor->role->assignableRoles());
    }

    /**
     * Constrain the status filter to a known tri-state, defaulting to active.
     */
    private function normalizeStatus(mixed $status): string
    {
        return in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active';
    }
}
