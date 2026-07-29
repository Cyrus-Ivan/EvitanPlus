import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { OfflineNotice } from '@/components/offline-notice';
import PrinterSettingsFields, { type PrinterSettingsData } from '@/components/printer-settings-fields';
import TimezoneSelect from '@/components/timezone-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Printer } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Business Settings', href: '/business-settings' }];

interface BusinessSettingsData {
    business_name: string;
    business_address: string;
    tin: string;
    receipt_footer: string;
    timezone: string;
    currency_symbol: string;
    [key: string]: string;
}

export default function BusinessSettings({
    settings,
    printerSettings,
    online,
}: {
    settings: BusinessSettingsData;
    printerSettings: PrinterSettingsData;
    online: boolean;
}) {
    const { data, setData, errors, patch, processing, recentlySuccessful } = useForm<BusinessSettingsData>({
        business_name: settings.business_name,
        business_address: settings.business_address,
        tin: settings.tin,
        receipt_footer: settings.receipt_footer,
        timezone: settings.timezone,
        currency_symbol: settings.currency_symbol,
    });

    const printer = useForm<PrinterSettingsData>({
        receipt_paper_size: printerSettings.receipt_paper_size,
        receipt_connection: printerSettings.receipt_connection,
        receipt_printer_name: printerSettings.receipt_printer_name,
        receipt_device_path: printerSettings.receipt_device_path,
        receipt_network_host: printerSettings.receipt_network_host,
        receipt_network_port: printerSettings.receipt_network_port,
        auto_print: printerSettings.auto_print,
        open_cash_drawer: printerSettings.open_cash_drawer,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('business-settings.update'), { preserveScroll: true });
    };

    const submitPrinter: FormEventHandler = (e) => {
        e.preventDefault();
        printer.patch(route('printer-settings.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Business Settings" />

            <div className="flex min-h-full flex-1 flex-col gap-8 p-4">
                {!online && <OfflineNotice what="Your business details" />}
                <section className="space-y-4">
                    <HeadingSmall title="Business Settings" description="Business identity used on receipts and reports." />

                    <form onSubmit={submit} className="max-w-xl space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="business_name">Business name</Label>
                            <Input
                                id="business_name"
                                value={data.business_name}
                                onChange={(e) => setData('business_name', e.target.value)}
                                required
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
                                placeholder="Business address"
                            />
                            <InputError message={errors.business_address} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="tin">TIN</Label>
                            <Input
                                id="tin"
                                value={data.tin}
                                onChange={(e) => setData('tin', e.target.value)}
                                placeholder="Tax Identification Number"
                            />
                            <InputError message={errors.tin} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="timezone">Business timezone</Label>
                            <TimezoneSelect id="timezone" value={data.timezone} onChange={(value) => setData('timezone', value)} />
                            <p className="text-muted-foreground text-xs">
                                Timestamps are stored in this timezone. Changing it later shifts how existing dates are shown — choose it once and
                                keep it.
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
                                maxLength={5}
                                placeholder="₱"
                            />
                            <p className="text-muted-foreground text-xs">Shown on prices, reports, and receipts.</p>
                            <InputError message={errors.currency_symbol} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="receipt_footer">Receipt footer</Label>
                            <Textarea
                                id="receipt_footer"
                                value={data.receipt_footer}
                                onChange={(e) => setData('receipt_footer', e.target.value)}
                                placeholder="Message printed at the bottom of every receipt"
                            />
                            <InputError message={errors.receipt_footer} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Save
                            </Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </section>

                <Separator className="max-w-xl" />

                <section className="space-y-4">
                    <HeadingSmall
                        title="Receipt printing"
                        description="Configure the thermal receipt printer. Sends raw ESC/POS — via the OS print queue, a device file, or the network."
                    />

                    <form onSubmit={submitPrinter} className="max-w-xl space-y-6">
                        <PrinterSettingsFields
                            data={printer.data}
                            setData={(key, value) => printer.setData(key, value)}
                            errors={printer.errors}
                            detectUrl={route('printer-settings.printers')}
                        />

                        <div className="flex items-center gap-3">
                            <Button disabled={printer.processing}>
                                {printer.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.post(route('printer-settings.test'), {}, { preserveScroll: true })}
                            >
                                <Printer className="size-4" />
                                Test print
                            </Button>

                            <Transition
                                show={printer.recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                        <p className="text-muted-foreground text-xs">Save your changes before running a test print.</p>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
