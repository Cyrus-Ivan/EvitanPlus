import InputError from '@/components/forms/input-error';
import ReportEmailGuide from '@/components/settings/report-email-guide';
import ReportScheduleFields from '@/components/settings/report-schedule-fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { HelpCircle, Plus, X } from 'lucide-react';

// Booleans ride as '1'/'0' strings, matching the string-valued settings table
// and the Laravel `boolean` rule. `recipients` is an array all the way to the
// server — the newline encoding it is stored in is the server's business.
export interface ReportEmailSettingsData {
    recipients: string[];
    report_email_daily_enabled: string;
    report_email_daily_days: string[];
    report_email_daily_time: string;
    report_email_weekly_enabled: string;
    report_email_weekly_time: string;
    report_email_monthly_enabled: string;
    report_email_monthly_time: string;
    report_email_yearly_enabled: string;
    report_email_yearly_time: string;
    report_email_include_cost: string;
    report_email_smtp_host: string;
    report_email_smtp_port: string;
    report_email_smtp_encryption: string;
    report_email_smtp_username: string;
    report_email_smtp_password: string;
    report_email_smtp_password_clear: string;
    report_email_from_address: string;
    report_email_from_name: string;
    [key: string]: string | string[];
}

/**
 * Two groups, not one run of fields. Who the report goes to and how often is a
 * decision the Owner revisits; which mailbox it is sent from is a technical
 * setup done once, in the vocabulary of whoever supplies the mailbox — the two
 * were reading as one list.
 *
 * "Sender credentials" is the term from `docs/glossary.md`, which is normative
 * and forbids coining a synonym for a defined one.
 */
export default function ReportEmailSettingsFields({
    data,
    setData,
    errors,
    passwordSet,
}: {
    data: ReportEmailSettingsData;
    setData: (key: string, value: string | string[]) => void;
    errors: Partial<Record<string, string>>;
    passwordSet: boolean;
}) {
    const recipients = data.recipients;

    const setRecipient = (index: number, value: string) =>
        setData(
            'recipients',
            recipients.map((r, i) => (i === index ? value : r)),
        );

    return (
        <>
            <div className="space-y-4">
                <h3 className="text-sm font-semibold">Recipients and schedule</h3>

                <div className="space-y-6">
                    <div className="grid gap-2">
                        <Label>Default recipients</Label>
                        <div className="grid gap-2">
                            {recipients.map((recipient, index) => (
                                <div key={index} className="grid gap-2">
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="email"
                                            value={recipient}
                                            onChange={(e) => setRecipient(index, e.target.value)}
                                            placeholder="name@example.com"
                                            aria-label={`Recipient ${index + 1}`}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${recipient || `recipient ${index + 1}`}`}
                                            onClick={() =>
                                                setData(
                                                    'recipients',
                                                    recipients.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                    <InputError message={errors[`recipients.${index}`]} />
                                </div>
                            ))}
                        </div>
                        <div>
                            <Button type="button" variant="outline" size="sm" onClick={() => setData('recipients', [...recipients, ''])}>
                                <Plus className="size-3.5" />
                                Add recipient
                            </Button>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Who a sales report goes to. Whoever sends one can tick and untick this list, or add an address for that send only.
                        </p>
                        <InputError message={errors.recipients} />
                    </div>

                    <ReportScheduleFields
                        values={data}
                        days={data.report_email_daily_days}
                        setValue={setData}
                        setDays={(days) => setData('report_email_daily_days', days)}
                        errors={errors}
                    />

                    <label className="flex items-start gap-3">
                        <Checkbox
                            checked={data.report_email_include_cost === '1'}
                            onCheckedChange={(value) => setData('report_email_include_cost', value === true ? '1' : '0')}
                        />
                        <span className="grid gap-1">
                            <span className="text-sm font-medium">Include cost and profit in the automatic report</span>
                            <span className="text-muted-foreground text-xs">
                                Off by default. Cost is otherwise yours alone to see — turning this on sends it out of the business on a timer, to
                                whoever is on the list above. A report you send by hand always matches what you can see on screen.
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <Separator />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="grid gap-0.5">
                        <h3 className="text-sm font-semibold">Sender credentials</h3>
                        <p className="text-muted-foreground text-xs">The mail account reports are sent from. Set up on this device only.</p>
                    </div>

                    {/*
                     * type="button" is load-bearing — the trigger sits inside the
                     * settings form, and a bare <button> there submits it.
                     * DialogTrigger owns the open state, so no useState here.
                     */}
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button type="button" variant="ghost" size="sm" className="shrink-0">
                                <HelpCircle className="size-4" />
                                Setup guide
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="flex max-h-[90vh] w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl">
                            <DialogHeader className="shrink-0 border-b px-4 py-4 pr-12 sm:px-6">
                                <DialogTitle>Sender setup guide</DialogTitle>
                                <DialogDescription>The mail account reports are sent from, and how to prove it works.</DialogDescription>
                            </DialogHeader>
                            <div className="min-h-0 min-w-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6">
                                <ReportEmailGuide />
                            </div>
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="report_email_smtp_host">Sending server</Label>
                        <Input
                            id="report_email_smtp_host"
                            value={data.report_email_smtp_host}
                            onChange={(e) => setData('report_email_smtp_host', e.target.value)}
                            placeholder="smtp.gmail.com"
                        />
                        <p className="text-muted-foreground text-xs">
                            The SMTP server for the sending account. Gmail is <code>smtp.gmail.com</code> on port 587, and needs an app password
                            rather than the account password.
                        </p>
                        <InputError message={errors.report_email_smtp_host} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-[auto_1fr]">
                        <div className="grid gap-2">
                            <Label htmlFor="report_email_smtp_port">Port</Label>
                            <Input
                                id="report_email_smtp_port"
                                type="number"
                                className="w-28"
                                value={data.report_email_smtp_port}
                                onChange={(e) => setData('report_email_smtp_port', e.target.value)}
                                placeholder="587"
                            />
                            <InputError message={errors.report_email_smtp_port} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="report_email_smtp_encryption">Encryption</Label>
                            <Select
                                value={data.report_email_smtp_encryption}
                                onValueChange={(value) => setData('report_email_smtp_encryption', value)}
                            >
                                <SelectTrigger id="report_email_smtp_encryption" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="tls">STARTTLS — usual, with port 587</SelectItem>
                                    <SelectItem value="ssl">SSL/TLS — usual, with port 465</SelectItem>
                                    <SelectItem value="none">None — plain connection; the server may still upgrade it</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.report_email_smtp_encryption} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="report_email_smtp_username">Username</Label>
                        <Input
                            id="report_email_smtp_username"
                            value={data.report_email_smtp_username}
                            onChange={(e) => setData('report_email_smtp_username', e.target.value)}
                            autoComplete="off"
                            placeholder="reports@example.com"
                        />
                        <InputError message={errors.report_email_smtp_username} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="report_email_smtp_password">Password</Label>
                        <Input
                            id="report_email_smtp_password"
                            type="password"
                            value={data.report_email_smtp_password}
                            onChange={(e) => setData('report_email_smtp_password', e.target.value)}
                            autoComplete="new-password"
                            disabled={data.report_email_smtp_password_clear === '1'}
                            placeholder={passwordSet ? 'Leave blank to keep the saved password' : ''}
                        />
                        <p className="text-muted-foreground text-xs">Stored encrypted on this device, and never shown again once saved.</p>
                        {passwordSet && (
                            <label className="flex items-center gap-3">
                                <Checkbox
                                    checked={data.report_email_smtp_password_clear === '1'}
                                    onCheckedChange={(value) => setData('report_email_smtp_password_clear', value === true ? '1' : '0')}
                                />
                                <span className="text-sm">Remove the saved password</span>
                            </label>
                        )}
                        <InputError message={errors.report_email_smtp_password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="report_email_from_address">Send from</Label>
                        <Input
                            id="report_email_from_address"
                            type="email"
                            value={data.report_email_from_address}
                            onChange={(e) => setData('report_email_from_address', e.target.value)}
                            placeholder="reports@example.com"
                        />
                        <p className="text-muted-foreground text-xs">The address recipients will see, and usually the same as the username.</p>
                        <InputError message={errors.report_email_from_address} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="report_email_from_name">Sender name</Label>
                        <Input
                            id="report_email_from_name"
                            value={data.report_email_from_name}
                            onChange={(e) => setData('report_email_from_name', e.target.value)}
                            placeholder="Defaults to your business name"
                        />
                        <InputError message={errors.report_email_from_name} />
                    </div>
                </div>
            </div>
        </>
    );
}
