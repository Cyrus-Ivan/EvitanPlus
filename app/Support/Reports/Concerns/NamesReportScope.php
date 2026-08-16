<?php

namespace App\Support\Reports\Concerns;

use App\Models\Instance;
use App\Support\Reports\SalesReportBuilder;
use Illuminate\Support\Str;

/**
 * How a document names the branches it covers, and the branch it was produced at.
 *
 * Shared by every report PDF (`SalesReportPdf` since M11, the employee
 * performance report since). The two questions are genuinely different and both
 * belong on the document: an Owner at head office can email one other branch's
 * figures from this device, so "what these numbers are about" and "where this
 * file came from" are not the same sentence.
 */
trait NamesReportScope
{
    /**
     * A human name for the reported scope, for the PDF header and the email
     * subject.
     *
     * @param  list<array{id: string, code: string, name: string}>  $branches
     */
    public function scopeLabel(string $scope, array $branches): string
    {
        if ($scope === SalesReportBuilder::SCOPE_LOCAL) {
            // A device that never hydrated has no branch name to print, and a
            // single-install Evitan POS report never had one either.
            $instance = Instance::current();

            return $instance === null ? 'This branch' : (string) $instance->branch_name;
        }

        if ($scope === SalesReportBuilder::SCOPE_ALL) {
            return 'All branches';
        }

        $branch = collect($branches)->firstWhere('id', $scope);

        return $branch === null ? 'One branch' : $branch['code'].' — '.$branch['name'];
    }

    /**
     * The branch this document was PRODUCED at, which is not the same question
     * as which branches it reports on.
     *
     * Named as `CODE — Name` rather than by {@see self::scopeLabel}'s rules,
     * since there is no `all` case to spell here — a device is always exactly
     * one branch, or none at all before the wizard has hydrated it.
     */
    private function generatedFrom(): string
    {
        $instance = Instance::current();

        return $instance === null
            ? 'This device'
            : $instance->branch_code.' — '.$instance->branch_name;
    }

    /**
     * The scope's slug for the attachment filename, empty for the branch-local
     * report.
     *
     * @param  list<array{id: string, code: string, name: string}>  $branches
     */
    private function scopeSlug(string $scope, array $branches): string
    {
        if ($scope === SalesReportBuilder::SCOPE_LOCAL) {
            return '';
        }

        if ($scope === SalesReportBuilder::SCOPE_ALL) {
            return 'all-branches-';
        }

        $branch = collect($branches)->firstWhere('id', $scope);

        return $branch === null ? '' : Str::lower($branch['code']).'-';
    }
}
