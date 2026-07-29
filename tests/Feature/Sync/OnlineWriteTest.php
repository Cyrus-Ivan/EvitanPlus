<?php

namespace Tests\Feature\Sync;

use App\Exceptions\OfflineWriteException;
use App\Models\Category;
use App\Models\InstanceSecret;
use App\Models\Item;
use App\Models\User;
use App\Support\Sync\CredentialVault;
use App\Support\Sync\OnlineWrite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * `OnlineWrite` itself, with the real class rather than the suite's stand-in.
 *
 * What matters here is the atomicity: shared data has to reach the business
 * database or not be written at all, because the alternative — a product that
 * exists on one till and nowhere else — is precisely the failure M6.5 exists to
 * end, and it is invisible from the device that caused it.
 *
 * The Postgres half is proved for real in tests/Integration/OnlineWriteTest.php.
 * These are the cases that need no Postgres because they are about what happens
 * when there ISN'T one.
 */
class OnlineWriteTest extends TestCase
{
    use RefreshDatabase;

    /** The real OnlineWrite and the real probe — that is the subject. */
    protected ?bool $connected = null;

    private function sealCredential(): void
    {
        InstanceSecret::query()->create([
            'id' => InstanceSecret::ID,
            'dsn_ciphertext' => app(CredentialVault::class)->seal([
                'driver' => 'pgsql',
                'host' => 'aws-0-ap-southeast-1.pooler.supabase.com',
                'port' => 6543,
                'database' => 'postgres',
                'username' => 'branch_9f2c.abcdefghijklmnop',
                'password' => 'secret',
                'search_path' => null,
            ]),
            'fingerprint' => 'sha256:test',
            'imported_at' => now(),
        ]);
    }

    public function test_a_device_with_no_credential_refuses_and_writes_nothing(): void
    {
        $this->expectException(OfflineWriteException::class);

        try {
            app(OnlineWrite::class)->run(function (): void {
                Category::create(['name' => 'Should not exist']);
            }, 'A category');
        } finally {
            // The refusal happens before the closure runs at all: there is no
            // point doing local work that is going to be rolled back.
            $this->assertSame(0, Category::query()->count());
        }
    }

    public function test_an_unreachable_project_refuses_and_writes_nothing(): void
    {
        $this->sealCredential();

        Http::fake(fn () => throw new ConnectionException('could not resolve host'));

        $this->expectException(OfflineWriteException::class);

        try {
            app(OnlineWrite::class)->run(fn () => Category::create(['name' => 'Should not exist']), 'A category');
        } finally {
            $this->assertSame(0, Category::query()->count());
        }
    }

    public function test_a_failure_reaching_postgres_rolls_the_local_write_back(): void
    {
        $this->sealCredential();

        // The probe passes, so the cycle is attempted — and the host does not
        // exist, so opening the connection fails. The local rows written before
        // that point must go with it.
        Http::fake(['*' => Http::response('', 401)]);

        try {
            app(OnlineWrite::class)->run(function (): void {
                $category = Category::create(['name' => 'Rolled back']);

                app(OnlineWrite::class)->mirror($category);
            }, 'A category');

            $this->fail('The cycle should not have reached Postgres.');
        } catch (\Throwable) {
            // The connection failure itself is not the assertion.
        }

        $this->assertSame(0, Category::query()->count(), 'A local write must not survive a failed online write.');
    }

    public function test_a_closure_that_mirrors_nothing_never_opens_a_connection(): void
    {
        $this->sealCredential();

        Http::fake(['*' => Http::response('', 401)]);

        // An unchanged submit is legitimate and must not fail for want of a
        // database it did not need.
        $result = app(OnlineWrite::class)->run(fn (): string => 'nothing changed', 'A category');

        $this->assertSame('nothing changed', $result);
    }

    public function test_mirror_outside_a_run_is_a_programming_error(): void
    {
        $this->expectException(RuntimeException::class);

        app(OnlineWrite::class)->mirror(Item::factory()->create());
    }

    public function test_the_screens_refuse_offline_and_say_why(): void
    {
        // No credential, so every online write is impossible.
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('categories.store'), ['name' => 'Beverages'])
            ->assertRedirect();

        $toast = session('toast');

        $this->assertSame('error', $toast['type']);
        $this->assertStringContainsString('shared across all your branches', $toast['message']);
        $this->assertStringContainsString('selling, returns and receipts', strtolower($toast['message']));

        $this->assertSame(0, Category::query()->count());
    }
}
