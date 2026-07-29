<?php

namespace App\Support\Sync;

use App\Models\Instance;
use App\Models\InstanceSecret;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The setup wizard's whole job, in order (MULTIBRANCH-BRAINSTORM.md §16).
 *
 * Parse → connect → version gate → hydrate → remember who we are → audit.
 *
 * ## Nothing is persisted until all of it succeeds
 *
 * The local writes — the hydrated baseline, `instance`, `instance_secret`, the
 * audit entry — are one SQLite transaction, and the Postgres work happens inside
 * it. A failed attempt therefore leaves the device exactly as it was, so the
 * remedy for every failure is the same: fix the thing the message names and
 * submit again. There is no half-imported state to detect, explain or recover
 * from, and no screen has to exist for one.
 *
 * That mirrors how provisioning works on the other side: BusinessProvisioner
 * connects as the role it just created and reads the seeded rows before
 * reporting success, because the milestone is a credential that demonstrably
 * works rather than one that ought to.
 *
 * It also means `instance.hydrated_at` cannot lie, which matters because
 * RequireInitialSetup reads it on every request to decide whether this device is
 * a working branch.
 */
class ConnectAndHydrate
{
    public function __construct(
        private readonly BranchCycle $cycle,
        private readonly VersionGate $gate,
        private readonly Hydrator $hydrator,
        private readonly CredentialVault $vault,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{business: string, branch: string, branch_code: string, schema: string, schema_version: int, counts: array<string, int>}
     */
    public function __invoke(CredentialsDocument $document): array
    {
        $business = $document->business();
        $branch = $document->branch();

        return DB::transaction(function () use ($document, $business, $branch): array {
            [$versions, $counts, $floors] = $this->cycle->run(
                $document->connection(),
                function (Connection $remote) use ($document, $branch): array {
                    // The gate runs BEFORE anything is read, and reads
                    // schema_meta live rather than trusting the JSON's copy —
                    // the schema may have been migrated since the file was
                    // issued (§9, §13).
                    $versions = $this->gate->read($remote);
                    $this->gate->assert($versions['min_app_version']);

                    return [
                        $versions,
                        $this->hydrator->hydrate($remote, $document),
                        // Not part of the baseline: no rows come down, only the
                        // two numbers the local serial generators must not fall
                        // behind (M6).
                        $this->hydrator->serialFloors($remote, $branch['id']),
                    ];
                },
            );

            Instance::query()->updateOrCreate(['id' => Instance::ID], [
                'business_id' => $business['id'],
                'schema_name' => $business['schema'],
                'branch_id' => $branch['id'],
                'branch_code' => $branch['code'],
                'branch_name' => $branch['name'],
                'schema_version' => $versions['schema_version'],
                'min_app_version' => $versions['min_app_version'],
                'hydrated_at' => now(),
                // Null on a new branch; the branch's existing high-water marks
                // when this device is replacing hardware. See
                // Hydrator::serialFloors() for why a restart at 1 would be lost
                // data rather than a visible error.
                'transaction_number_floor' => $floors['transaction'],
                'return_number_floor' => $floors['return'],
            ]);

            InstanceSecret::query()->updateOrCreate(['id' => InstanceSecret::ID], [
                'dsn_ciphertext' => $this->vault->seal($document->connection()),
                'fingerprint' => $this->vault->fingerprint($document->password()),
                'imported_at' => now(),
                'rotated_at' => null,
            ]);

            // Actor null: this is a system event on a device where nobody has
            // signed in yet — the Owner exists, but the developer running setup
            // is not them and must not be recorded as them.
            $this->audit->record(
                action: 'system.setup_completed',
                description: "Device set up as branch {$branch['code']} ({$branch['name']}) of {$business['name']}.",
                new: [
                    'business' => $business['name'],
                    'schema' => $business['schema'],
                    'branch_code' => $branch['code'],
                    'schema_version' => $versions['schema_version'],
                    'hydrated' => $counts,
                ],
                actor: null,
            );

            return [
                'business' => $business['name'],
                'branch' => $branch['name'],
                'branch_code' => $branch['code'],
                'schema' => $business['schema'],
                'schema_version' => $versions['schema_version'],
                'counts' => $counts,
            ];
        });
    }
}
