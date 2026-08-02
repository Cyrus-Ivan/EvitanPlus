<?php

namespace Provisioning\Support;

/**
 * What a fully-migrated business schema declares about itself (§13, M9).
 *
 * Two numbers, and they are not the same kind of thing (docs/glossary.md §6):
 *
 *   SCHEMA_VERSION   integer   the state of the table structure
 *   MIN_APP_VERSION  semver    the oldest app build allowed to sync with it
 *
 * ## Why they live here and not in a migration
 *
 * They used to live inside migration 000001, which meant they could be SET but
 * never RAISED: that migration runs once per schema and never again, so a
 * twentieth migration would have left every provisioned business still claiming
 * version 1 with a floor of 1.0.0. The gate would then admit exactly the stale
 * builds it exists to refuse — a silent failure, because a gate that never
 * refuses looks identical to one with nothing to refuse.
 *
 * Now the migration seeds from here and `schema:migrate` re-stamps from here
 * after every successful run ({@see SchemaStamp}), so these constants are the
 * single declaration and one file is the whole edit.
 *
 * ## One of these is checked; the other is a judgement only you can make
 *
 * **SCHEMA_VERSION is the migration count**, and `SchemaVersionTest` asserts it.
 * There is nothing to remember: add a migration and the test fails until this
 * number matches. That is deliberate — the number's whole job is to let a human
 * (and M10's monitor) tell which structure a schema is in, and a version that
 * drifts from the files describes nothing. It is *recorded* on both sides and
 * never compared; only MIN_APP_VERSION is.
 *
 * **MIN_APP_VERSION cannot be checked and is the one to think about.** It means
 * "the oldest app build that can safely push to and pull from this schema", and
 * no static check can decide that — whether a change breaks an older build is a
 * claim about what that build's code does. So the test above exists mostly to
 * put you *here*, at the moment you are already editing this file:
 *
 *   - Raise it ONLY for a breaking change, and only to the first app version
 *     that can talk to the new shape.
 *   - An additive migration — a nullable column, a new table, a new index —
 *     must leave it alone. Raising it needlessly freezes the sync of every
 *     branch of every business until each device gets a new build; they keep
 *     selling, but nothing moves in either direction.
 *   - It must never exceed the version this repo can build
 *     (`config/nativephp.php`), or every branch freezes with no build to
 *     install. That one IS checked.
 *
 * Neither value may ever move backwards. SchemaStamp enforces that; this file is
 * where the mistake would be made.
 */
final class SchemaVersion
{
    /**
     * The number of migrations a fully-migrated business schema has applied.
     *
     * Asserted against {@see migrationCount()}, so this is not a number you
     * choose — it is one you keep in step, and the test says so when you don't.
     */
    public const SCHEMA_VERSION = 19;

    /** Raise ONLY for a breaking change. See the class docblock. */
    public const MIN_APP_VERSION = '1.0.0';

    /**
     * The business migration set on disk.
     *
     * `database/migrations` only — `migrations-admin` builds the console's own
     * schema, which no business schema has ever heard of and which versions
     * separately.
     */
    public static function migrationCount(): int
    {
        return count(glob(self::migrationPath().'/*.php') ?: []);
    }

    public static function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }
}
