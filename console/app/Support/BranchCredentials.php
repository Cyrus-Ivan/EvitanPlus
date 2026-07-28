<?php

namespace Provisioning\Support;

use RuntimeException;

/**
 * The credentials JSON (MULTIBRANCH-BRAINSTORM.md §9) — the one delivery
 * artifact provisioning produces, and the input to M5's setup wizard.
 *
 * ## Handling rules this class enforces
 *
 * The file holds a live database credential in plaintext, so:
 *  - it is created with fopen('x'), which fails if the path exists — a
 *    credential file is never silently overwritten;
 *  - it is chmod 0600 and its directory 0700, before a single byte is written;
 *  - it is RESERVED before any database work begins. Provisioning takes ~13
 *    seconds and ends by minting a password that exists nowhere else; finding
 *    out then that the path is unwritable would strand a live role whose only
 *    credential is gone.
 *
 * §9 also makes the file one-time: after import the wizard prompts to delete
 * it. Nothing here can enforce that, but the default location
 * (console/storage/app/credentials) is git-ignored and inside the console app,
 * which is pruned from every build (§18).
 *
 * ## Shape
 *
 * The `connection` block deliberately uses the shipped app's own key names from
 * `config('database.connections.pgsql')`, so M5's import is an assignment rather
 * than a translation. It is a **subset** of that array, not the whole of it —
 * `url`, `charset`, `prefix` and `prefix_indexes` are absent — so the import
 * MERGES this block over the config array. A wholesale assignment would drop
 * those keys, and would silently discard any driver `options` a later fix adds:
 * the console suite would stay green while every shipped till lost them.
 *
 * `format`/`format_version` let that importer reject a document shape it does
 * not know instead of half-reading it.
 *
 * The OWNER's password is not in here. §9 does not list it, it is not needed to
 * connect, and the business owner's login has no business sitting in a file on
 * a developer's laptop.
 */
final class BranchCredentials
{
    public const FORMAT = 'evitan.branch-credentials';

    public const FORMAT_VERSION = 1;

    /** The default directory, relative to the console app root. */
    public const DEFAULT_DIRECTORY = 'storage/app/credentials';

    /** @var resource|null */
    private mixed $handle = null;

    private ?string $path = null;

    /**
     * Claim the path exclusively, so provisioning fails here rather than after
     * it has created a role nobody can use.
     */
    public function reserve(string $path): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the credentials directory [{$directory}].");
        }

        // 'x' — exclusive create. An existing file is a refusal, never an
        // overwrite: the file it would replace may be the only copy of a
        // credential already delivered to a branch.
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException(
                file_exists($path)
                    ? "[{$path}] already exists; choose another --out path."
                    : "Could not create [{$path}]."
            );
        }

        @chmod($path, 0600);

        $this->handle = $handle;
        $this->path = $path;

        return $path;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function write(array $document): string
    {
        if ($this->handle === null || $this->path === null) {
            throw new RuntimeException('Reserve a credentials path before writing to it.');
        }

        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        if (fwrite($this->handle, $json.PHP_EOL) === false) {
            throw new RuntimeException("Could not write to [{$this->path}].");
        }

        fflush($this->handle);
        fclose($this->handle);
        $this->handle = null;

        return $this->path;
    }

    /**
     * Remove the reserved file — part of the compensating cleanup, so a rolled
     * back provision leaves no credential for a role that no longer exists.
     */
    public function discard(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }

        $this->path = null;
    }

    /**
     * Build the document. Every value is either operator input, a generated
     * secret, or read back from the schema — nothing is assumed.
     *
     * @param  array<string, mixed>  $business  id, name, schema
     * @param  array<string, mixed>  $branch  id, code, name, address
     * @param  array<string, mixed>  $versions  schema_version, min_app_version
     * @return array<string, mixed>
     */
    public static function document(
        array $business,
        array $branch,
        SupabaseEndpoint $endpoint,
        string $role,
        string $password,
        array $versions,
    ): array {
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'issued_at' => now()->toIso8601String(),

            'business' => [
                'id' => $business['id'],
                'name' => $business['name'],
                'schema' => $business['schema'],
            ],

            'branch' => [
                'id' => $branch['id'],
                'code' => $branch['code'],
                'name' => $branch['name'],
                'address' => $branch['address'],
            ],

            // The shipped app's own pgsql key names (see the class docblock) so
            // M5 assigns rather than translates — but a SUBSET of them, so M5
            // merges this block OVER config('database.connections.pgsql') rather
            // than replacing it. See §9.
            'connection' => [
                'driver' => 'pgsql',
                'host' => $endpoint->host,
                // The transaction pooler, not the session pooler the console
                // administers through (§7). Verified below by connecting as the
                // role over this exact endpoint before the JSON is reported good.
                'port' => $endpoint->port,
                'database' => $endpoint->database,
                'username' => $endpoint->loginName($role),
                'password' => $password,
                // NULL on purpose, and M5 must assign it as-is rather than
                // substituting business.schema. The schema is pinned server-side
                // by `ALTER ROLE ... SET search_path` (BranchRole::applyGrants),
                // which Postgres applies at backend startup — so it reaches every
                // pooled backend. A client-side `SET` would run on whichever
                // backend Supavisor checked out and then handed back, which under
                // transaction pooling means it lands nowhere useful. Null is also
                // inert: PostgresConnector::configureSearchPath fires on isset().
                'search_path' => null,
                'sslmode' => $endpoint->sslmode,
            ],

            // The bare Postgres role, which is what M3's rotate/disable/delete
            // operate on. It differs from connection.username whenever the
            // endpoint is a pooler, so both are carried explicitly.
            'role' => [
                'name' => $role,
            ],

            // Read back from schema_meta, never assumed: this is what M5's
            // version gate compares against the app's own version.
            'versions' => [
                'schema_version' => (int) $versions['schema_version'],
                'min_app_version' => (string) $versions['min_app_version'],
            ],
        ];
    }

    /**
     * A filename that says which business, which branch and when — so a folder
     * of these is self-describing during delivery.
     */
    public static function filename(string $schema, string $code): string
    {
        return $schema.'-'.$code.'-'.now()->utc()->format('Ymd\THis\Z').'.json';
    }
}
