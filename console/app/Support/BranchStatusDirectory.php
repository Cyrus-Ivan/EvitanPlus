<?php

namespace Provisioning\Support;

use stdClass;

/**
 * What every branch of a business last said about itself (§14, M10).
 *
 * The read behind `monitor`, and the counterpart to {@see BranchState}: that one
 * reports what the CONSOLE did to a branch's credential, this one reports what
 * the BRANCH is doing with it. Both are needed on the same screen, because the
 * two most common answers to "why has this till gone quiet" are "its internet is
 * down" and "you disabled it", and only one of those is visible from either
 * half alone.
 *
 * ## Left join, never inner
 *
 * A branch with no `branch_status` row has never completed a cycle — a till
 * still in its box, a device that has never been online, or a wizard that was
 * run and abandoned. That is the single most important row this command can
 * print, and an inner join would delete it. Everything downstream therefore
 * treats a null heartbeat as a value ("never") rather than as an absence.
 *
 * ## Nothing here is cached or mirrored
 *
 * Read live through the admin connection on every invocation, like
 * {@see BusinessDirectory}. A monitor showing a remembered figure is worse than
 * no monitor: the number it is remembering is precisely the one that goes stale.
 */
final class BranchStatusDirectory
{
    /**
     * Every branch of one business, oldest first, with its last heartbeat.
     *
     * @return list<array{state: BranchState, heartbeat: stdClass|null}>
     */
    public static function forSchema(string $schema, CredentialIssuance $issuance): array
    {
        $states = BranchState::forSchema($schema, $issuance);

        if ($states === []) {
            return [];
        }

        $heartbeats = self::heartbeats($schema);

        return array_map(fn (BranchState $state): array => [
            'state' => $state,
            'heartbeat' => $heartbeats[$state->id()] ?? null,
        ], $states);
    }

    /**
     * This schema's heartbeat rows, keyed by branch id.
     *
     * One query for the whole business rather than one per branch: the table
     * holds a single row per branch by construction, so it is small, and a
     * per-branch read would pin the schema once per row mid-listing.
     *
     * @return array<string, stdClass>
     */
    private static function heartbeats(string $schema): array
    {
        $rows = SchemaConnection::pin($schema)->select(
            'select branch_id, app_version, schema_version, unsynced_count,
                    last_sync_at, last_seen_at, connection_state, last_error
               from branch_status'
        );

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row->branch_id] = $row;
        }

        return $keyed;
    }
}
