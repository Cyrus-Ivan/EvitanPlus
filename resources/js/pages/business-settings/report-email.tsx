import HeadingSmall from '@/components/heading-small';
import ReportEmailSettingsFields, { type ReportEmailSettingsData } from '@/components/report-email-settings-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import BusinessSettingsLayout from '@/layouts/business-settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Business Settings', href: '/business-settings' },
    { title: 'Report email', href: '/business-settings/report-email' },
];

// What the server hands back: the stored configuration minus the password,
// plus whether one is set at all. Spelled out rather than derived from
// ReportEmailSettingsData, whose index signature (which useForm needs) would
// widen every field to `string | string[]`.
interface ReportEmailSettingsProp {
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
    report_email_from_address: string;
    report_email_from_name: string;
    password_set: string;
}

export default function ReportEmail({ reportEmailSettings }: { reportEmailSettings: ReportEmailSettingsProp }) {
    const email = useForm<ReportEmailSettingsData>({
        recipients: reportEmailSettings.recipients,
        report_email_daily_enabled: reportEmailSettings.report_email_daily_enabled,
        report_email_daily_days: reportEmailSettings.report_email_daily_days,
        report_email_daily_time: reportEmailSettings.report_email_daily_time,
        report_email_weekly_enabled: reportEmailSettings.report_email_weekly_enabled,
        report_email_weekly_time: reportEmailSettings.report_email_weekly_time,
        report_email_monthly_enabled: reportEmailSettings.report_email_monthly_enabled,
        report_email_monthly_time: reportEmailSettings.report_email_monthly_time,
        report_email_yearly_enabled: reportEmailSettings.report_email_yearly_enabled,
        report_email_yearly_time: reportEmailSettings.report_email_yearly_time,
        report_email_include_cost: reportEmailSettings.report_email_include_cost,
        report_email_smtp_host: reportEmailSettings.report_email_smtp_host,
        report_email_smtp_port: reportEmailSettings.report_email_smtp_port,
        report_email_smtp_encryption: reportEmailSettings.report_email_smtp_encryption,
        report_email_smtp_username: reportEmailSettings.report_email_smtp_username,
        // Never seeded from the server — the ciphertext does not cross, and a
        // blank field means "keep the saved password".
        report_email_smtp_password: '',
        report_email_smtp_password_clear: '0',
        report_email_from_address: reportEmailSettings.report_email_from_address,
        report_email_from_name: reportEmailSettings.report_email_from_name,
    });

    const submitEmail: FormEventHandler = (e) => {
        e.preventDefault();
        email.patch(route('report-email-settings.update'), {
            preserveScroll: true,
            // The saved ciphertext is untouched by a blank submit, so leaving
            // the typed password in the box would only invite a second save
            // that re-seals the same string.
            onSuccess: () => email.setData('report_email_smtp_password', ''),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Report Email" />

            <BusinessSettingsLayout>
                <section className="space-y-4">
                    <HeadingSmall
                        title="Report email"
                        description="Who sales reports go to, how often they go out on their own, and the account they are sent from."
                    />

                    <form onSubmit={submitEmail} className="max-w-xl space-y-6">
                        <ReportEmailSettingsFields
                            data={email.data}
                            setData={(key, value) => email.setData(key, value)}
                            errors={email.errors}
                            passwordSet={reportEmailSettings.password_set === '1'}
                        />

                        <div className="flex items-center gap-3">
                            <Button disabled={email.processing}>
                                {email.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.post(route('report-email-settings.test'), {}, { preserveScroll: true })}
                            >
                                <Mail className="size-4" />
                                Send test email
                            </Button>

                            <Transition
                                show={email.recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                        <p className="text-muted-foreground text-xs">Save your changes before sending a test email.</p>
                    </form>
                </section>
            </BusinessSettingsLayout>
        </AppLayout>
    );
}
