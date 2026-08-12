<?php

namespace App\Support\Reports;

use App\Models\Instance;
use App\Support\Settings\BusinessSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * The sales report as a PDF document (dompdf, pure PHP — no browser binary,
 * safe for the offline NativePHP build).
 *
 * The PDF carries a net-sales bar chart (one diverging bar per sub-bucket,
 * built from HTML divs — dompdf's inline-SVG support is unreliable), the summary
 * totals, the breakdown table, a per-branch table when the report is
 * consolidated, and a "Top selling items" table for the whole selected range
 * (cost-free, so safe for all Admin+ viewers).
 *
 * Returns BYTES, not a response. Since the download was replaced by emailing
 * (`docs/glossary.md` §10) the only consumers are a Mailable's attachment and
 * the scheduled send, and neither has an HTTP response to hang a file on.
 */
class SalesReportPdf
{
    public function __construct(private readonly BusinessSettings $business) {}

    /**
     * @param  array<string, mixed>  $report  A report array as returned by
     *                                        {@see SalesReportBuilder::local()} or the controller's consolidated read.
     */
    public function render(array $report, string $period, Carbon $from, bool $canViewCost): string
    {
        $rows = array_map(function (array $row) use ($period): array {
            $row['label'] = ReportPeriods::bucketLabel($period, $row['bucket']);

            return $row;
        }, $report['rows']);

        return Pdf::loadView('reports.pdf', [
            'business' => $this->business->all(),
            'period' => $period,
            'periodNoun' => ReportPeriods::SUB_NOUNS[$period],
            'label' => ReportPeriods::label($period, $from),
            'generatedAt' => Carbon::now(),
            'rows' => $rows,
            'totals' => $report['totals'],
            'topItems' => $report['topItems'],
            'canViewCost' => $canViewCost,
            // M11: which branches these figures cover, named in the header, and
            // broken out in their own table when there is more than one.
            'scopeLabel' => $this->scopeLabel($report['scope'], $report['branches']),
            'branchRows' => $report['branchRows'],
        ])->output();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function filename(array $report, string $period, Carbon $from): string
    {
        return 'sales-report-'.$this->scopeSlug($report['scope'], $report['branches'])
            .$period.'-'.$from->toDateString().'-'.Carbon::now()->format('Ymd-His').'.pdf';
    }

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
