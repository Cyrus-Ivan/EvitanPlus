<?php

namespace App\Support\Reports;

use App\Enums\LoginEventType;
use App\Support\Sync\OnlineRead;
use Carbon\Carbon;
use Illuminate\Database\Connection;

/**
 * Shifts, recomputed from the append-only `login_history` (employee performance).
 *
 * A SHIFT is a `login` paired with whatever ended it, anchored to the day it
 * STARTED. That anchor is the whole point: a cashier who signs in at 8:00 PM and
 * out at 4:03 AM worked one shift on the earlier day, not two half-days either
 * side of midnight, and a report that splits it answers a question nobody asked.
 *
 * ## Why the pairing happens in PHP
 *
 * Over ONE connection, like {@see SalesFigures} and for the same reason. In
 * production that connection is always the Postgres one {@see OnlineRead} opens
 * for the length of one request — employee performance is online-only and always
 * business-wide — but the class stays connection-agnostic, which is what lets the
 * unit tests pair shifts against SQLite with no credential in sight.
 *
 * Unlike the sales recompute there is no bucketing SQL here at all — no
 * `strftime`, no `to_char`, no timezone binding — because a sequence of events has
 * to be walked in order to be paired, and walking it in PHP costs one small
 * ordered read and removes a whole class of dialect trap. Only the window bounds
 * differ between the two databases, and they follow the same rule
 * {@see SalesFigures::bound()} uses.
 *
 * ## Where a shift happened
 *
 * A shift carries the branch its `login` was recorded at, because the report
 * spans the whole business and a person is not a branch: somebody can sign in at
 * Manila on Monday and at Cebu on Tuesday, and a month that does not say so reads
 * as one undifferentiated block. The code is resolved by a LEFT JOIN onto
 * `branches` — only on Postgres, since the local `login_history` has no
 * `branch_id` column and deliberately never will (every local row is this
 * branch's by construction). On SQLite the branch is simply null.
 *
 * ## The unclosed shift
 *
 * A shift can end four ways, and only two of them are a time you may believe:
 *
 *  - `logout` — the person signed out. Real end, real hours.
 *  - `forced_logout` — an admin ended their session. Also real: it happened when
 *    the row says it did.
 *  - `application_crash` — the app did not close cleanly, and the row is written
 *    on the NEXT LAUNCH carrying that moment. It says the shift ended, and says
 *    nothing trustworthy about when. A till left off over a weekend would
 *    otherwise book the whole weekend as worked.
 *  - nothing at all — still signed in, or the device has not been back since.
 *
 * The last two produce an OPEN shift: a start, no end, and no hours. The month's
 * total hours stays a number that can be defended, and the count of open shifts
 * is reported alongside it so an unclean exit reads as the data-quality problem
 * it is rather than as overtime.
 */
class AttendanceFigures
{
    /**
     * How far past the window's end to keep reading, only to CLOSE a shift that
     * started inside it. A shift beginning at 23:59 on the last night is closed
     * by an event the following day; nothing longer than this is a shift anybody
     * worked, and a start beyond the window is dropped either way.
     */
    private const CLOSING_DAYS = 1;

    /**
     * @param  Connection  $db  The business Postgres connection in production;
     *                          local SQLite in the pairing tests.
     */
    public function __construct(private readonly Connection $db) {}

    /**
     * Every shift this employee STARTED inside the window, in order.
     *
     * `end` is null on an open shift and `hours` is null with it — never 0, which
     * would read as "worked no time" rather than "we do not know".
     *
     * @return list<array{date: string, start: Carbon, end: ?Carbon, hours: ?float, open: bool, branch: ?string}>
     */
    public function shifts(string $userId, Carbon $from, Carbon $to): array
    {
        $events = $this->events($userId, $from, $to->copy()->addDays(self::CLOSING_DAYS)->endOfDay());

        $shifts = [];
        $open = null;

        foreach ($events as $event) {
            if ($event['type'] === LoginEventType::Login->value) {
                // A login while another shift is open means the earlier one was
                // never closed — the app was killed, or the row that would have
                // closed it never reached this database. Close it as open.
                if ($open !== null) {
                    $shifts[] = $this->shift($open, null);
                }

                $open = $event;

                continue;
            }

            if ($open === null) {
                // A sign-out whose sign-in fell before the window: it belongs to
                // the previous month's shift, which that month already reported.
                continue;
            }

            $shifts[] = $this->shift(
                $open,
                // The crash row's timestamp is the next launch, not the exit.
                $event['type'] === LoginEventType::ApplicationCrash->value ? null : $event['at'],
            );

            $open = null;
        }

        if ($open !== null) {
            $shifts[] = $this->shift($open, null);
        }

        // Only shifts that STARTED in the window are this window's. The extra
        // closing day was read to finish them, never to add its own.
        return array_values(array_filter(
            $shifts,
            fn (array $shift): bool => $shift['start']->betweenIncluded($from, $to),
        ));
    }

    /**
     * One shift, with its hours when they are knowable.
     *
     * @param  array{type: string, at: Carbon, branch: ?string}  $start  The `login` event that opened it.
     * @return array{date: string, start: Carbon, end: ?Carbon, hours: ?float, open: bool, branch: ?string}
     */
    private function shift(array $start, ?Carbon $end): array
    {
        $at = $start['at'];

        return [
            // The day the shift BELONGS to — its start, whatever midnight did.
            'date' => $at->toDateString(),
            'start' => $at,
            'end' => $end,
            'hours' => $end === null ? null : round($at->diffInSeconds($end) / 3600, 2),
            'open' => $end === null,
            // Where they signed IN. A sign-out cannot happen at another branch
            // without a sign-in there first, which would have opened a shift of
            // its own, so the sign-in is the whole answer.
            'branch' => $start['branch'],
        ];
    }

    /**
     * This employee's raw sign-in/out events over the window, oldest first, as
     * business-local moments.
     *
     * Ordered by `coalesce(occurred_at, created_at)` — the same value every other
     * login-history screen displays and filters on, because `occurred_at` is
     * nullable by design (an event reconstructed on the next launch has no
     * accurate time of its own).
     *
     * The branch join is Postgres-only: upstream every row carries the branch it
     * happened at, while the local table has no such column at all. Columns are
     * therefore qualified — `branches` carries a `created_at` of its own, and an
     * unqualified `coalesce(occurred_at, created_at)` would be an ambiguous-column
     * error at runtime rather than at boot.
     *
     * @return list<array{type: string, at: Carbon, branch: ?string}>
     */
    private function events(string $userId, Carbon $from, Carbon $to): array
    {
        $when = 'coalesce(login_history.occurred_at, login_history.created_at)';
        $joins = $this->db->getDriverName() === 'pgsql';

        $columns = ['login_history.event_type', 'login_history.occurred_at', 'login_history.created_at'];

        $query = $this->db->table('login_history')
            ->where('login_history.user_id', $userId);

        if ($joins) {
            $query->leftJoin('branches', 'branches.id', '=', 'login_history.branch_id');
            $columns[] = 'branches.code as branch_code';
        }

        return $query
            ->whereRaw($when.' >= ?', [$this->bound($from)])
            ->whereRaw($when.' <= ?', [$this->bound($to)])
            ->orderByRaw($when)
            // A second key so two events sharing a timestamp still pair in a
            // stable order: `timestamptz` is precision 0 upstream, so a sign-out
            // and the sign-in after it can land in the same second.
            ->orderBy('login_history.id')
            ->get($columns)
            ->map(fn (object $row): array => [
                'type' => (string) $row->event_type,
                'at' => $this->localise($row->occurred_at ?? $row->created_at),
                // Null on SQLite, and null upstream for a row written before the
                // branch existed — the shift is still a shift either way.
                'branch' => isset($row->branch_code) ? (string) $row->branch_code : null,
            ])
            ->all();
    }

    /**
     * A window bound, in the form the connection compares correctly.
     *
     * SQLite holds naive business-local strings, so the bound is written the same
     * way. Postgres holds `timestamptz` against a UTC session, so the offset has
     * to be written out or a Manila bound lands eight hours off — a shift that
     * sorts and renders perfectly and covers the wrong nights. Same rule as
     * {@see SalesFigures::bound()}.
     */
    private function bound(Carbon $moment): string
    {
        return $this->db->getDriverName() === 'pgsql'
            ? $moment->copy()->setTimezone($this->timezone())->format('Y-m-d H:i:s.uP')
            : $moment->format('Y-m-d H:i:s');
    }

    /**
     * A stored timestamp as a business-local moment. Upstream values arrive with
     * an offset and are converted in PHP (the habit `LoginHistoryController`
     * established); local ones are already business-local and only need parsing.
     */
    private function localise(mixed $value): Carbon
    {
        return Carbon::parse((string) $value)->setTimezone($this->timezone());
    }

    /** The business timezone — one for the whole business. */
    private function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
