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
 * ## The discipline, which nothing can check for you
 *
 * **Raise SCHEMA_VERSION whenever you add a migration.** It is what tells a
 * human — and M10's monitor — which structure a schema is actually in. Nothing
 * compares it; it is recorded on both sides and that is its entire job.
 *
 * **Raise MIN_APP_VERSION only for a BREAKING change**, and set it to the first
 * app version that can talk to the new shape. This is the one the gate compares,
 * and raising it freezes every branch of every business below it on their next
 * cycle — they keep selling, but nothing syncs in either direction until each
 * device gets a new build. An additive migration (a new nullable column, a new
 * table, a new index) must leave it alone, or every routine update needlessly
 * freezes the whole estate.
 *
 * Neither value may ever move backwards. SchemaStamp enforces that; this file is
 * where the mistake would be made.
 */
final class SchemaVersion
{
    /** Raise on every migration added to console/database/migrations. */
    public const SCHEMA_VERSION = 1;

    /** Raise ONLY for a breaking change. See the class docblock. */
    public const MIN_APP_VERSION = '1.0.0';
}
