<?php

namespace App\Support\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the login-history lifecycle for the desktop app.
 *
 * Sessions are database-backed in every runtime (the native runtime's vendor
 * default of `file` is re-pinned to `database` in AppServiceProvider), but
 * either way session rows survive an unclean exit (window closed, OS shutdown,
 * crash, or force-kill), so an interrupted session cannot be detected from
 * storage alone. Instead, every app run mints a fresh "boot id"; a session
 * stamped with an older id outlived the run that created it and is treated as
 * an unclean exit (see EnforceBootSession).
 */
class SessionManager
{
    /**
     * Cache key holding the current run's boot id. Backed by the shared
     * (database) cache so every NativePHP worker process sees the same value.
     */
    private const BOOT_ID_KEY = 'app_boot_id';

    public function __construct(private readonly LoginHistoryRecorder $recorder) {}

    /**
     * Mint a fresh boot id for this application run. Called on ApplicationBooted,
     * which fires reliably on every launch (unlike the window-close/shutdown
     * events, which race the process teardown and rarely reach PHP in time).
     */
    public function handleBoot(): string
    {
        $bootId = (string) Str::uuid();
        Cache::forever(self::BOOT_ID_KEY, $bootId);

        return $bootId;
    }

    /**
     * The current run's boot id, or null if one has not been minted yet.
     */
    public function currentBootId(): ?string
    {
        return Cache::get(self::BOOT_ID_KEY);
    }

    /**
     * Record a successful login, closing any session this branch left open.
     *
     * A branch has one till and a till holds one session, so two `login` rows
     * with nothing between them is a shape the business cannot produce. The
     * desktop app is single-window, so the one session cookie guarantees that
     * much while the app is running, and the boot-session guard closes a session
     * that outlived its run. Neither covers an IDLE one: `session.lifetime` is
     * 120 minutes and a cashier who steps away past it is returned to the login
     * screen with nothing written at all, so without this their next sign-in
     * would stack a second `login` on the first.
     */
    public function handleLogin(User $user): void
    {
        $this->closeDanglingSession();

        $this->recorder->record($user, LoginEventType::Login);
    }

    /**
     * Close a session left open at this branch, if the last event was a sign-in.
     *
     * Every local row is this branch's by construction — the SQLite table has no
     * branch_id and the push stamps one from `instance` — so "the last event at
     * this branch" is simply the last row, read the way every other reader of
     * this table orders it.
     *
     * The session belongs to whoever opened it, which need not be the person
     * signing in now. What ended it, and when, are both unknowable here, so it
     * is recorded as the same ApplicationCrash every other unclean exit is: the
     * causes are indistinguishable after the fact, and AttendanceFigures already
     * declines to believe that row's timestamp and books the shift as open with
     * no hours — which is the honest answer here too.
     *
     * A no-op on the ordinary restart path: EnforceBootSession has already
     * written this row, so the last event is not a `login` any more.
     */
    private function closeDanglingSession(): void
    {
        $last = LoginHistory::query()
            ->orderByRaw('COALESCE(occurred_at, created_at) desc')
            ->orderByDesc('id')
            ->first();

        if ($last === null || $last->event_type !== LoginEventType::Login) {
            return;
        }

        $user = $last->user;

        if ($user === null) {
            return;
        }

        $this->recorder->record(
            $user,
            LoginEventType::ApplicationCrash,
            // A second before the sign-in that noticed it, but NEVER before the
            // sign-in it closes.
            //
            // The two new rows are written in one request and every reader
            // orders on COALESCE(occurred_at, created_at), where whole seconds
            // are all there are — these columns are `timestamp(0) with time
            // zone` upstream — so sharing that second would leave the order of a
            // session and the one that replaced it resting on the id tiebreak.
            // Hence the second back. But two sign-ins inside ONE second would
            // then put this row before the session it is closing, and a branch
            // reading `crash, login, login` is the exact adjacency this method
            // exists to prevent — so the earlier stamp wins when it is later.
            now()->subSecond()->max($last->occurred_at ?? $last->created_at),
            'Signed out: the session was never closed and had ended by the next sign-in at this branch.',
        );
    }

    /**
     * Graceful, user-initiated logout.
     */
    public function handleLogout(User $user): void
    {
        $this->recorder->record($user, LoginEventType::Logout);
    }

    /**
     * A session that outlived the run it was created in: the previous run ended
     * without a clean logout (window closed, shutdown, crash, or force-kill).
     * All such interruptions are indistinguishable by the next launch, so they
     * share a single event type.
     */
    public function markUncleanExit(User $user): void
    {
        $this->recorder->record(
            $user,
            LoginEventType::ApplicationCrash,
            null,
            'Signed out: the app did not close cleanly (detected on next launch).',
        );
    }

    /**
     * Administratively evict a user (e.g. after an Owner/Admin resets their
     * password) so they must sign in again. Records the event and clears the
     * user's session rows — effective in every runtime now that the native
     * runtime also uses the database session driver.
     */
    public function forceLogout(User $target, ?string $reason = null): void
    {
        $this->recorder->record(
            $target,
            LoginEventType::ForcedLogout,
            null,
            $reason ?? 'Signed out by an administrator.',
        );

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $target->getKey())
            ->delete();
    }
}
