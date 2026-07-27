<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Provisioning\Support\BranchGrants;

/**
 * Asserts that a role's LIVE privileges in Postgres equal BranchGrants exactly.
 *
 * Extracted from BranchRoleGrantsTest for M3, which needs the same assertion in
 * a second place: a role brought back by `branch:rotate --recreate` must return
 * with the full current matrix, not a subset that happens to be enough for
 * whatever the test then tries. §7 As built states that requirement — this is
 * what makes it a fact rather than a claim.
 *
 * Equality in both directions is the point. A missing grant breaks a branch; an
 * extra one silently widens the blast radius of a leaked credential, and only a
 * test that rejects surplus will ever notice.
 */
trait AssertsBranchGrants
{
    protected function assertGrantsMatchMatrix(string $role, string $schema): void
    {
        $rows = DB::connection('pgsql_admin')->select(
            'select table_name, privilege_type
             from information_schema.table_privileges
             where grantee = ? and table_schema = ?',
            [$role, $schema],
        );

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->privilege_type][] = $row->table_name;
        }

        foreach ([
            'SELECT' => BranchGrants::SELECT,
            'INSERT' => BranchGrants::INSERT,
            'UPDATE' => BranchGrants::UPDATE,
        ] as $privilege => $expected) {
            $granted = $actual[$privilege] ?? [];
            sort($granted);
            $expected = array_values($expected);
            sort($expected);

            $this->assertSame($expected, $granted, "The live {$privilege} grants differ from BranchGrants.");
        }

        // Nothing beyond the three privileges above is granted at table level.
        $this->assertSame(
            ['INSERT', 'SELECT', 'UPDATE'],
            collect(array_keys($actual))->sort()->values()->all(),
        );

        // Column-level UPDATE is exactly the documented resolution columns.
        foreach (BranchGrants::UPDATE_COLUMNS as $table => $columns) {
            $granted = collect(DB::connection('pgsql_admin')->select(
                'select column_name
                 from information_schema.column_privileges
                 where grantee = ? and table_schema = ? and table_name = ? and privilege_type = \'UPDATE\'',
                [$role, $schema, $table],
            ))->pluck('column_name')->sort()->values()->all();

            $expected = $columns;
            sort($expected);

            $this->assertSame($expected, $granted, "Column UPDATE grants on {$table} differ from BranchGrants.");
        }
    }
}
