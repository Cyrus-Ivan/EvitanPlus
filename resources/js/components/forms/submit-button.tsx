import { Button, type ButtonProps } from '@/components/ui/button';
import { LoaderCircle } from 'lucide-react';

/**
 * The submit button of any form that posts — 19 of them at the time this was
 * written, each having spelled out the same spinner.
 *
 * `processing` both disables the button and shows the spinner, because those two
 * had drifted apart: a couple of sites disabled without a spinner, so a slow
 * save on a busy till looked like a dead button. They are one state and it is
 * spelled once here.
 *
 * The spinner sizing settles a split between `h-4 w-4` (19 sites) and
 * `mr-2 size-4` (3). The margin was never needed — `buttonVariants` already puts
 * `gap-2` between a button's children — and on the three sites that carried it
 * the icon sat a little further from its label than everywhere else.
 */
export default function SubmitButton({ processing, children, disabled, ...props }: ButtonProps & { processing?: boolean }) {
    return (
        <Button type="submit" disabled={disabled || processing} {...props}>
            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
            {children}
        </Button>
    );
}
