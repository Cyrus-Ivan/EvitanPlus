import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * One headline figure — the row of them above the sales report and the employee
 * month.
 *
 * `tabular-nums` on the value is the part that matters: a row of these is read
 * across, and proportional digits make four cards of the same width hold
 * numbers that do not line up with each other.
 *
 * `muted` is for a figure that is a SUBTRACTION from the headline — returns and
 * defects sit beside net sales, and toning them down is what stops the eye
 * reading four equal figures and adding them up.
 */
export default function StatCard({ label, value, hint, muted }: { label: string; value: string; hint?: string; muted?: boolean }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</div>
                <div className={cn('mt-1 text-2xl font-semibold tabular-nums', muted ? 'text-muted-foreground' : 'text-foreground')}>{value}</div>
                {hint && <div className="text-muted-foreground mt-1 text-xs">{hint}</div>}
            </CardContent>
        </Card>
    );
}
