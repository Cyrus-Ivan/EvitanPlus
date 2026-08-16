import type { LoginEventType } from '@/types';

/**
 * How a login event is named and toned, shared by the three surfaces that show
 * one: the event filter's options, the calendar cell, and the day dialog.
 *
 * Clean sign-in/out reads neutral; the abnormal terminations (crash / power loss
 * / forced) read as warnings — the whole reason anybody scans this screen.
 */
export const EVENT: Record<LoginEventType, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    login: { label: 'Login', variant: 'default' },
    logout: { label: 'Logout', variant: 'secondary' },
    // Any unclean exit (window closed / shutdown / crash / force-kill) is detected
    // on the next launch and recorded uniformly as application_crash.
    application_crash: { label: 'Unexpected exit', variant: 'destructive' },
    forced_logout: { label: 'Forced logout', variant: 'destructive' },
};

/** An event's label, falling back to the raw value for one this build predates. */
export function eventLabel(type: LoginEventType): string {
    return EVENT[type]?.label ?? type;
}

/**
 * A dot colour per event, for the calendar cell.
 *
 * The cell cannot afford a Badge per line — a busy day would be a wall of
 * pills — so the tone is carried by a dot and the label by the text beside it.
 * The pairing with `EVENT`'s variants is deliberate: destructive events are the
 * ones that must catch the eye at a glance across a whole month.
 */
export const EVENT_DOT: Record<LoginEventType, string> = {
    login: 'bg-emerald-500',
    logout: 'bg-muted-foreground',
    application_crash: 'bg-destructive',
    forced_logout: 'bg-destructive',
};
