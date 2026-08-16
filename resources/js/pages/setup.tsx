import InputError from '@/components/forms/input-error';
import SubmitButton from '@/components/forms/submit-button';
import AppLogoIcon from '@/components/layout/app-logo-icon';
import PrinterSettingsFields, { type PrinterSettingsData } from '@/components/settings/printer-settings-fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { useEffect, useState } from 'react';

// The setup wizard for a device that is not yet a branch (M5). The Owner, the
// business identity and the catalog already exist in Postgres — this device
// imports a branch credential, connects, and copies the baseline down. Steps are
// client-side over ONE form; a single POST connects, hydrates and remembers who
// this device is, and nothing at all is written unless the whole of that
// succeeds. Closing the app mid-wizard therefore persists nothing.

// Booleans ride as '1'/'0' strings, matching PrinterSettingsData and the
// Laravel `boolean` rule (which accepts both).
interface SetupForm {
    credentials_path: string;
    delete_after_import: string;
    configure_printer: string;
    [key: string]: string;
}

const STEPS = ['Branch credentials', 'Printer'] as const;

// Which step each server-validated field lives on, so a validation error can
// send the user back to the right screen. Every connect/hydrate failure is
// reported on credentials_path, because that is the input that chose them.
const FIELD_STEP: Record<string, number> = {
    credentials_path: 0,
    delete_after_import: 0,
    configure_printer: 1,
    receipt_paper_size: 1,
    receipt_connection: 1,
    receipt_printer_name: 1,
    receipt_device_path: 1,
    receipt_network_host: 1,
    receipt_network_port: 1,
    auto_print: 1,
    open_cash_drawer: 1,
};

export default function Setup({
    printerDefaults,
    appVersion,
    desktop,
}: {
    printerDefaults: PrinterSettingsData;
    appVersion: string;
    desktop: boolean;
}) {
    const [step, setStep] = useState(0);
    const { flash } = usePage<SharedData>().props;

    const { data, setData, errors, post, processing, transform } = useForm<SetupForm & PrinterSettingsData>({
        credentials_path: '',
        delete_after_import: '1',
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

    // The native file dialog runs in the desktop runtime, so the chosen path
    // comes back as a flash prop rather than from the browser.
    useEffect(() => {
        if (flash.credentials_path) {
            setData('credentials_path', flash.credentials_path);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash.credentials_path]);

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
                            <h1 className="text-xl font-medium">Set up this branch</h1>
                            <p className="text-muted-foreground text-sm">
                                Import the credentials file for this branch. This device needs internet for this step only.
                            </p>
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
                                    The credentials file was issued for this branch when the business was set up. Everything else — the accounts, the
                                    product catalog and the business details — is copied from the business database.
                                </p>

                                <div className="grid gap-2">
                                    <Label htmlFor="credentials_path">Credentials file</Label>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            id="credentials_path"
                                            value={data.credentials_path}
                                            onChange={(e) => setData('credentials_path', e.target.value)}
                                            required
                                            autoFocus
                                            spellCheck={false}
                                            placeholder="/media/usb/biz_xxxx-MNL-20260729T101500Z.json"
                                        />
                                        {desktop && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => router.post(route('setup.browse'), {}, { preserveScroll: true })}
                                            >
                                                <FolderOpen className="h-4 w-4" />
                                                Browse
                                            </Button>
                                        )}
                                    </div>
                                    <InputError message={errors.credentials_path} />
                                </div>

                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="delete_after_import"
                                        checked={data.delete_after_import === '1'}
                                        onCheckedChange={(checked) => setData('delete_after_import', checked === true ? '1' : '0')}
                                    />
                                    <div className="grid gap-1">
                                        <Label htmlFor="delete_after_import">Delete the file after importing it</Label>
                                        <p className="text-muted-foreground text-xs">
                                            It holds this branch's database password in plain text. Once imported, this device keeps its own encrypted
                                            copy and the file is no longer needed.
                                        </p>
                                    </div>
                                </div>

                                <p className="text-muted-foreground text-xs">App version {appVersion}</p>
                            </>
                        )}

                        {step === 1 && (
                            <>
                                <p className="text-muted-foreground text-sm">
                                    Connect a thermal receipt printer now, or skip and set it up later in Business Settings. This is the one setting
                                    that belongs to this device rather than to the business.
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
                                <SubmitButton processing={processing}>{step < STEPS.length - 1 ? 'Next' : 'Connect and set up'}</SubmitButton>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
