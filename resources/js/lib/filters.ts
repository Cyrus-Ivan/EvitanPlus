/**
 * The sentinel a filter uses for "don't filter on this".
 *
 * Radix's `Select` cannot hold an empty string as an item value, so every
 * optional filter on every list page needs a stand-in for "no choice made". Each
 * page had declared its own — `ALL` on four pages, `ALL_CATEGORIES` on two,
 * `ANY` on one — which is seven declarations of one idea under three names, and
 * three chances for a page to send `all` to a controller expecting `any`.
 *
 * The value is stripped before the request goes out (see `useListFilters`): the
 * server never sees it, so it is a UI convention rather than a wire format, and
 * this is the only place it needs to be true.
 */
export const ALL = 'all';
