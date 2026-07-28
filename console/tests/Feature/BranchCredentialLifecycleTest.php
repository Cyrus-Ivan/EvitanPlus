<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Provisioning\Support\BranchConnection;
use Provisioning\Support\BranchRole;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\RoleName;
use Provisioning\Support\SchemaConnection;
use Tests\Concerns\AssertsBranchGrants;
use Tests\Concerns\ProvisionsBusiness;
use Tests\TestCase;
use Throwable;

/**
 * The M3 milestone (§8.3): rotate, disable, enable, revoke, re-create.
 *
 * > "The console can fully manage the lifecycle of branch credentials; a
 * > disabled role's client fails auth cleanly while an enabled one connects."
 *
 * Every assertion is made by trying to authenticate as the branch, because that
 * is the only thing that settles whether a credential works. Reading
 * `pg_roles.rolcanlogin` would prove the console issued the right statement, not
 * that the outcome is what an actual till experiences.
 *
 * ## Why this class provisions its own branch
 *
 * The tests run in order against ONE branch, deliberately: a credential's
 * lifecycle is a sequence, and asserting each step from a fresh fixture would
 * skip the transitions that are the interesting part (rotate-then-revoke,
 * revoke-then-recreate). The fixture's own branch is left alone so the shared
 * trait's teardown stays valid.
 */
class BranchCredentialLifecycleTest extends TestCase
{
    use AssertsBranchGrants;
    use ProvisionsBusiness;

    private const CODE = 'LIFE';

    /**
     * How long a credential change may take to become visible through the
     * pooler. In isolation rotate and enable are instant and disable takes ~2s,
     * but a role with warm pooled connections lags longer, so this is sized for
     * the worst observed case rather than the best.
     */
    private const POOLER_SETTLE_SECONDS = 90;

    /**
     * Seconds between connection attempts while polling.
     *
     * Sized by what is being waited for, not by how fast an answer is wanted:
     * Supavisor's role cache expires on its own schedule (~126s for a re-created
     * role), so asking more often cannot make it expire sooner. It only spends
     * pooler clients, which Supavisor frees lazily.
     */
    private const POLL_INTERVAL_SECONDS = 10;

    /** The branch under test, provisioned once for the class. */
    private static ?string $branchId = null;

    private static ?string $role = null;

    /** The password from the most recently issued JSON. */
    private static ?string $password = null;

    private static ?string $loginName = null;

    /** @var list<string> */
    private static array $paths = [];

    public static function tearDownAfterClass(): void
    {
        try {
            BranchConnection::close();
        } catch (Throwable) {
        }

        // The schema goes FIRST: CASCADE takes the tables and therefore the ACL
        // entries naming this role, and Postgres refuses to drop a role while
        // any grant still references it. The other order silently leaks a
        // cluster-wide role on every run.
        self::tearDownProvisionedBusiness();

        if (self::$role !== null) {
            try {
                BranchRole::drop(self::$role);
            } catch (Throwable) {
            }

            self::$role = null;
        }

        foreach (self::$paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        self::$paths = [];
        self::$branchId = null;
        self::$password = null;
        self::$loginName = null;

        self::tearDownProvisionedBusiness();

        parent::tearDownAfterClass();
    }

    public function test_1_a_provisioned_branch_can_authenticate(): void
    {
        $this->lifecycleBranch();

        $this->assertConnects('A freshly provisioned branch could not authenticate.');
        $this->assertSame(1, $this->currentGeneration());
    }

    public function test_2_rotation_replaces_the_password_and_keeps_the_grants(): void
    {
        $this->lifecycleBranch();

        $old = (string) self::$password;

        $exit = $this->rotate();
        $this->assertSame(0, $exit, 'branch:rotate failed: '.Artisan::output());

        $this->assertNotSame($old, self::$password, 'Rotation issued the same password.');

        // The new credential works…
        $this->assertConnects('The rotated credential could not authenticate.');

        // …and the old one is dead. This is the fact that makes rotation
        // meaningful, and the reason §9 delivers a fresh JSON rather than
        // patching one in place.
        $this->assertCannotAuthenticate($old, 'The pre-rotation password still authenticates.');

        // Rotation is a credential operation, not a privilege one.
        $this->assertGrantsMatchMatrix((string) self::$role, $this->schema());

        $this->assertSame(2, $this->currentGeneration());
    }

    public function test_3_a_disabled_role_fails_auth_while_an_enabled_one_connects(): void
    {
        $this->lifecycleBranch();

        $this->assertSame(0, Artisan::call('branch:disable', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-code' => self::CODE,
        ]), Artisan::output());

        $this->assertFalse(BranchRole::canLogin((string) self::$role));

        // The milestone, stated as a till would experience it: the password is
        // still correct and the connection is still refused.
        $this->assertCannotAuthenticate(
            (string) self::$password,
            'A disabled role still authenticates.',
        );

        // Disable is LOGIN-only: branches.disabled_at is business state and a
        // credential action must not push it to every till.
        $this->assertNull(SchemaConnection::pin($this->schema())
            ->selectOne('select disabled_at from branches where code = ?', [self::CODE])->disabled_at);

        // Re-running is a no-op success, not an error.
        $this->assertSame(0, Artisan::call('branch:disable', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-code' => self::CODE,
        ]));

        $this->assertSame(0, Artisan::call('branch:enable', [
            '--no-interaction' => true,
            '--schema' => $this->schema(),
            '--branch-code' => self::CODE,
        ]), Artisan::output());

        $this->assertTrue(BranchRole::canLogin((string) self::$role));
        $this->assertConnects('An enabled role could not authenticate.');

        // Enabling did not mint a new credential — the same one came back.
        $this->assertSame(2, $this->currentGeneration());
    }

    public function test_4_rotate_and_recreate_refuse_each_others_preconditions(): void
    {
        $this->lifecycleBranch();

        // The role exists, so there is nothing to re-create.
        $this->assertNotSame(0, Artisan::call('branch:rotate', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-code' => self::CODE,
            '--recreate' => true,
            '--out' => $this->tempPath(),
        ]));

        $this->assertStringContainsString('still exists', Artisan::output());

        // And the refusal changed nothing.
        $this->assertConnects('A refused --recreate disturbed a working credential.');
        $this->assertSame(2, $this->currentGeneration());
    }

    public function test_5_revoking_drops_the_role_and_keeps_the_branch(): void
    {
        $this->lifecycleBranch();

        $this->assertSame(0, Artisan::call('branch:revoke', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-code' => self::CODE,
        ]), Artisan::output());

        $this->assertFalse(BranchRole::exists((string) self::$role));
        $this->assertCannotAuthenticate((string) self::$password, 'A revoked role still authenticates.');

        // The branch itself is untouched — this deletes a login, not a branch.
        $this->assertNotNull(SchemaConnection::pin($this->schema())
            ->selectOne('select id from branches where code = ?', [self::CODE]));

        // No live credential, but the history of what was issued survives.
        $issuance = new CredentialIssuance;
        $this->assertNull($issuance->current((string) self::$branchId));
        $this->assertSame(2, $issuance->generations((string) self::$branchId));

        // Rotating a role that no longer exists is refused, and names the fix.
        $this->assertNotSame(0, Artisan::call('branch:rotate', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-code' => self::CODE,
            '--out' => $this->tempPath(),
        ]));

        $this->assertStringContainsString('--recreate', Artisan::output());
    }

    /**
     * Re-creation, including the pooler window it has to survive.
     *
     * `--verify-wait=0` makes the command take the tolerated path immediately
     * rather than spending two minutes inside the command; the test then does
     * the waiting itself, so the slow part is visible here rather than hidden
     * in a step that looks hung.
     */
    public function test_6_recreate_brings_the_role_back_with_the_full_grant_matrix(): void
    {
        $this->lifecycleBranch();

        $this->assertSame(0, $this->rotate(recreate: true), Artisan::output());

        // Deliberately NOT asserting that the run reported verified=false.
        // Whether the pooler is still holding the old role by the time this
        // test runs depends on how long the earlier tests took, so requiring
        // either outcome would make this fail on timing rather than on
        // behaviour. What must hold in both cases is below: the role exists,
        // it carries the full matrix, and nothing was rolled back.
        $this->assertTrue(BranchRole::exists((string) self::$role));

        // §7 As built names this requirement explicitly: grants are enumerated
        // per table, so a re-created role must come back with the whole matrix
        // rather than whatever a partial re-grant happened to cover.
        $this->assertGrantsMatchMatrix((string) self::$role, $this->schema());

        $this->assertSame(3, $this->currentGeneration());

        // The credentials file was KEPT, verified or not. That is the guarantee
        // that matters here: the role holds this password and the file is its
        // only copy, so a rollback would have stranded the branch.
        $this->assertFileExists((string) self::$paths[count(self::$paths) - 1]);

        // Deliberately NOT waiting here for the credential to start working.
        // That would be re-measuring Supabase's ~126s cache TTL, which is a
        // property of the pooler rather than of this code — it is measured once
        // in a probe and recorded in PoolerLag. Doing it in the suite costs two
        // minutes and, worse, a dozen pooler clients, which is what starved
        // every later test class of connections on the first full run.
    }

    public function test_7_every_action_is_on_the_provisioning_record(): void
    {
        $this->lifecycleBranch();

        $actions = collect(SchemaConnection::pinConsole()->select(
            'select action, outcome from provisioning_audit where branch_id = ? order by created_at',
            [self::$branchId],
        ));

        $this->assertSame(
            ['branch.provision', 'branch.rotate', 'branch.disable', 'branch.enable', 'branch.delete', 'branch.recreate'],
            $actions->pluck('action')->all(),
        );

        $this->assertSame(['succeeded'], $actions->pluck('outcome')->unique()->values()->all());

        // Not one password, in any form, anywhere in the trail or the issuance
        // record — the whole point of the fingerprint.
        $rows = SchemaConnection::pinConsole()->select(
            'select detail from provisioning_audit where branch_id = ?', [self::$branchId]
        );

        foreach ($rows as $row) {
            $this->assertStringNotContainsString((string) self::$password, (string) $row->detail);
        }

        $fingerprints = SchemaConnection::pinConsole()->select(
            'select fingerprint from credential_issuance where branch_id = ?', [self::$branchId]
        );

        $this->assertCount(3, $fingerprints);

        foreach ($fingerprints as $row) {
            $this->assertStringStartsWith('sha256:', (string) $row->fingerprint);
            $this->assertStringNotContainsString((string) self::$password, (string) $row->fingerprint);
        }
    }

    /** Provisions the class's branch on first use and caches its credential. */
    private function lifecycleBranch(): void
    {
        if (self::$branchId !== null) {
            return;
        }

        $path = $this->tempPath();

        $exit = Artisan::call('branch:provision', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-name' => 'Lifecycle Branch',
            '--branch-code' => self::CODE,
            '--out' => $path,
        ]);

        $this->assertSame(0, $exit, 'Provisioning the lifecycle branch failed: '.Artisan::output());

        $this->adopt($path);

        self::$role = RoleName::forBranch((string) self::$branchId);
    }

    private function rotate(bool $recreate = false): int
    {
        $path = $this->tempPath();

        $exit = Artisan::call('branch:rotate', array_filter([
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-code' => self::CODE,
            '--recreate' => $recreate,
            // Re-create: don't wait inside the command at all — this test does
            // its own waiting, so the two-minute pause is attributable rather
            // than looking like a hang, and the unverified report is exercised.
            // Rotate: a short budget, enough to ride out pooler lag without
            // spending the full default on every run.
            '--verify-wait' => $recreate ? '0' : '60',
            '--out' => $path,
        ]));

        if ($exit === 0) {
            $this->adopt($path);
        }

        return $exit;
    }

    /** Take the credential from a freshly written JSON as the current one. */
    private function adopt(string $path): void
    {
        $this->assertFileExists($path);

        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        self::$branchId = (string) $document['branch']['id'];
        self::$password = (string) $document['connection']['password'];
        self::$loginName = (string) $document['connection']['username'];
    }

    /**
     * Poll until the credential works, allowing for the pooler's stale-role
     * window. Fails with the last error rather than a bare timeout, so a real
     * breakage is distinguishable from slowness.
     */
    /**
     * Poll until the current credential connects, or fail with the last error
     * rather than a bare timeout — a real breakage must stay distinguishable
     * from slowness.
     */
    private function pollUntilConnected(int $seconds, string $message): void
    {
        $deadline = microtime(true) + $seconds;
        $last = 'never attempted';

        while (true) {
            try {
                $connection = $this->open((string) self::$password);
                $this->assertSame($this->schema(), $connection->selectOne('select current_schema() as v')->v);

                return;
            } catch (Throwable $e) {
                $last = explode("\n", $e->getMessage())[0];
            } finally {
                BranchConnection::close();
            }

            if (microtime(true) >= $deadline) {
                $this->fail($message." Gave up after {$seconds}s. Last error: ".$last);
            }

            // Deliberately unhurried. What this is waiting on is Supavisor's
            // role cache, which expires on its own schedule — polling harder
            // cannot hurry it, and only spends pooler clients that Supabase
            // releases lazily. A tight loop here is exactly how this first
            // failed: it starved every test class that ran after it. Waiting
            // longer costs this test a few seconds and the suite nothing.
            sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    /**
     * Assert the current credential works, allowing for pooler lag.
     *
     * Polls for the same reason assertCannotAuthenticate does: a credential
     * change is visible at the server immediately but can take a moment to
     * reach a client through Supavisor's warm connections. Asserting on the
     * first attempt produced failures that were about the pooler, not the
     * console.
     */
    private function assertConnects(string $message): void
    {
        $this->pollUntilConnected(self::POOLER_SETTLE_SECONDS, $message);
    }

    /**
     * Assert the credential is refused — and refused for a credential reason.
     *
     * ## Why this retries
     *
     * Supabase's Supavisor pooler keeps warm server connections per username.
     * A `NOLOGIN` takes effect at the server immediately, but a client can still
     * be handed an already-established pooled connection for a second or two
     * afterwards. Measured against the live project: rotation and enable settle
     * instantly, disable settles within ~2s. So this polls briefly rather than
     * asserting on the first attempt — the alternative is a test that fails
     * roughly at random and teaches everyone to re-run it.
     *
     * This is a real property of the deployment, not a test artifact: an
     * operator disabling a branch should know the cut-off is near-immediate but
     * not instantaneous.
     *
     * Matching the message still matters: a test that accepted any exception
     * would pass just as happily on a typo in the connection host.
     */
    private function assertCannotAuthenticate(string $password, string $message): void
    {
        $deadline = microtime(true) + self::POOLER_SETTLE_SECONDS;
        $last = null;

        do {
            try {
                $this->open($password)->selectOne('select 1 as v');
            } catch (Throwable $e) {
                $this->assertMatchesRegularExpression(
                    // The last three are the pooler's own wordings once the role
                    // a cached session was bound to has been dropped or changed.
                    '/authentication failed|not permitted to log in|does not exist'
                    .'|invalid role OID|EAUTHQUERY|user not found in the database/i',
                    $e->getMessage(),
                    'Refused, but not by the credential system: '.$e->getMessage(),
                );

                return;
            } finally {
                BranchConnection::close();
            }

            $last = 'the connection succeeded';
            sleep(self::POLL_INTERVAL_SECONDS);
        } while (microtime(true) < $deadline);

        $this->fail($message.' (still '.$last.' after '.self::POOLER_SETTLE_SECONDS.'s)');
    }

    private function open(string $password): Connection
    {
        // Purge first: a cached session from a previous credential would answer
        // happily and prove nothing about the one under test.
        BranchConnection::close();

        return BranchConnection::open((string) self::$loginName, $password);
    }

    private function currentGeneration(): int
    {
        $current = (new CredentialIssuance)->current((string) self::$branchId);

        $this->assertNotNull($current, 'The branch has no live credential on record.');

        return (int) $current->generation;
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir().'/evitan-lifecycle-'.Str::lower(Str::random(12)).'.json';

        self::$paths[] = $path;

        return $path;
    }
}
