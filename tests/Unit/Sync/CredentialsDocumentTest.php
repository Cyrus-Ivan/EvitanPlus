<?php

namespace Tests\Unit\Sync;

use App\Exceptions\SetupFailedException;
use App\Support\Sync\CredentialsDocument;
use Tests\TestCase;

/**
 * Reading the credentials JSON (MULTIBRANCH-BRAINSTORM.md §9).
 *
 * The envelope exists so a document this build does not understand is REFUSED
 * rather than half-read — a rule that only earns its keep the day someone
 * changes the format, which is exactly the day nobody will be running this by
 * hand. Hence a test per way of being wrong.
 */
class CredentialsDocumentTest extends TestCase
{
    public function test_it_reads_a_well_formed_document(): void
    {
        $document = CredentialsDocument::fromString((string) json_encode($this->document()));

        $this->assertSame('biz_test', $document->business()['schema']);
        $this->assertSame('MNL', $document->branch()['code']);
        $this->assertSame('evitan_branch_x', $document->roleName());
        $this->assertSame(['schema_version' => 1, 'min_app_version' => '1.0.0'], $document->issuedVersions());
    }

    public function test_the_login_name_and_the_role_name_are_kept_apart(): void
    {
        // Through Supavisor you authenticate as `<role>.<project-ref>`, while
        // the console's rotate/disable/delete operate on the bare role. They are
        // two values, and collapsing them is a bug that only surfaces in the
        // field, at setup, on a customer's device.
        $document = CredentialsDocument::fromString((string) json_encode($this->document()));

        $this->assertSame('evitan_branch_x.projectref', $document->connection()['username']);
        $this->assertSame('evitan_branch_x', $document->roleName());
        $this->assertNotSame($document->connection()['username'], $document->roleName());
    }

    public function test_search_path_stays_null_and_stays_present(): void
    {
        $connection = CredentialsDocument::fromString((string) json_encode($this->document()))->connection();

        // Present so it overrides whatever the config default holds; null so
        // PostgresConnector::configureSearchPath (which fires on isset) emits
        // nothing. The real pin is server-side, and it is the only one that
        // survives transaction pooling.
        $this->assertArrayHasKey('search_path', $connection);
        $this->assertNull($connection['search_path']);
    }

    public function test_the_connection_block_is_not_the_whole_connection(): void
    {
        $connection = CredentialsDocument::fromString((string) json_encode($this->document()))->connection();

        // If this ever stops being true, BranchCycle's merge stops being the
        // required operation and the reasoning in both docblocks needs revisiting.
        $this->assertEqualsCanonicalizing(
            ['driver', 'host', 'port', 'database', 'username', 'password', 'search_path', 'sslmode'],
            array_keys($connection),
        );
    }

    public function test_a_file_that_does_not_exist_is_refused(): void
    {
        $this->expectException(SetupFailedException::class);

        CredentialsDocument::fromFile('/nowhere/at/all.json');
    }

    public function test_text_that_is_not_json_is_refused(): void
    {
        $this->expectException(SetupFailedException::class);

        CredentialsDocument::fromString('{not json at all');
    }

    public function test_another_kind_of_json_document_is_refused(): void
    {
        $this->expectException(SetupFailedException::class);

        CredentialsDocument::fromString((string) json_encode(['format' => 'something.else', 'format_version' => 1]));
    }

    public function test_a_future_format_version_is_refused_by_number(): void
    {
        try {
            CredentialsDocument::fromString((string) json_encode($this->document(['format_version' => 2])));

            $this->fail('An unknown format version must be refused.');
        } catch (SetupFailedException $e) {
            // Naming the versions is the difference between "this file is
            // broken" and "this device needs a newer build".
            $this->assertStringContainsString('version 2', $e->getMessage());
            $this->assertStringContainsString('version 1', $e->getMessage());
        }
    }

    /**
     * Every required value, one at a time — a document missing any of them must
     * fail HERE, where the message can name the missing key, rather than as a
     * connection error later.
     */
    public function test_each_required_value_is_required(): void
    {
        $required = [
            ['business', 'id'], ['business', 'name'], ['business', 'schema'],
            ['branch', 'id'], ['branch', 'code'], ['branch', 'name'],
            ['connection', 'driver'], ['connection', 'host'], ['connection', 'port'],
            ['connection', 'database'], ['connection', 'username'], ['connection', 'password'],
            ['role', 'name'],
            ['versions', 'schema_version'], ['versions', 'min_app_version'],
        ];

        foreach ($required as [$block, $key]) {
            $document = $this->document();
            unset($document[$block][$key]);

            try {
                CredentialsDocument::fromString((string) json_encode($document));

                $this->fail("A document without [{$block}.{$key}] must be refused.");
            } catch (SetupFailedException $e) {
                $this->assertStringContainsString("{$block}.{$key}", $e->getMessage());
            }
        }
    }

    public function test_a_missing_section_is_named(): void
    {
        $document = $this->document();
        unset($document['connection']);

        try {
            CredentialsDocument::fromString((string) json_encode($document));

            $this->fail('A document without a connection section must be refused.');
        } catch (SetupFailedException $e) {
            $this->assertStringContainsString('connection', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function document(array $overrides = []): array
    {
        return array_merge([
            'format' => CredentialsDocument::FORMAT,
            'format_version' => CredentialsDocument::FORMAT_VERSION,
            'issued_at' => '2026-07-29T10:15:00+00:00',
            'business' => ['id' => 'b-1', 'name' => 'Cruz Sari-Sari Store', 'schema' => 'biz_test'],
            'branch' => ['id' => 'br-1', 'code' => 'MNL', 'name' => 'Manila', 'address' => null],
            'connection' => [
                'driver' => 'pgsql',
                'host' => 'aws-0-ap-southeast-1.pooler.supabase.com',
                'port' => 6543,
                'database' => 'postgres',
                'username' => 'evitan_branch_x.projectref',
                'password' => 'a-branch-secret',
                'search_path' => null,
                'sslmode' => 'require',
            ],
            'role' => ['name' => 'evitan_branch_x'],
            'versions' => ['schema_version' => 1, 'min_app_version' => '1.0.0'],
        ], $overrides);
    }
}
