<?php

namespace Provisioning\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Turns validated operator input into a live, connectable business (§8.1).
 *
 * ## Why this is not one transaction
 *
 * The roadmap says "on submit (transactional)", and every individual step here
 * is. The whole sequence cannot be: Laravel's migrator commits per migration,
 * CREATE ROLE is cluster-global on a different connection than the seed, and
 * writing the credentials file is not a database operation at all. So atomicity
 * is achieved the only way it can be — an undo stack, unwound in reverse on any
 * failure, leaving nothing behind.
 *
 * That compensation is only safe because provisioning is CREATE-ONLY: the
 * schema must not exist beforehand, so "drop it" can never destroy data this
 * run did not create. Adding a branch to an existing business is a separate
 * command (M3) precisely so this one keeps that guarantee.
 *
 * ## Ordering that matters
 *
 * The credentials file is reserved BEFORE any database work: the sequence ends
 * by minting a password that exists nowhere else, and discovering an unwritable
 * path at that point would strand a live role whose only credential is gone.
 *
 * Verification happens BEFORE success is reported: the provisioner connects as
 * the branch role it just created and reads the seeded rows. The milestone is a
 * JSON a client can actually use, so it is proven, not assumed.
 */
final class BusinessProvisioner
{
    public const ACTION = 'business.provision';

    /** @var list<array{label: string, undo: Closure}> */
    private array $undo = [];

    private BranchCredentials $credentials;

    public function __construct(
        private readonly ProvisioningAudit $audit,
        private readonly CredentialIssuance $issuance,
        private readonly ?OutputInterface $output = null,
    ) {
        $this->credentials = new BranchCredentials;
    }

    /**
     * Everything that must be true before anything is created.
     *
     * Separate from provision() so the command can fail with zero side effects,
     * including before it has prompted for a 13-field flow.
     *
     * @return list<string> Human-readable reasons to refuse; empty means go.
     */
    public function preflight(string $schema, string $role): array
    {
        $problems = [];

        if (! $this->audit->isReady()) {
            $problems[] = 'The provisioning audit store is missing — run: php console/artisan console:migrate';
        }

        // The same hard precondition BranchIssuer::preflight applies, and for a
        // stronger reason here: this is where a branch's FIRST credential is
        // minted, and an issuance that goes unrecorded can never be
        // reconstructed — the secret is written to one file and kept nowhere.
        if (! $this->issuance->isReady()) {
            $problems[] = 'The credential issuance record is missing — run: php console/artisan console:migrate';
        }

        if (SchemaConnection::exists($schema)) {
            $problems[] = "Schema [{$schema}] already exists. Provisioning a business is create-only; "
                .'adding a branch to an existing business is a separate command (Phase M3). '
                .'If this is an orphan from an interrupted run, remove it first: '
                .implode(' ', BranchRole::cleanupSql($role, $schema));
        }

        if (BranchRole::exists($role)) {
            $problems[] = "Postgres role [{$role}] already exists.";
        }

        return $problems;
    }

    /**
     * Create everything, or nothing.
     *
     * @param  array<string, mixed>  $data  Validated input; see BusinessProvisionCommand.
     * @param  Closure(string): void  $step  Progress reporter.
     * @return array<string, mixed> The provisioning summary.
     *
     * @throws RuntimeException Carrying the original failure and the cleanup outcome.
     */
    public function provision(array $data, Closure $step): array
    {
        // Both identities are minted by the caller, before the preflight, so the
        // schema and role names checked for collisions are the ones actually used.
        $businessId = (string) ($data['business_id'] ?? Str::orderedUuid());
        $branchId = (string) ($data['branch_id'] ?? Str::orderedUuid());

        $schema = SchemaName::forBusiness($businessId);
        $role = RoleName::forBranch($branchId);
        $code = BranchCode::validate((string) $data['branch_code']);

        $endpoint = SupabaseEndpoint::forBranch();
        $password = BranchSecret::generate();

        $target = [
            'business_id' => $businessId,
            'schema_name' => $schema,
            'branch_id' => $branchId,
            'branch_code' => $code,
            'role_name' => $role,
        ];

        // Before any DDL: if the artifact cannot be written, nothing should be
        // created for it to describe.
        $step('Reserving credentials file');
        $path = $this->credentials->reserve($this->credentialsPath($data, $schema, $code));
        $this->pushUndo('credentials file', fn () => $this->credentials->discard());

        try {
            // Registered BEFORE the schema is created, not after it is migrated.
            // schema:migrate creates the schema and then applies every migration
            // that the migrator commits one at a time, so a failure anywhere in
            // that run leaves a half-built schema behind. Pushing the undo
            // afterwards left that whole window uncompensated — and the rollback
            // would then report "Nothing was left behind" over an orphan the
            // create-only preflight refuses on the retry. The drop is
            // `if exists`, so a no-op undo costs nothing.
            $this->pushUndo("schema {$schema}", fn () => $this->dropSchema($schema));

            $step("Creating and migrating schema [{$schema}]");
            $this->migrateSchema($schema);

            $step('Seeding owner, business settings and first branch');
            $this->seed($schema, $data, $branchId, $code);

            $step("Creating least-privilege role [{$role}]");
            BranchRole::create($role, $password, $schema);
            // Passing the schema matters: this undo runs BEFORE the schema drop
            // (reverse order), so the role's grants are still live and must be
            // revoked before Postgres will let go of the role.
            $this->pushUndo("role {$role}", fn () => BranchRole::drop($role, $schema));

            $step('Reading the schema version gate');
            $versions = $this->readVersions($schema);

            $step('Verifying the branch role can connect and read');
            $this->verify($endpoint->loginName($role), $password, $schema, $code);

            $step('Writing the credentials JSON');
            $document = BranchCredentials::document(
                business: [
                    'id' => $businessId,
                    'name' => (string) $data['business_name'],
                    'schema' => $schema,
                ],
                branch: [
                    'id' => $branchId,
                    'code' => $code,
                    'name' => (string) $data['branch_name'],
                    'address' => $data['branch_address'] !== '' ? (string) $data['branch_address'] : null,
                ],
                endpoint: $endpoint,
                role: $role,
                password: $password,
                versions: $versions,
            );

            $this->credentials->write($document);

            // Last, and inside the guarded block: this is the branch's first
            // credential, and a business provisioned without this row would
            // report "never issued" while running a live one, and would number
            // its first ROTATION generation 1 — making generation 1 mean two
            // different secrets for that branch, permanently. Unlike the audit
            // trail it cannot be reconstructed afterwards, so a failure here
            // rolls the whole provision back rather than leaving it unrecorded.
            $step('Recording the credential issuance');
            $this->issuance->record($target, $password, CredentialIssuance::PROVISION);
        } catch (Throwable $original) {
            throw $this->rollBack($original, $target);
        }

        // Past this point everything real exists. An audit failure is reported
        // but must not fail the command: the business, role and JSON are all
        // live, and claiming otherwise would be the bigger lie.
        $this->recordQuietly(ProvisioningAudit::SUCCEEDED, $target, [
            'schema_version' => $versions['schema_version'],
            'min_app_version' => $versions['min_app_version'],
            'credentials_path' => $path,
            'owner_username' => $data['username'],
        ], $step);

        return [
            'business_id' => $businessId,
            'business_name' => $data['business_name'],
            'schema' => $schema,
            'branch_id' => $branchId,
            'branch_code' => $code,
            'branch_name' => $data['branch_name'],
            'role' => $role,
            'login_name' => $endpoint->loginName($role),
            'schema_version' => $versions['schema_version'],
            'min_app_version' => $versions['min_app_version'],
            'credentials_path' => $path,
        ];
    }

    /**
     * Reuse M1's command wholesale rather than re-implementing create + migrate.
     */
    private function migrateSchema(string $schema): void
    {
        $exit = Artisan::call('schema:migrate', ['--schema' => $schema], $this->output);

        if ($exit !== 0) {
            throw new RuntimeException("schema:migrate failed for [{$schema}].");
        }
    }

    /**
     * The one seed transaction (§8.1 step 3). Genuinely atomic.
     *
     * Deliberately does NOT write:
     *  - schema_meta — migration 000001 already inserted the singleton row, and
     *    schema_meta_singleton would reject a second one;
     *  - branch_status — §14 is an upsert on the branch's first heartbeat, and
     *    M10's monitor left-joins branches, so a never-seen branch still lists;
     *  - printer settings — device-local hardware config, set during M5's wizard;
     *  - any transaction-serial counter — the app derives the next serial from
     *    MAX(transaction_number), so a fresh schema needs nothing.
     *
     * @param  array<string, mixed>  $data
     */
    private function seed(string $schema, array $data, string $branchId, string $code): void
    {
        $business = SchemaConnection::pin($schema);

        $business->transaction(function (Connection $db) use ($data, $branchId, $code): void {
            // The preflight said this schema did not exist; if it now holds
            // rows, something raced us and seeding on top would be silent
            // corruption rather than a clean failure.
            if ($db->table('users')->exists() || $db->table('branches')->exists()) {
                throw new RuntimeException('The target schema is not empty; refusing to seed over it.');
            }

            $now = now();

            $db->table('branches')->insert([
                'id' => $branchId,
                'code' => $code,
                'name' => $data['branch_name'],
                'address' => $data['branch_address'] !== '' ? $data['branch_address'] : null,
                'disabled_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // bcrypt via the framework default, which is what the app's
            // 'password' => 'hashed' cast verifies against. Matching cost is
            // not required for password_verify.
            $db->table('users')->insert([
                'id' => (string) Str::orderedUuid(),
                'name' => $data['owner_name'],
                'username' => $data['username'],
                'password' => Hash::make((string) $data['password']),
                'role' => 'owner',
                'must_change_password' => false,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            $db->table('settings')->insert(BusinessSettingKeys::rows($data));
        });
    }

    /**
     * @return array{schema_version: int, min_app_version: string}
     */
    private function readVersions(string $schema): array
    {
        $meta = SchemaConnection::pin($schema)
            ->selectOne('select schema_version, min_app_version from schema_meta');

        if ($meta === null) {
            throw new RuntimeException("[{$schema}] has no schema_meta row; the migration did not complete.");
        }

        return [
            'schema_version' => (int) $meta->schema_version,
            'min_app_version' => (string) $meta->min_app_version,
        ];
    }

    /**
     * Prove the emitted credentials work, as the branch role, before claiming
     * success — the roadmap milestone is a JSON a pgsql client can connect with.
     *
     * search_path is left unset so this also proves the server-side ALTER ROLE
     * pin: if the pin were missing, the role would land in `public` and the
     * unqualified reads below would fail.
     */
    private function verify(string $loginName, string $password, string $schema, string $code): void
    {
        try {
            $branch = BranchConnection::open($loginName, $password);

            $actualSchema = (string) $branch->selectOne('select current_schema() as name')->name;

            if ($actualSchema !== $schema) {
                throw new RuntimeException(
                    "The branch role lands in schema [{$actualSchema}], not [{$schema}] — search_path is not pinned."
                );
            }

            $seededCode = $branch->selectOne('select code from branches');

            if ($seededCode === null || (string) $seededCode->code !== $code) {
                throw new RuntimeException('The branch role cannot read the seeded branch row.');
            }

            $settings = (int) $branch->selectOne('select count(*) as total from settings')->total;

            if ($settings !== count(BusinessSettingKeys::KEYS)) {
                throw new RuntimeException('Expected '.count(BusinessSettingKeys::KEYS)." settings rows, found {$settings}.");
            }
        } finally {
            // Always: an open branch session blocks DROP SCHEMA and DROP ROLE,
            // so leaving it open would break the cleanup path.
            BranchConnection::close();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function credentialsPath(array $data, string $schema, string $code): string
    {
        $out = $data['out'] ?? null;

        if (is_string($out) && trim($out) !== '') {
            return trim($out);
        }

        return base_path(BranchCredentials::DEFAULT_DIRECTORY)
            .DIRECTORY_SEPARATOR
            .BranchCredentials::filename($schema, $code);
    }

    /** @param  Closure(): void  $undo */
    private function pushUndo(string $label, Closure $undo): void
    {
        $this->undo[] = ['label' => $label, 'undo' => $undo];
    }

    private function dropSchema(string $schema): void
    {
        DB::connection('pgsql_admin')->statement(
            'drop schema if exists "'.SchemaName::validate($schema).'" cascade'
        );
    }

    /**
     * Unwind in reverse, then report the ORIGINAL failure.
     *
     * Every step is individually guarded: one failing undo must not prevent the
     * others, and no cleanup error may replace the error that actually caused
     * the failure — that would send the operator chasing the wrong problem.
     *
     * @param  array<string, mixed>  $target
     */
    private function rollBack(Throwable $original, array $target): RuntimeException
    {
        // A branch session opened during verification would block the drops.
        BranchConnection::close();

        $failures = [];

        foreach (array_reverse($this->undo) as ['label' => $label, 'undo' => $undo]) {
            try {
                $undo();
            } catch (Throwable $e) {
                $failures[] = $label.': '.$e->getMessage();
            }
        }

        $this->undo = [];

        $outcome = $failures === []
            ? ProvisioningAudit::ROLLED_BACK
            : ProvisioningAudit::CLEANUP_FAILED;

        try {
            $this->audit->record(self::ACTION, $outcome, $target, [
                'error' => $original->getMessage(),
                'cleanup_failures' => $failures,
            ]);
        } catch (Throwable) {
            // The provision already failed; an unreachable audit store must not
            // become the error the operator sees instead.
        }

        $message = 'Provisioning failed: '.$original->getMessage();

        if ($failures !== []) {
            $message .= PHP_EOL.PHP_EOL.'Cleanup did not fully succeed, so some objects remain:'
                .PHP_EOL.'  - '.implode(PHP_EOL.'  - ', $failures)
                .PHP_EOL.PHP_EOL.'Remove them by hand with:'.PHP_EOL.'  '
                .implode(PHP_EOL.'  ', BranchRole::cleanupSql(
                    (string) $target['role_name'],
                    (string) $target['schema_name'],
                ));
        } else {
            $message .= PHP_EOL.'Nothing was left behind.';
        }

        return new RuntimeException($message, 0, $original);
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $detail
     * @param  Closure(string): void  $step
     */
    private function recordQuietly(string $outcome, array $target, array $detail, Closure $step): void
    {
        try {
            $this->audit->record(self::ACTION, $outcome, $target, $detail);
        } catch (Throwable $e) {
            $step('WARNING: the provision succeeded but its audit entry failed: '.$e->getMessage());
        }
    }
}
