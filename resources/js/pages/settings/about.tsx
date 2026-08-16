import AppLogoIcon from '@/components/layout/app-logo-icon';
import HeadingSmall from '@/components/page/heading-small';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'About',
        href: '/settings/about',
    },
];

interface AboutProps {
    appName: string;
    version: string;
    copyright: string;
}

export default function About({ appName, version, copyright }: AboutProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="About" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="About" description="Application information" />

                    <div className="flex items-center gap-4">
                        <AppLogoIcon className="h-12 w-auto" />
                        <div>
                            <p className="font-semibold">{appName}</p>
                            <p className="text-muted-foreground text-sm">Offline-first retail point of sale</p>
                        </div>
                    </div>

                    <dl className="space-y-2 text-sm">
                        <div className="flex gap-2">
                            <dt className="text-muted-foreground w-24">Version</dt>
                            <dd className="tabular-nums">{version}</dd>
                        </div>
                        <div className="flex gap-2">
                            <dt className="text-muted-foreground w-24">Copyright</dt>
                            <dd>{copyright}</dd>
                        </div>
                    </dl>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
