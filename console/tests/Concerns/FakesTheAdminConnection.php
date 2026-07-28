<?php

namespace Tests\Concerns;

/**
 * A synthetic `pgsql_admin` connection, so endpoint logic can be tested without
 * a Supabase project.
 *
 * Nothing here is ever connected to. SupabaseEndpoint only reads the config
 * array, which is what makes the whole branch-endpoint derivation testable
 * offline — and testable in configurations a developer's own project cannot be
 * in at the same time (pooler and direct, at once, in one run).
 *
 * The fixture values are the shape console/.env.example documents, not an
 * abstraction of it: a real Supavisor host, a `postgres.<project-ref>` username,
 * and a port as a STRING because that is what env() returns.
 */
trait FakesTheAdminConnection
{
    /**
     * The canonical production case: admin through the session pooler.
     *
     * @var array<string, mixed>
     */
    protected const POOLER_ADMIN = [
        'driver' => 'pgsql',
        'host' => 'aws-1-ap-southeast-1.pooler.supabase.com',
        'port' => '5432',
        'database' => 'postgres',
        'username' => 'postgres.abcdefghijklmnopqrst',
        'password' => 'fixture-password-never-copied',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'require',
    ];

    /**
     * The other supported shape: a direct connection, or a local Postgres.
     *
     * The username carries no dot, so there is no Supavisor tenant to derive —
     * which is what makes the branch endpoint inherit rather than assume 6543.
     *
     * @var array<string, mixed>
     */
    protected const DIRECT_ADMIN = [
        'driver' => 'pgsql',
        'host' => 'db.abcdefghijklmnopqrst.supabase.co',
        'port' => '5432',
        'database' => 'postgres',
        'username' => 'postgres',
        'password' => 'fixture-password-never-copied',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'require',
    ];

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $base
     */
    protected function fakeAdminConnection(array $overrides = [], array $base = self::POOLER_ADMIN): void
    {
        config(['database.connections.pgsql_admin' => array_merge($base, $overrides)]);
    }
}
