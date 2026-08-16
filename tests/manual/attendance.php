<?php

declare(strict_types=1);

/**
 * The manual rig's year of ATTENDANCE — the sign-ins and sign-outs behind the
 * year of sales, written straight into a business schema.
 *
 * Required by `tests/manual/pg.php`, which owns the connection, the transaction
 * and the staff roster this runs inside; it also owns {@see uuid()} and
 * {@see quoteIdentifier()}, which this file uses and deliberately does not
 * redeclare. Same constraints as its host: no Laravel, no console code, nothing
 * autoloaded and nothing shipped.
 *
 * ## Why the rig needs shifts at all
 *
 * `/employee-performance` is a calendar of one person's month: the shifts they
 * worked and the sales they rang, on the same day. Seeding a year of sales and
 * no `login_history` leaves that screen showing a full month of sales under
 * "0 days worked" — the one reading that is worse than an empty rig, because it
 * looks like a bug in the page rather than an absence in the fixture.
 *
 * ## Shifts are DERIVED from the sales, never invented beside them
 *
 * A shift is planned from the events that person actually rang at that branch
 * that day: the sign-in lands shortly BEFORE their first one and the sign-out
 * shortly AFTER their last. So every sale on the calendar falls inside a shift,
 * which is the only property that makes the page worth looking at — a screen
 * showing ₱4,200 rung on a day nobody was signed in teaches the reader to
 * distrust it.
 *
 * That alignment is only possible because each branch has its OWN staff (see
 * `pg.php`'s `$branches['staff']`). When both branches drew from one pool, every
 * cashier rang sales at MNL and CEB on the same day, and a shift covering each
 * put one person in two places at once: under `?scope=all` the pairing then
 * closes the first branch's shift when the second branch's sign-in arrives, and
 * a year of the rig reads as a year of phantom open shifts. The fix belongs in
 * who rang the sale, not in how the shift is derived.
 *
 * ## The four shapes worth seeing
 *
 * A rig is for looking (docs/glossary.md §1), so the generator deliberately
 * produces the cases a plain 09:00–17:00 year never would:
 *
 *  - **A night shift** — 20:00 to around 04:00, with the sales to match. The
 *    branch trades through Friday and Saturday nights and one person works it
 *    (`RIG_NIGHT_NIGHTS` in tests/manual/sales.php, where the rota has to live).
 *    It is ONE shift on the day it started, which is what {@see RIG_DAY_START}
 *    is for, and its after-midnight sales land on the NEXT day's cell — the
 *    deliberate mismatch `EmployeePerformanceBuilder` documents, and until there
 *    were night shifts there was no way to see it.
 *  - **A shift that closes after midnight** ({@see RIG_LATE_CLOSE_RATE}) —
 *    locking up long after the last customer. A DAY shift, not a night one: it
 *    renders with the same `+1d` marker on a person who started at 07:30, which
 *    is the piece of calendar arithmetic that has no other way to be seen.
 *  - **A shift with no sign-out** ({@see RIG_UNCLEAN_EXIT_RATE}) — the app did
 *    not close cleanly, so the `application_crash` row is written at the NEXT
 *    launch carrying that moment, exactly as `SessionManager::markUncleanExit()`
 *    does. It contributes no hours, and the month says how many it excluded.
 *  - **A day worked with no sales** ({@see RIG_OFFICE_VISIT_RATE}) — the Owner
 *    or Admin signing in to read a report. Hours with no transactions beside
 *    them is a real shape the page has to render.
 *
 * What it still cannot produce is a DAY OFF. There is now a rota — the night
 * one, which is what took the night person off the day they work — but each
 * branch has two people and a dozen sales a day drawn between them, so somebody
 * rings something every day of the year and a shift has to cover it. Every month
 * therefore reads as 30-odd days worked, which is the one place this fixture is
 * still visibly a fixture. Days off would be the same trick as nights: decide
 * who is on in the sales generator, before deciding who sold.
 */

/**
 * The hour a business day begins. Anything earlier belongs to the night before.
 *
 * A night shift's after-midnight sales are the same shift as its before-midnight
 * ones, and keying them on the calendar date splits one night into two: the
 * second signs in while the first is still open, and `AttendanceFigures` closes
 * the first as an open shift with no hours. A year of that is the phantom open
 * shifts described above, from the other direction.
 *
 * A no-op for day trading, whose earliest sale is 08:00.
 */
const RIG_DAY_START = 6;

/** Minutes before the first sale that a shift starts. */
const RIG_SHIFT_LEAD = [15, 75];

/** Minutes after the last sale that a shift ends. */
const RIG_SHIFT_TAIL = [10, 90];

/** Percentage of shifts that end with no sign-out at all. */
const RIG_UNCLEAN_EXIT_RATE = 4;

/** Percentage of shifts that close after midnight. */
const RIG_LATE_CLOSE_RATE = 3;

/** Percentage of a non-cashier's sale-less days that carry a short session anyway. */
const RIG_OFFICE_VISIT_RATE = 10;

/** Rows per multi-row INSERT, for the same reason {@see RIG_INSERT_CHUNK} exists. */
const RIG_SESSION_CHUNK = 1000;

/** What the app writes on the row it reconstructs at the next launch. */
const RIG_UNCLEAN_EXIT_NOTE = 'Signed out: the app did not close cleanly (detected on next launch).';

const RIG_SESSION_COLUMNS = ['id', 'branch_id', 'user_id', 'event_type', 'occurred_at', 'notes', 'created_at'];

/**
 * A branch's year of shifts, planned from its year of events.
 *
 * One shift per person per BUSINESS day ({@see rigBusinessDay()}), spanning
 * everything they did in it — the rig has one device per branch
 * (docs/glossary.md §"Till"), so a person signing in twice in a day is a shape
 * it cannot produce anyway.
 *
 * Two people posted to one branch therefore have OVERLAPPING sessions on one
 * device, which a real till could not produce: it holds one session at a time
 * and they would take turns. Deriving those turns would mean deriving them from
 * the sales, and the seeded year picks its cashier per SALE at random within a
 * day, so the runs alternate every receipt — seven ninety-minute sessions a day
 * each, which is a worse fixture for the screen this exists to fill. The fiction
 * is in the attribution, not in the shift. (A night is the one place the rota is
 * real: the person on it is the only one selling, so their session genuinely has
 * the till to itself.)
 *
 * @param  list<array<string, mixed>>  $events  A planned year, as rigPlanYear() returns it.
 * @param  list<array{id: string, weight: int, role: string}>  $staff  This branch's own staff.
 * @return list<array{user: string, in: DateTimeImmutable, last: DateTimeImmutable, out: ?DateTimeImmutable}> Chronological by sign-in.
 */
function rigPlanShifts(array $events, array $staff, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    // [business day][user id] => [first event, last event]
    $worked = [];

    foreach ($events as $event) {
        $day = rigBusinessDay($event['at']);
        $user = $event['user'];

        if (! isset($worked[$day][$user])) {
            $worked[$day][$user] = [$event['at'], $event['at']];

            continue;
        }

        // The plan is chronological, but a return is merged in by timestamp
        // rather than appended, so neither end is safe to assume.
        if ($event['at'] < $worked[$day][$user][0]) {
            $worked[$day][$user][0] = $event['at'];
        }

        if ($event['at'] > $worked[$day][$user][1]) {
            $worked[$day][$user][1] = $event['at'];
        }
    }

    $shifts = [];

    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $date = $day->format('Y-m-d');

        foreach ($staff as $member) {
            $span = $worked[$date][$member['id']] ?? null;

            if ($span === null) {
                $visit = rigOfficeVisit($member, $day, $end);

                if ($visit !== null) {
                    $shifts[] = $visit;
                }

                continue;
            }

            $shifts[] = rigShiftAround($member['id'], $span[0], $span[1], $end);
        }
    }

    usort($shifts, static fn (array $a, array $b): int => $a['in'] <=> $b['in']);

    return $shifts;
}

/**
 * The date of the business day a moment falls in — the calendar date, except
 * before {@see RIG_DAY_START}, which still belongs to the night before.
 */
function rigBusinessDay(DateTimeImmutable $at): string
{
    return ((int) $at->format('G') < RIG_DAY_START ? $at->modify('-1 day') : $at)->format('Y-m-d');
}

/**
 * One shift wrapped around the events it has to cover.
 *
 * The sign-in is always before the first sale and the sign-out (when there is
 * one) always after the last, which is what makes every seeded sale fall inside
 * a shift on the calendar.
 *
 * @return array{user: string, in: DateTimeImmutable, last: DateTimeImmutable, out: ?DateTimeImmutable}
 */
function rigShiftAround(string $user, DateTimeImmutable $first, DateTimeImmutable $last, DateTimeImmutable $end): array
{
    $in = $first->modify('-'.mt_rand(RIG_SHIFT_LEAD[0], RIG_SHIFT_LEAD[1]).' minutes');

    $out = match (true) {
        // No sign-out at all: the app was killed, and the row that says so is
        // written at the next launch by rigWriteShifts().
        mt_rand(1, 100) <= RIG_UNCLEAN_EXIT_RATE => null,
        // Closing up long after the last customer — the shift belongs to the day
        // it started and renders with a +1d marker. Only a shift still on its
        // own day can close late: a night shift's last sale is already tomorrow
        // morning, and +1 day from there is a sign-out a full day after the
        // work. The roll is drawn first so every shift costs the same draws.
        mt_rand(1, 100) <= RIG_LATE_CLOSE_RATE
            && $last->format('Y-m-d') === $first->format('Y-m-d') => $last->modify('+1 day')->setTime(0, mt_rand(5, 50)),
        default => $last->modify('+'.mt_rand(RIG_SHIFT_TAIL[0], RIG_SHIFT_TAIL[1]).' minutes'),
    };

    // A sign-out in the future is not a thing that has happened. Today's shift
    // is still running, which is exactly what the app would show.
    return [
        'user' => $user,
        'in' => $in,
        // Carried so an unclean exit can be detected AFTER the work it ended,
        // never in the middle of it — see rigWriteShifts().
        'last' => $last,
        'out' => $out !== null && $out <= $end ? $out : null,
    ];
}

/**
 * A short session on a day this person rang nothing — the Owner or Admin opening
 * the app to read a report. Null most days, and always for a cashier.
 *
 * @param  array{id: string, weight: int, role: string}  $member
 * @return array{user: string, in: DateTimeImmutable, last: DateTimeImmutable, out: ?DateTimeImmutable}|null
 */
function rigOfficeVisit(array $member, DateTimeImmutable $day, DateTimeImmutable $end): ?array
{
    if ($member['role'] === 'cashier' || mt_rand(1, 100) > RIG_OFFICE_VISIT_RATE) {
        return null;
    }

    $in = $day->setTime(mt_rand(8, 16), mt_rand(0, 59));
    $out = $in->modify('+'.mt_rand(30, 90).' minutes');

    if ($in > $end) {
        return null;
    }

    return ['user' => $member['id'], 'in' => $in, 'last' => $in, 'out' => $out <= $end ? $out : null];
}

/**
 * Write a planned year of shifts, and return how many rows it took.
 *
 * A shift with no sign-out gets its `application_crash` row at the NEXT launch
 * of this branch's device — whoever signs in next — carrying that moment and the
 * app's own note, because that is when the app notices and all it can honestly
 * say. The next launch AFTER the work ended, specifically: sessions on one
 * device overlap here (see {@see rigPlanShifts()}), and a crash row stamped
 * between two of this person's own sales would read as the app noticing an exit
 * that had not happened yet.
 *
 * A shift with nothing after it is left open: the person is still signed in, or
 * the device has not been back.
 *
 * @param  array{id: string, code: string}  $branch
 * @param  list<array{user: string, in: DateTimeImmutable, last: DateTimeImmutable, out: ?DateTimeImmutable}>  $shifts
 */
function rigWriteShifts(PDO $pdo, array $branch, array $shifts): int
{
    $buffer = [];
    $written = 0;

    foreach ($shifts as $index => $shift) {
        $buffer[] = rigSessionRow($branch, $shift['user'], 'login', $shift['in'], null);

        if ($shift['out'] !== null) {
            $buffer[] = rigSessionRow($branch, $shift['user'], 'logout', $shift['out'], null);
        } elseif (($detected = rigNextLaunchAfter($shifts, $index, $shift['last'])) !== null) {
            // Two seconds before the launch that detected it, so the row sorts
            // ahead of the sign-in it was written beside. These columns are
            // `timestamp(0) with time zone`, so whole seconds are all there are.
            $buffer[] = rigSessionRow(
                $branch,
                $shift['user'],
                'application_crash',
                $detected->modify('-2 seconds'),
                RIG_UNCLEAN_EXIT_NOTE,
            );
        }

        if (count($buffer) >= RIG_SESSION_CHUNK) {
            $written += rigFlushSessions($pdo, $buffer);
        }
    }

    return $written + rigFlushSessions($pdo, $buffer);
}

/**
 * When this device was next started after $after — the moment an unclean exit
 * would have been noticed. Null when nothing followed it.
 *
 * @param  list<array{user: string, in: DateTimeImmutable, last: DateTimeImmutable, out: ?DateTimeImmutable}>  $shifts
 */
function rigNextLaunchAfter(array $shifts, int $index, DateTimeImmutable $after): ?DateTimeImmutable
{
    for ($i = $index + 1, $n = count($shifts); $i < $n; $i++) {
        if ($shifts[$i]['in'] > $after) {
            return $shifts[$i]['in'];
        }
    }

    return null;
}

/**
 * @return list<mixed>
 */
function rigSessionRow(array $branch, string $user, string $event, DateTimeImmutable $at, ?string $notes): array
{
    $stamp = rigStamp($at);

    // occurred_at and created_at are the same instant here. They diverge in the
    // app only for an event reconstructed after the fact, and the row above that
    // does that is stamped with the moment of detection for both.
    return [uuid(), $branch['id'], $user, $event, $stamp, $notes, $stamp];
}

/**
 * @param  list<list<mixed>>  $buffer
 */
function rigFlushSessions(PDO $pdo, array &$buffer): int
{
    if ($buffer === []) {
        return 0;
    }

    $row = '('.implode(',', array_fill(0, count(RIG_SESSION_COLUMNS), '?')).')';

    $sql = 'insert into '.quoteIdentifier('login_history')
        .' ('.implode(',', array_map('quoteIdentifier', RIG_SESSION_COLUMNS)).') values '
        .implode(',', array_fill(0, count($buffer), $row));

    $pdo->prepare($sql)->execute(array_merge(...$buffer));

    $written = count($buffer);
    $buffer = [];

    return $written;
}
