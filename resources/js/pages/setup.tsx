import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import PrinterSettingsFields, { type PrinterSettingsData } from '@/components/printer-settings-fields';
import TimezoneSelect from '@/components/timezone-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';

// The initial setup wizard for a fresh install (no users exist yet — see
// RequireInitialSetup). Steps are purely client-side over a single form; ONE
// atomic POST at the end creates the Owner, saves the settings, and signs in.
// Closing the app mid-wizard persists nothing, so it simply reappears.

interface SetupForm {
    // Owner account
    name: string;
    username: string;
    password: string;
    password_confirmation: string;
    // Business details + localization
    business_name: string;
    business_address: string;
    tin: string;
    receipt_footer: string;
    timezone: string;
    currency_symbol: string;
    // Printer (skippable)
    configure_printer: string;
    [key: string]: string;
}

const STEPS = ['Owner account', 'Business details', 'Localization', 'Printer'] as const;

// Which step each server-validated field lives on, so a validation error can
// send the user back to the right screen.
const FIELD_STEP: Record<string, number> = {
    name: 0,
    username: 0,
    password: 0,
    password_confirmation: 0,
    business_name: 1,
    business_address: 1,
    tin: 1,
    receipt_footer: 1,
    timezone: 2,
    currency_symbol: 2,
    configure_printer: 3,
    receipt_paper_size: 3,
    receipt_connection: 3,
    receipt_printer_name: 3,
    receipt_device_path: 3,
    receipt_network_host: 3,
    receipt_network_port: 3,
    auto_print: 3,
    open_cash_drawer: 3,
};

export default function Setup({ defaults, printerDefaults }: { defaults: Record<string, string>; printerDefaults: PrinterSettingsData }) {
    const [step, setStep] = useState(0);

    const { data, setData, errors, post, processing, transform } = useForm<SetupForm & PrinterSettingsData>({
        name: '',
        username: '',
        password: '',
        password_confirmation: '',
        business_name: defaults.business_name ?? '',
        business_address: defaults.business_address ?? '',
        tin: defaults.tin ?? '',
        receipt_footer: defaults.receipt_footer ?? '',
        timezone: defaults.timezone ?? 'Asia/Manila',
        currency_symbol: defaults.currency_symbol ?? '₱',
        configure_printer: '1',
        receipt_paper_size: printerDefaults.receipt_paper_size,
        receipt_connection: printerDefaults.receipt_connection,
        receipt_printer_name: printerDefaults.receipt_printer_name,
        receipt_device_path: printerDefaults.receipt_device_path,
        receipt_network_host: printerDefaults.receipt_network_host,
        receipt_network_port: printerDefaults.receipt_network_port,
        auto_print: printerDefaults.auto_print,
        open_cash_drawer: printerDefaults.open_cash_drawer,
    });

    const submit = (configurePrinter: boolean) => {
        transform((current) => ({ ...current, configure_printer: configurePrinter ? '1' : '0' }));
        post(route('setup.store'), {
            onError: (formErrors) => {
                // Jump back to the first step that has an errored field.
                const firstStep = Math.min(...Object.keys(formErrors).map((field) => FIELD_STEP[field] ?? 0));
                setStep(Number.isFinite(firstStep) ? firstStep : 0);
            },
        });
    };

    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <Head title="Setup" />

            <div className="w-full max-w-lg">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                            <AppLogoIcon className="size-9 object-contain" />
                        </div>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">Welcome to Evitan Plus</h1>
                            <p className="text-muted-foreground text-sm">Let's set up your store. This only takes a minute.</p>
                        </div>

                        {/* Step indicator */}
                        <ol className="flex items-center gap-2" aria-label="Setup progress">
                            {STEPS.map((label, i) => (
                                <li key={label} className="flex items-center gap-2">
                                    <span
                                        className={cn(
                                            'flex size-6 items-center justify-center rounded-full text-xs font-medium',
                                            i === step
                                                ? 'bg-primary text-primary-foreground'
                                                : i < step
                                                  ? 'bg-primary/20 text-primary'
                                                  : 'bg-muted text-muted-foreground',
                                        )}
                                        aria-current={i === step ? 'step' : undefined}
                                    >
                                        {i + 1}
                                    </span>
                                    <span className={cn('text-xs', i === step ? 'text-foreground font-medium' : 'text-muted-foreground')}>
                                        {label}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </div>

                    <form
                        className="flex flex-col gap-6"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (step < STEPS.length - 1) {
                                setStep(step + 1);
                            } else {
                                submit(true);
                            }
                        }}
                    >
                        {step === 0 && (
                            <>
                                <p className="text-muted-foreground text-sm">
                                    Create the Owner account — the one account with full access, including item costs, settings, and backups.
                                </p>

                                <div className="grid gap-2">
                                    <Label htmlFor="name">Your name</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        required
                                        autoFocus
                                        autoComplete="name"
                                        placeholder="Full name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="username">Username</Label>
                                    <Input
                                        id="username"
                                        value={data.username}
                                        onChange={(e) => setData('username', e.target.value)}
                                        required
                                        autoComplete="username"
                                        placeholder="Username for signing in"
                                    />
                                    <InputError message={errors.username} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        required
                                        autoComplete="new-password"
                                        placeholder="Password"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">Confirm password</Label>
                                    <Input
                                        id="password_confirmation"
                                        type="password"
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        required
                                        autoComplete="new-password"
                                        placeholder="Repeat the password"
                                    />
                                    <InputError message={errors.password_confirmation} />
                                </div>
                            </>
                        )}

                        {step === 1 && (
                            <>
                                <p className="text-muted-foreground text-sm">Business identity shown on receipts and reports.</p>

                                <div className="grid gap-2">
                                    <Label htmlFor="business_name">Business name</Label>
                                    <Input
                                        id="business_name"
                                        value={data.business_name}
                                        onChange={(e) => setData('business_name', e.target.value)}
                                        required
                                        autoFocus
                                        autoComplete="organization"
                                        placeholder="Business name"
                                    />
                                    <InputError message={errors.business_name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="business_address">Address</Label>
                                    <Textarea
                                        id="business_address"
                                        value={data.business_address}
                                        onChange={(e) => setData('business_address', e.target.value)}
                                        placeholder="Business address (optional)"
                                    />
                                    <InputError message={errors.business_address} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="tin">TIN</Label>
                                    <Input
                                        id="tin"
                                        value={data.tin}
                                        onChange={(e) => setData('tin', e.target.value)}
                                        placeholder="Tax Identification Number (optional)"
                                    />
                                    <InputError message={errors.tin} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="receipt_footer">Receipt footer</Label>
                                    <Textarea
                                        id="receipt_footer"
                                        value={data.receipt_footer}
                                        onChange={(e) => setData('receipt_footer', e.target.value)}
                                        placeholder="Message printed at the bottom of every receipt (optional)"
                                    />
                                    <InputError message={errors.receipt_footer} />
                                </div>
                            </>
                        )}

                        {step === 2 && (
                            <>
                                <p className="text-muted-foreground text-sm">How dates and money are shown across the app.</p>

                                <div className="grid gap-2">
                                    <Label htmlFor="timezone">Business timezone</Label>
                                    <TimezoneSelect id="timezone" value={data.timezone} onChange={(value) => setData('timezone', value)} />
                                    <p className="text-muted-foreground text-xs">
                                        Timestamps are stored in this timezone — choose it once and keep it.
                                    </p>
                                    <InputError message={errors.timezone} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="currency_symbol">Currency symbol</Label>
                                    <Input
                                        id="currency_symbol"
                                        className="w-28"
                                        value={data.currency_symbol}
                                        onChange={(e) => setData('currency_symbol', e.target.value)}
                                        required
                                        maxLength={5}
                                        placeholder="₱"
                                    />
                                    <p className="text-muted-foreground text-xs">Shown on prices, reports, and receipts.</p>
                                    <InputError message={errors.currency_symbol} />
                                </div>
                            </>
                        )}

                        {step === 3 && (
                            <>
                                <p className="text-muted-foreground text-sm">
                                    Connect a thermal receipt printer now, or skip and set it up later in Business Settings.
                                </p>

                                <PrinterSettingsFields
                                    data={data}
                                    setData={(key, value) => setData(key, value)}
                                    errors={errors}
                                    detectUrl={route('setup.printers')}
                                />
                            </>
                        )}

                        <div className="flex items-center justify-between gap-3">
                            <Button type="button" variant="ghost" onClick={() => setStep(step - 1)} className={cn(step === 0 && 'invisible')}>
                                Back
                            </Button>

                            <div className="flex items-center gap-3">
                                {step === STEPS.length - 1 && (
                                    <Button type="button" variant="outline" disabled={processing} onClick={() => submit(false)}>
                                        Skip for now
                                    </Button>
                                )}
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    {step < STEPS.length - 1 ? 'Next' : 'Finish setup'}
                                </Button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
