<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Provisioning\Support\BranchStatusDirectory;
use Provisioning\Support\CredentialIssuance;
use Tests\Concerns\ProvisionsBusiness;
use Tests\TestCase;
use Throwable;

/**
 * The monitor (M10) — what every branch last said about itself.
 *
 * Two things are worth a real Postgres here rather than a stand-in. The
 * heartbeat rows are written BY THE BRANCH ROLE, so this file writes them
 * through the branch connection: a monitor that reads rows the till could never
 * have written would be green against a privilege the shipped app does not
 * have. And `connection_state` is now constrained to four words (migration
 * 000020), which only the real schema can enforce.
 */
class MonitorTest extends TestCase
{
    use ProvisionsBusiness;

    /**
     * ProvisionsBusiness is lazy but NOT self-cleaning; its docblock requires
     * this, and a class that forgets leaks one schema and one cluster-wide role
     * per run while staying green.
     */
    public static function tearDownAfterClass(): void
    {
        self::tearDownProvisionedBusiness();

        parent::tearDownAfterClass();
    }

    /** Set by beat(), so tearDown never provisions a business just to clean up. */
    private bool $reported = false;

    protected function tearDown(): void
    {
        // The heartbeat row is a singleton per branch, so a row left by one test
        // is the state the next one reads. Removed as admin: the branch role
        // holds no DELETE anywhere, which is the point.
        if ($this->reported) {
            try {
                $this->admin()->statement('delete from '.$this->schema().'.branch_status');
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }

    /**
     * The most important row the monitor prints: a branch that has never
     * completed a cycle. An inner join would silently drop exactly this one.
     */
    public function test_a_branch_that_never_reported_is_listed_as_never(): void
    {
        $this->assertSame(0, Artisan::call('monitor', ['--schema' => $this->schema()]));

        $output = Artisan::output();

        $this->assertStringContainsString('FIX', $output);
        $this->assertStringContainsString('never', $output);
    }

    public function test_it_reports_what_the_branch_wrote(): void
    {
        $this->beat('online', unsynced: 3);

        $this->assertSame(0, Artisan::call('monitor', ['--schema' => $this->schema()]));

        $output = Artisan::output();

        $this->assertStringContainsString('online', $output);
        $this->assertStringContainsString('1.4.0', $output);
        $this->assertStringContainsString('FIX', $output);
    }

    /**
     * The row M9 could not surface. `schema:migrate` knows which BUILDS a raised
     * floor froze; only this table knows which tills are on them.
     */
    public function test_a_frozen_branch_says_so_and_carries_its_error(): void
    {
        $this->beat('version_blocked', error: 'This build is too old for the business database.');

        Artisan::call('monitor', ['--schema' => $this->schema()]);

        $output = Artisan::output();

        $this->assertStringContainsString('version_blocked', $output);
        // The error is printed beneath the table, where a text column fits.
        $this->assertStringContainsString('too old', $output);
    }

    /** No target at all is the whole point: every business, every branch. */
    public function test_it_covers_every_business_when_given_no_target(): void
    {
        $this->beat('online');

        $this->assertSame(0, Artisan::call('monitor'));

        $this->assertStringContainsString($this->schema(), Artisan::output());
    }

    /**
     * The four words are now the schema's rule rather than a convention held in
     * two PHP files (migration 000020). Written as ADMIN: this is not something
     * the app could do, and the check is what makes that true of anything else
     * that ever writes here.
     */
    public function test_a_fifth_connection_state_is_refused(): void
    {
        $this->reported = true;

        $this->expectException(QueryException::class);

        $this->admin()->statement(
            'insert into '.$this->schema().'.branch_status (branch_id, connection_state, unsynced_count)
             values (?, ?, 0)',
            [$this->branchId(), 'syncing'],
        );
    }

    public function test_the_directory_pairs_a_branch_with_its_heartbeat(): void
    {
        $this->beat('online', unsynced: 7);

        $rows = BranchStatusDirectory::forSchema($this->schema(), new CredentialIssuance);

        $this->assertCount(1, $rows);
        $this->assertSame('FIX', $rows[0]['state']->code());
        $this->assertSame('active', $rows[0]['state']->credential);
        $this->assertSame(7, (int) $rows[0]['heartbeat']->unsynced_count);
    }

    /**
     * Write a heartbeat the way a till does — as the branch role, through the
     * same upsert, so the grant is part of what passes.
     */
    private function beat(string $state, int $unsynced = 0, ?string $error = null): void
    {
        $this->reported = true;

        $this->branch()->statement(
            'insert into branch_status
                 (branch_id, app_version, schema_version, unsynced_count, connection_state,
                  last_error, last_seen_at, last_sync_at, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             on conflict (branch_id) do update set
                 connection_state = excluded.connection_state,
                 unsynced_count = excluded.unsynced_count,
                 last_error = excluded.last_error,
                 last_seen_at = excluded.last_seen_at',
            [$this->branchId(), '1.4.0', 20, $unsynced, $state, $error],
        );
    }

    private function branchId(): string
    {
        return (string) $this->provisioned()['branch']['id'];
    }

    private function admin(): Connection
    {
        return DB::connection('pgsql_admin');
    }
}
