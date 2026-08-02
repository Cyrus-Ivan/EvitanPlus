<?php

namespace Provisioning\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Write the declared version pair into a schema's `schema_meta` (M9, §13).
 *
 * The act, as against the three quantities it moves — `docs/glossary.md` §6
 * calls this the **version stamp**. It is the only thing in this project that
 * ever changes `schema_meta` after the schema is created, which is why it is a
 * class of its own rather than four lines inside `schema:migrate`: the rules
 * below are the load-bearing part, and they are testable without a migrator run.
 *
 * ## Only when it changed
 *
 * `applied_at` means "when this schema reached this version", not "when someone
 * last ran the command". An unconditional UPDATE would destroy that distinction
 * on the very command an operator runs repeatedly and idempotently, and
 * `applied_at` is on the business screen precisely so they can see whether a
 * schema has moved recently.
 *
 * ## Never backwards
 *
 * A schema whose recorded pair is AHEAD of {@see SchemaVersion} was migrated by
 * a newer console checkout than the one now running. Stamping it down would
 * un-freeze branches that a breaking migration deliberately froze — the gate
 * would then admit builds that cannot read the tables in front of them, and the
 * corruption the gate exists to prevent happens on the next cycle. So this
 * refuses, loudly, and the operator updates their checkout.
 *
 * The two numbers are compared with different operators on purpose:
 * `schema_version` is an integer and `min_app_version` is semver, and comparing
 * semver as a string is the classic error ('1.10.0' sorts before '1.9.0').
 */
final class SchemaStamp
{
    /**
     * The pair a schema currently records, or null when it has no row.
     *
     * Null is a real state, not an error: a schema whose migration set died
     * before 000001 committed has no `schema_meta` at all, and `schema:migrate`
     * is the command that fixes it.
     *
     * @return array{schema_version: int, min_app_version: string}|null
     */
    public function read(Connection $connection): ?array
    {
        $meta = $connection->selectOne('select schema_version, min_app_version from schema_meta');

        if ($meta === null) {
            return null;
        }

        return [
            'schema_version' => (int) $meta->schema_version,
            'min_app_version' => (string) $meta->min_app_version,
        ];
    }

    /**
     * Bring `schema_meta` up to the declared pair.
     *
     * `from` is never null here — a schema with no row throws below rather than
     * being stamped from nothing, so callers may report the transition without
     * guarding for its absence.
     *
     * @return array{
     *     from: array{schema_version: int, min_app_version: string},
     *     to: array{schema_version: int, min_app_version: string},
     *     changed: bool,
     *     min_app_raised: bool,
     * }
     *
     * @throws RuntimeException When the schema is ahead of this checkout, or has no row to stamp.
     */
    public function apply(Connection $connection): array
    {
        $from = $this->read($connection);
        $to = $this->declared();

        if ($from === null) {
            throw new RuntimeException(
                'This schema has no schema_meta row to stamp, so its version cannot be recorded. '
                .'The migration set did not complete — re-run this command.'
            );
        }

        $this->refuseDowngrade($from, $to);

        $changed = $from !== $to;

        if ($changed) {
            $connection->table('schema_meta')->where('id', 1)->update([
                'schema_version' => $to['schema_version'],
                'min_app_version' => $to['min_app_version'],
                'applied_at' => now(),
            ]);
        }

        return [
            'from' => $from,
            'to' => $to,
            'changed' => $changed,
            'min_app_raised' => version_compare($to['min_app_version'], $from['min_app_version'], '>'),
        ];
    }

    /**
     * @return array{schema_version: int, min_app_version: string}
     */
    public function declared(): array
    {
        return [
            'schema_version' => SchemaVersion::SCHEMA_VERSION,
            'min_app_version' => SchemaVersion::MIN_APP_VERSION,
        ];
    }

    /**
     * @param  array{schema_version: int, min_app_version: string}  $from
     * @param  array{schema_version: int, min_app_version: string}  $to
     *
     * @throws RuntimeException
     */
    private function refuseDowngrade(array $from, array $to): void
    {
        $behind = [];

        if ($from['schema_version'] > $to['schema_version']) {
            $behind[] = "schema version {$from['schema_version']} against this checkout's {$to['schema_version']}";
        }

        if (version_compare($from['min_app_version'], $to['min_app_version'], '>')) {
            $behind[] = "min app version {$from['min_app_version']} against this checkout's {$to['min_app_version']}";
        }

        if ($behind === []) {
            return;
        }

        throw new RuntimeException(
            'This schema is AHEAD of the console running against it — it records '
            .implode(' and ', $behind).'. It was migrated by a newer checkout; '
            .'stamping it down would un-freeze branches a breaking migration froze. '
            .'Update this console checkout and run again.'
        );
    }
}
