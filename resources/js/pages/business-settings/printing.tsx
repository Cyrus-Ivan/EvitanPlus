import SubmitButton from '@/components/forms/submit-button';
import HeadingSmall from '@/components/page/heading-small';
import PrinterSettingsFields, { type PrinterSettingsData } from '@/components/settings/printer-settings-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import BusinessSettingsLayout from '@/layouts/business-settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Business Settings', href: '/business-settings' },
    { title: 'Receipt printing', href: '/business-settings/printing' },
];

export default function Printing({ printerSettings }: { printerSettings: PrinterSettingsData }) {
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

    const submitPrinter: FormEventHandler = (e) => {
        e.preventDefault();
        printer.patch(route('printer-settings.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Receipt Printing" />

            <BusinessSettingsLayout>
                <section className="space-y-4">
                    {/* No offline notice, here or on Report email: the printer
                        is this device's hardware and saving it needs nothing
                        but the device. */}
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
                            <SubmitButton processing={printer.processing}>Save</SubmitButton>
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
            </BusinessSettingsLayout>
        </AppLayout>
    );
}
