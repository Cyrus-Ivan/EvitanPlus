<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Tests\TestCase;
use Throwable;

/**
 * `--pretend` prints; it does not create.
 *
 * The runbook has always said so and the command did the opposite: it called
 * ensureExists() before it read the flag, and Laravel's own MigrateCommand calls
 * migrate:install before it reads the flag too. So a preview against a fresh
 * business UUID left a real schema containing only `migrations` — a state
 * NOTHING in this tool could then reach. business:provision refuses it as
 * create-only, the branch commands refuse it as not-a-business, and the menu
 * lists it as not provisioned. The only exit was a hand-written DROP SCHEMA.
 *
 * These tests are cheap: no provisioning, no migration set, just DDL that must
 * not have happened.
 */
class SchemaMigratePretendTest extends TestCase
{
    /**
     * Every name these tests hand to `--pretend`. Nothing should ever be
     * created under one — that is the assertion — so this list exists purely
     * for the regression that would break it, which is also why BOTH tests
     * record here rather than only the one that asserts non-existence.
     *
     * @var list<string>
     */
    private static array $schemas = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$schemas as $schema) {
            try {
                DB::connection('pgsql_admin')->statement('drop schema if exists "'.$schema.'" cascade');
            } catch (Throwable) {
            }
        }

        self::$schemas = [];

        parent::tearDownAfterClass();
    }

    /**
     * A name in the disposable namespace, recorded so tearDown can drop it.
     *
     * Disposable even though the whole point is that nothing gets created: if
     * the regression this class guards ever returns, a kill before tearDown
     * leaves a `biz_<hex>` that `composer rig:clean:all` can never sweep and
     * that is indistinguishable from a customer's. `biz_test_` costs nothing
     * here — `schema:migrate` derives no meaning from the prefix.
     */
    private static function disposableName(): string
    {
        $schema = SchemaName::forBusiness((string) Str::orderedUuid(), disposable: true);

        self::$schemas[] = $schema;

        return $schema;
    }

    public function test_pretending_against_a_fresh_business_creates_no_schema(): void
    {
        $schema = self::disposableName();

        $this->assertFalse(SchemaConnection::exists($schema), 'The fixture schema already existed.');

        $exit = Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--schema' => $schema,
            '--pretend' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertFalse(
            SchemaConnection::exists($schema),
            'schema:migrate --pretend created the schema it was only asked to describe.',
        );
    }

    /** Having refused to guess, it still says what it would have done. */
    public function test_it_reports_what_would_happen_instead(): void
    {
        $schema = self::disposableName();

        Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--schema' => $schema,
            '--pretend' => true,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('nothing will be changed', $output);
        $this->assertStringContainsString('would be created', $output);
        $this->assertStringContainsString('would run', $output);
    }

    /**
     * The console's own schema takes the same rule. Harmless here in practice —
     * an empty evitan_console is idempotently reused — but a flag that means
     * two different things in two sibling commands is its own defect.
     */
    public function test_console_migrate_pretend_changes_nothing(): void
    {
        $existed = SchemaConnection::exists(SchemaName::CONSOLE);

        $this->assertSame(0, Artisan::call('console:migrate', [
            '--no-interaction' => true,
            '--pretend' => true,
        ]), Artisan::output());

        $this->assertSame(
            $existed,
            SchemaConnection::exists(SchemaName::CONSOLE),
            'console:migrate --pretend changed whether the console schema exists.',
        );
    }
}
