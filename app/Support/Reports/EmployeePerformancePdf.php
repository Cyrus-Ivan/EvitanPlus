<?php

namespace App\Support\Reports;

use App\Models\User;
use App\Support\Reports\Concerns\NamesReportScope;
use App\Support\Settings\BusinessSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * One employee's month as a PDF document (dompdf, pure PHP — no browser binary,
 * safe for the offline NativePHP build).
 *
 * The document is the screen: the summary figures, then the month as a calendar
 * whose cells carry that day's shifts and that day's sales. The calendar is an
 * ordinary 7-column HTML table — dompdf lays tables out reliably, which is why
 * the sales report draws its chart from positioned divs rather than SVG.
 *
 * One employee per document, always. Several employees are several attachments
 * on one message, never one combined file: a performance report is about a
 * person, and it is forwarded to that person.
 *
 * The document always covers the whole business, so there is no scope to name —
 * {@see NamesReportScope} is used here only for {@see self::generatedFrom()},
 * which asks the other question: which branch the file was produced at.
 *
 * Returns BYTES, not a response — the only consumer is a Mailable's attachment.
 * There is no download route, here or on `/reports`: a report that lands in the
 * Downloads folder of a shop counter has not left the building.
 */
class EmployeePerformancePdf
{
    use NamesReportScope;

    public function __construct(private readonly BusinessSettings $business) {}

    /**
     * @param  array{days: list<array<string, mixed>>, summary: array<string, mixed>}  $report
     * @param  array{id: string, name: string, username: string, role: string, archived: bool}  $employee
     */
    public function render(array $report, array $employee, Carbon $from): string
    {
        return Pdf::loadView('reports.employee-pdf', [
            'business' => $this->business->all(),
            'employee' => $employee,
            'label' => ReportPeriods::label(EmployeePerformanceBuilder::PERIOD, $from),
            'generatedAt' => Carbon::now(),
            'weeks' => self::weeks($report['days']),
            'summary' => $report['summary'],
            // The generation details at the foot of the document. The branches
            // covered are not a variable here — this report is always all of
            // them — but where the file came FROM still is: an Owner at head
            // office can email a month from any device.
            'generatedFrom' => $this->generatedFrom(),
        ])->output();
    }

    public function filename(User $employee, Carbon $from): string
    {
        return 'employee-performance-'.Str::slug($employee->username)
            .'-'.$from->format('Y-m').'-'.Carbon::now()->format('Ymd-His').'.pdf';
    }

    /**
     * The month's days cut into calendar weeks, Monday first, with the leading
     * and trailing cells of a partial week left null.
     *
     * Done here rather than in the Blade template so the view stays a table of
     * what it is handed, and so the same shape could feed a second renderer
     * without re-deriving what a calendar week is. Public and static because it
     * is a pure shape transform with no document behind it — which is also what
     * lets the view be rendered on its own in a test rather than through
     * dompdf's compressed output stream.
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<list<array<string, mixed>|null>>
     */
    public static function weeks(array $days): array
    {
        if ($days === []) {
            return [];
        }

        $weeks = [];
        // ISO weekday, Monday = 1: a month starting on a Thursday opens with
        // three empty cells.
        $week = array_fill(0, (int) $days[0]['weekday'] - 1, null);

        foreach ($days as $day) {
            $week[] = $day;

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        if ($week !== []) {
            $weeks[] = array_pad($week, 7, null);
        }

        return $weeks;
    }
}
