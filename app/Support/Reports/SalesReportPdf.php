<?php

namespace App\Support\Reports;

use App\Support\Reports\Concerns\NamesReportScope;
use App\Support\Settings\BusinessSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

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
    use NamesReportScope;

    public function __construct(private readonly BusinessSettings $business) {}

    /**
     * @param  array<string, mixed>  $report  A report array as returned by
     *                                        {@see SalesReportBuilder::local()} or the controller's consolidated read.
     * @param  bool  $automated  Whether the scheduled send produced this, rather
     *                           than somebody pressing a button. Named in the
     *                           document's generation details, because a report
     *                           that arrived by itself and one a person asked for
     *                           are read differently.
     */
    public function render(array $report, string $period, Carbon $from, bool $canViewCost, bool $automated = false): string
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
            // The generation details at the foot of the document.
            'generatedFrom' => $this->generatedFrom(),
            'automated' => $automated,
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
}
