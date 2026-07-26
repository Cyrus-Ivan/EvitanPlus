import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 shrink-0 items-center justify-center">
                <AppLogoIcon className="size-8 object-contain group-data-[collapsible=icon]:size-6" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm group-data-[collapsible=icon]:hidden">
                <span className="mb-0.5 truncate leading-none font-semibold">Evitan Plus</span>
            </div>
        </>
    );
}
