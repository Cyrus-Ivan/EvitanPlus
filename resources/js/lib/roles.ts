import { type UserRole } from '@/types';

// Single client-side mirror of App\Enums\UserRole::level() — the one place the
// Owner > Admin > Cashier hierarchy is encoded on the frontend. Keep in sync
// with the backend enum; everything else imports from here so there is no
// duplicated hierarchy to drift.
export const ROLE_LEVEL: Record<UserRole, number> = {
    owner: 3,
    admin: 2,
    cashier: 1,
};

/**
 * What a role is called on screen.
 *
 * Lives beside the hierarchy for the same reason the hierarchy lives here: it
 * had been declared twice, in the role dialog and in the employee day dialog,
 * and a role added to `ROLE_LEVEL` would have been named in neither.
 */
export const ROLE_LABEL: Record<UserRole, string> = {
    owner: 'Owner',
    admin: 'Admin',
    cashier: 'Cashier',
};

/** A role's label, falling back to the raw value for one this build predates. */
export function roleLabel(role: UserRole | string): string {
    return ROLE_LABEL[role as UserRole] ?? role;
}

/** Whether `role` clears a minimum-role requirement (mirrors UserRole::atLeast). */
export function roleAtLeast(role: UserRole, minRole: UserRole): boolean {
    return ROLE_LEVEL[role] >= ROLE_LEVEL[minRole];
}

/** Whether `actor` strictly outranks `target` (mirrors UserPolicy::outranks). */
export function outranks(actor: UserRole, target: UserRole): boolean {
    return ROLE_LEVEL[actor] > ROLE_LEVEL[target];
}
