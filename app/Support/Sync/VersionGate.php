<?php

namespace App\Support\Sync;

use App\Exceptions\SetupFailedException;
use App\Exceptions\VersionBlockedException;
use Illuminate\Database\Connection;

/**
 * The version gate (MULTIBRANCH-BRAINSTORM.md §13, docs/glossary.md §6).
 *
 * Three quantities are in play and the gate compares exactly one pair:
 *
 *   app version      semver, this build          config('nativephp.version')
 *   schema version   integer, the table shapes   schema_meta.schema_version
 *   min app version  semver, declared by the schema   schema_meta.min_app_version
 *
 *   the gate is:  APP VERSION >= MIN APP VERSION,  and nothing else.
 *
 * The schema version is RECORDED by both sides and never compared — it exists so
 * a human can tell which migration state a schema is in. Confusing it for the
 * gate is the easiest way to build this wrong, and §13 said so in words that
 * named neither of the compared values until the vocabulary pass fixed it.
 *
 * Failing the gate refuses sync in BOTH directions: a stale push writes
 * old-format rows into a migrated Postgres, and a new-format pull writes columns
 * the local SQLite has not got. Both are corruption. The till keeps selling
 * throughout — this class throws inside a cycle, and a failed cycle has never
 * stopped a sale.
 *
 * Additive migrations must leave min_app_version alone; only a breaking one
 * raises it. That is a discipline on the console's migrations, not something
 * this class can check.
 */
class VersionGate
{
    /**
     * Read the schema's version pair over an open branch connection.
     *
     * Read LIVE, never taken from the credentials JSON: a schema migrated since
     * the file was issued still carries the old numbers there (§9).
     *
     * @return array{schema_version: int, min_app_version: string}
     *
     * @throws SetupFailedException
     */
    public function read(Connection $remote): array
    {
        $meta = $remote->selectOne('select schema_version, min_app_version from schema_meta');

        if ($meta === null) {
            throw new SetupFailedException(
                'The business database has no schema_meta row, so its version cannot be established. '
                .'The schema is incomplete — contact the developer who provisioned it.'
            );
        }

        return [
            'schema_version' => (int) $meta->schema_version,
            'min_app_version' => (string) $meta->min_app_version,
        ];
    }

    /**
     * @throws VersionBlockedException When this build is older than the schema allows.
     */
    public function assert(string $minAppVersion): void
    {
        $appVersion = $this->appVersion();

        if (version_compare($appVersion, $minAppVersion, '<')) {
            throw new VersionBlockedException($appVersion, $minAppVersion);
        }
    }

    public function appVersion(): string
    {
        return (string) config('nativephp.version');
    }
}
