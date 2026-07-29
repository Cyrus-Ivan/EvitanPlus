<?php

namespace App\Support\Sync;

use App\Exceptions\SetupFailedException;

/**
 * The credentials JSON the provisioning console emits, read back
 * (MULTIBRANCH-BRAINSTORM.md §9).
 *
 * ## It is a versioned envelope, so half-reading one is not an option
 *
 * `format` + `format_version` exist precisely so this class can refuse a shape
 * it does not recognise. A future document that moves a key would otherwise be
 * read as a valid document with a missing value, and the failure would surface
 * as a connection error on a customer's device rather than as "this file is
 * newer than this build".
 *
 * ## Two values that look like one
 *
 * `connection.username` is what you authenticate WITH — through Supavisor that
 * is `<role>.<project-ref>` — while `role.name` is the bare Postgres role the
 * console's rotate/disable/delete operate on. They differ whenever the endpoint
 * is a pooler, which is always. Collapsing them is a bug that only shows up in
 * the field, so both are carried, separately, all the way through.
 *
 * ## The connection block is assigned, never translated
 *
 * It already uses the shipped app's own `config('database.connections.pgsql')`
 * key names, and `search_path` is null ON PURPOSE — the schema is pinned
 * server-side as a role default, which is the only pin that survives transaction
 * pooling. Substituting `business.schema` here would emit a client-side SET on
 * whichever backend Supavisor happened to hand over. See {@see BranchCycle}, which
 * merges this block over the config rather than replacing it.
 */
final class CredentialsDocument
{
    public const FORMAT = 'evitan.branch-credentials';

    public const FORMAT_VERSION = 1;

    /**
     * @param  array<string, mixed>  $document
     */
    private function __construct(private readonly array $document) {}

    /**
     * Read and validate the document at $path.
     *
     * @throws SetupFailedException
     */
    public static function fromFile(string $path): self
    {
        if ($path === '' || ! is_file($path)) {
            throw new SetupFailedException("No file found at [{$path}]. Check the path and try again.");
        }

        if (! is_readable($path)) {
            throw new SetupFailedException("[{$path}] cannot be read. Check the file's permissions.");
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new SetupFailedException("[{$path}] could not be read.");
        }

        return self::fromString($contents, $path);
    }

    /**
     * @throws SetupFailedException
     */
    public static function fromString(string $contents, string $label = 'the credentials file'): self
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new SetupFailedException("[{$label}] is not valid JSON. Use the file the provisioning console produced, unedited.");
        }

        $format = $decoded['format'] ?? null;

        if ($format !== self::FORMAT) {
            throw new SetupFailedException(
                "[{$label}] is not an Evitan branch credentials file. Expected a document with format \""
                .self::FORMAT.'".'
            );
        }

        $version = $decoded['format_version'] ?? null;

        if ($version !== self::FORMAT_VERSION) {
            throw new SetupFailedException(
                "[{$label}] is credentials format version ".var_export($version, true)
                .', and this build only understands version '.self::FORMAT_VERSION
                .'. Install a newer build, or ask for a credentials file in the older format.'
            );
        }

        foreach (['business' => ['id', 'name', 'schema'],
            'branch' => ['id', 'code', 'name'],
            'connection' => ['driver', 'host', 'port', 'database', 'username', 'password'],
            'role' => ['name'],
            'versions' => ['schema_version', 'min_app_version']] as $block => $keys) {
            if (! isset($decoded[$block]) || ! is_array($decoded[$block])) {
                throw new SetupFailedException("[{$label}] is missing its \"{$block}\" section.");
            }

            foreach ($keys as $key) {
                if (! isset($decoded[$block][$key]) || $decoded[$block][$key] === '') {
                    throw new SetupFailedException("[{$label}] is missing \"{$block}.{$key}\".");
                }
            }
        }

        return new self($decoded);
    }

    /**
     * The pgsql connection block, exactly as issued.
     *
     * `search_path` is included as-is even though it is null: the key's presence
     * with a null value is what keeps PostgresConnector from emitting any SET at
     * all, and dropping the key would fall back to whatever the config default
     * happens to be.
     *
     * @return array<string, mixed>
     */
    public function connection(): array
    {
        /** @var array<string, mixed> $connection */
        $connection = $this->document['connection'];

        $connection['search_path'] ??= null;

        return $connection;
    }

    /** @return array{id: string, name: string, schema: string} */
    public function business(): array
    {
        return [
            'id' => (string) $this->document['business']['id'],
            'name' => (string) $this->document['business']['name'],
            'schema' => (string) $this->document['business']['schema'],
        ];
    }

    /** @return array{id: string, code: string, name: string, address: string|null} */
    public function branch(): array
    {
        return [
            'id' => (string) $this->document['branch']['id'],
            'code' => (string) $this->document['branch']['code'],
            'name' => (string) $this->document['branch']['name'],
            'address' => isset($this->document['branch']['address'])
                ? (string) $this->document['branch']['address']
                : null,
        ];
    }

    /**
     * The version pair as ISSUED — a record of what the schema looked like when
     * the file was written, not an authority. The gate reads schema_meta live,
     * because a schema migrated since issuance would still be carrying the old
     * numbers here.
     *
     * @return array{schema_version: int, min_app_version: string}
     */
    public function issuedVersions(): array
    {
        return [
            'schema_version' => (int) $this->document['versions']['schema_version'],
            'min_app_version' => (string) $this->document['versions']['min_app_version'],
        ];
    }

    /** The bare Postgres role — NOT the pooler login name. */
    public function roleName(): string
    {
        return (string) $this->document['role']['name'];
    }

    /** The secret itself, used only to compute the fingerprint the console can match. */
    public function password(): string
    {
        return (string) $this->document['connection']['password'];
    }

    public function issuedAt(): ?string
    {
        return isset($this->document['issued_at']) ? (string) $this->document['issued_at'] : null;
    }
}
