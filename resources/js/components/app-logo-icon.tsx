import { cn } from '@/lib/utils';

type Variant = 'auto' | 'onLight' | 'onDark';

interface AppLogoIconProps {
    className?: string;
    variant?: Variant;
}

/**
 * Evitan "E" mark. Two colour variants live in /public:
 *   - logo-black.png for light surfaces
 *   - logo-white.png for dark surfaces
 *
 * `variant`:
 *   - 'auto'    (default) follows the app theme (Tailwind `dark:` on <html>).
 *   - 'onLight' always black (fixed light surface).
 *   - 'onDark'  always white (fixed dark surface, e.g. the auth split panel).
 */
export default function AppLogoIcon({ className, variant = 'auto' }: AppLogoIconProps) {
    if (variant === 'onLight') {
        return <img src="/logo-black.png" alt="Evitan Plus" className={className} />;
    }

    if (variant === 'onDark') {
        return <img src="/logo-white.png" alt="Evitan Plus" className={className} />;
    }

    return (
        <>
            <img src="/logo-black.png" alt="Evitan Plus" className={cn('block dark:hidden', className)} />
            <img src="/logo-white.png" alt="Evitan Plus" className={cn('hidden dark:block', className)} />
        </>
    );
}
