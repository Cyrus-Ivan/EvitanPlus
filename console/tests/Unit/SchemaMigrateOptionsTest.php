<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Tests\CredentialFreeTestCase;

/**
 * `schema:migrate`'s targeting, refused before it opens a connection (M9).
 *
 * Worth a credential-free test because these are the invocations that must fail
 * FAST. `--all` migrates every biz_* schema in the project, so an argument
 * mistake that reaches Postgres before it is caught is one that has already
 * started doing DDL somewhere the operator did not mean.
 */
class SchemaMigrateOptionsTest extends CredentialFreeTestCase
{
    public function test_all_cannot_be_combined_with_a_business(): void
    {
        $exit = Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--all' => true,
            '--business' => '00000000-0000-0000-0000-0000000000ff',
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('--all cannot be combined', Artisan::output());
    }

    public function test_all_cannot_be_combined_with_a_schema(): void
    {
        $exit = Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--all' => true,
            '--schema' => 'biz_0000000000000000000000000000dead',
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('--all cannot be combined', Artisan::output());
    }

    /** Neither a target nor --all is not "everything"; it is a missing argument. */
    public function test_it_refuses_with_no_target_at_all(): void
    {
        $this->assertNotSame(0, Artisan::call('schema:migrate', ['--no-interaction' => true]));
    }
}
