<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_records_a_login_event()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $events = LoginHistory::where('user_id', $user->id)->pluck('event_type');
        $this->assertEquals([LoginEventType::Login], $events->all());
    }

    public function test_logout_records_a_logout_event()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $this->assertSame(
            1,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::Logout)
                ->count()
        );
    }

    public function test_failed_login_records_no_event()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $this->assertSame(0, LoginHistory::where('user_id', $user->id)->count());
    }

    /**
     * One till, one open session: a sign-in over a session nobody closed says so
     * before it records itself. The session that was open is the earlier user's,
     * which is the whole reason the row is not simply attributed to whoever is
     * signing in now.
     */
    public function test_a_login_closes_a_session_this_branch_left_open()
    {
        $earlier = User::factory()->cashier()->create();
        $user = User::factory()->create();

        // Signed in, and never signed out — an idle session timing out writes
        // nothing at all, which is the case this exists for.
        LoginHistory::factory()->for($earlier)->create([
            'event_type' => LoginEventType::Login,
            'occurred_at' => now()->subHours(3),
        ]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        $events = LoginHistory::query()
            ->orderByRaw('COALESCE(occurred_at, created_at)')
            ->orderBy('id')
            ->get(['user_id', 'event_type']);

        $this->assertSame(
            [LoginEventType::Login->value, LoginEventType::ApplicationCrash->value, LoginEventType::Login->value],
            $events->pluck('event_type')->map(fn (LoginEventType $type): string => $type->value)->all(),
        );

        // The closing row belongs to the session it closed, and it sorts ahead
        // of the sign-in that noticed it.
        $this->assertSame($earlier->id, $events[1]->user_id);
        $this->assertSame($user->id, $events[2]->user_id);
    }

    /**
     * Two sign-ins inside one second. The closing row is stamped a second back
     * so it cannot share the new sign-in's moment, and these columns hold whole
     * seconds — so the naive subtraction would put it BEFORE the session it
     * closes, and the branch would read `crash, login, login`: the very
     * adjacency the guard exists to prevent, written by the guard itself.
     */
    public function test_a_session_is_never_closed_before_it_opened()
    {
        $earlier = User::factory()->cashier()->create();
        $user = User::factory()->create();

        $opened = LoginHistory::factory()->for($earlier)->create([
            'event_type' => LoginEventType::Login,
            'occurred_at' => now(),
        ]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $closing = LoginHistory::where('event_type', LoginEventType::ApplicationCrash)->sole();

        // Against the STORED moment, which is what every reader compares and is
        // already truncated to the whole second these columns hold.
        $this->assertTrue(
            $closing->occurred_at->greaterThanOrEqualTo($opened->fresh()->occurred_at),
            'The closing row is stamped before the sign-in it closes.',
        );

        // And the branch still reads as one session at a time.
        $this->assertSame(
            [LoginEventType::Login->value, LoginEventType::ApplicationCrash->value, LoginEventType::Login->value],
            LoginHistory::query()
                ->orderByRaw('COALESCE(occurred_at, created_at)')
                ->orderBy('id')
                ->pluck('event_type')
                ->map(fn (LoginEventType $type): string => $type->value)
                ->all(),
        );
    }

    /**
     * A session the app has already accounted for must not be accounted for
     * twice. `application_crash` is the ordinary restart path, where
     * EnforceBootSession wrote that row on the way to the login screen.
     */
    #[DataProvider('closedSessions')]
    public function test_a_login_after_a_closed_session_records_only_itself(LoginEventType $closed)
    {
        $earlier = User::factory()->cashier()->create();
        $user = User::factory()->create();

        LoginHistory::factory()->for($earlier)->create([
            'event_type' => LoginEventType::Login,
            'occurred_at' => now()->subHours(3),
        ]);

        LoginHistory::factory()->for($earlier)->create([
            'event_type' => $closed,
            'occurred_at' => now()->subHours(2),
        ]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertSame(3, LoginHistory::count());
        $this->assertSame(
            [LoginEventType::Login],
            LoginHistory::where('user_id', $user->id)->pluck('event_type')->all(),
        );
    }

    /**
     * @return array<string, array{LoginEventType}>
     */
    public static function closedSessions(): array
    {
        return [
            'signed out' => [LoginEventType::Logout],
            'unclean exit already recorded' => [LoginEventType::ApplicationCrash],
            'forced out by an administrator' => [LoginEventType::ForcedLogout],
        ];
    }
}
