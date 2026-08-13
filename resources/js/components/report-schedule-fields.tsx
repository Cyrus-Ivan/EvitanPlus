import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ReactNode } from 'react';

/** The four automatic sends, in the order the server walks them. */
export const SCHEDULE_KINDS = ['daily', 'weekly', 'monthly', 'yearly'] as const;

export type ScheduleKind = (typeof SCHEDULE_KINDS)[number];

/**
 * Every quarter-hour of the day. A closed list, not a free time input: the
 * command that sends these ticks every fifteen minutes, so a time it cannot land
 * on would be a picker that lies. Mirrors ReportSchedule::times() on the server,
 * which is also what validates the submission.
 */
const TIMES = Array.from({ length: 24 * 4 }, (_, i) => {
    const hour = Math.floor(i / 4);
    const minute = (i % 4) * 15;

    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
});

const timeLabel = (value: string) => {
    const [hour, minute] = value.split(':').map(Number);

    return `${hour % 12 === 0 ? 12 : hour % 12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
};

/** ISO-8601 weekday numbers, Monday = 1 — Carbon's isoWeekday() spelling. */
const WEEKDAYS = [
    { value: '1', short: 'Mon', long: 'Monday' },
    { value: '2', short: 'Tue', long: 'Tuesday' },
    { value: '3', short: 'Wed', long: 'Wednesday' },
    { value: '4', short: 'Thu', long: 'Thursday' },
    { value: '5', short: 'Fri', long: 'Friday' },
    { value: '6', short: 'Sat', long: 'Saturday' },
    { value: '7', short: 'Sun', long: 'Sunday' },
];

const DESCRIPTIONS: Record<ScheduleKind, string> = {
    daily: 'Covers the previous day.',
    weekly: 'Sent on a Monday, covering the previous week.',
    monthly: 'Sent on the first Monday of the month, covering the previous calendar month.',
    yearly: 'Sent on the first Monday of the year, covering the previous year.',
};

const TITLES: Record<ScheduleKind, string> = {
    daily: 'Daily report',
    weekly: 'Weekly report',
    monthly: 'Monthly report',
    yearly: 'Yearly report',
};

/**
 * The four automatic sends, each switched on by itself.
 *
 * They are independent on purpose: the previous day's figures at opening time,
 * last week's on a Monday morning and last month's early in the new month are
 * three separate wants, and M14's single "how often" select made them exclusive.
 *
 * The time and day controls appear only once a schedule is on. A switched-off
 * report has nothing to say about when it would have gone, and four permanently
 * visible time pickers turn a short page into a wall.
 */
export default function ReportScheduleFields({
    values,
    days,
    setValue,
    setDays,
    errors,
}: {
    /** The whole settings form; only the `report_email_<kind>_*` keys are read. */
    values: Record<string, string | string[]>;
    days: string[];
    setValue: (key: string, value: string) => void;
    setDays: (days: string[]) => void;
    errors: Partial<Record<string, string>>;
}) {
    const text = (key: string) => (typeof values[key] === 'string' ? values[key] : '');

    return (
        <div className="grid gap-2">
            <Label>Send automatically</Label>

            <div className="grid gap-3">
                {SCHEDULE_KINDS.map((kind) => (
                    <ScheduleBlock
                        key={kind}
                        kind={kind}
                        enabled={text(`report_email_${kind}_enabled`) === '1'}
                        time={text(`report_email_${kind}_time`) || '08:00'}
                        onEnabledChange={(on) => setValue(`report_email_${kind}_enabled`, on ? '1' : '0')}
                        onTimeChange={(time) => setValue(`report_email_${kind}_time`, time)}
                        error={errors[`report_email_${kind}_time`]}
                    >
                        {kind === 'daily' && (
                            <div className="grid gap-2">
                                <Label className="text-muted-foreground text-xs font-normal">On these days</Label>
                                <ToggleGroup
                                    type="multiple"
                                    variant="outline"
                                    size="sm"
                                    value={days}
                                    onValueChange={setDays}
                                    className="flex-wrap justify-start"
                                >
                                    {WEEKDAYS.map((day) => (
                                        <ToggleGroupItem
                                            key={day.value}
                                            value={day.value}
                                            aria-label={day.long}
                                            /*
                                             * The shared `on` state is bg-accent, which against
                                             * this card is all but invisible — and "which days
                                             * are ticked" is the only thing this control says.
                                             * Filled like the checkbox above it, which is the
                                             * same on/off idea on the same card.
                                             */
                                            className="data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                                        >
                                            {day.short}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                                <p className="text-muted-foreground text-xs">
                                    All seven means every day. Untick a day and no report goes out the morning after it.
                                </p>
                                <InputError message={errors.report_email_daily_days} />
                            </div>
                        )}
                    </ScheduleBlock>
                ))}
            </div>

            <p className="text-muted-foreground text-xs">
                Sent from this device, for this branch only. Each goes out while the app is open — if the device was closed at the chosen time, the
                report is sent the next time you open it, and only the most recent one is: three weeks away means one weekly report, not three.
            </p>
        </div>
    );
}

function ScheduleBlock({
    kind,
    enabled,
    time,
    onEnabledChange,
    onTimeChange,
    error,
    children,
}: {
    kind: ScheduleKind;
    enabled: boolean;
    time: string;
    onEnabledChange: (enabled: boolean) => void;
    onTimeChange: (time: string) => void;
    error?: string;
    children?: ReactNode;
}) {
    return (
        <div className="rounded-md border p-3">
            <label className="flex items-start gap-3">
                <Checkbox checked={enabled} onCheckedChange={(value) => onEnabledChange(value === true)} aria-label={TITLES[kind]} />
                <span className="grid gap-0.5">
                    <span className="text-sm font-medium">{TITLES[kind]}</span>
                    <span className="text-muted-foreground text-xs">{DESCRIPTIONS[kind]}</span>
                </span>
            </label>

            {enabled && (
                <div className="mt-3 grid gap-4 pl-7">
                    {children}

                    <div className="grid gap-2">
                        <Label htmlFor={`report_email_${kind}_time`} className="text-muted-foreground text-xs font-normal">
                            Send at
                        </Label>
                        <Select value={time} onValueChange={onTimeChange}>
                            <SelectTrigger id={`report_email_${kind}_time`} className="w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {TIMES.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {timeLabel(value)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={error} />
                    </div>
                </div>
            )}
        </div>
    );
}
