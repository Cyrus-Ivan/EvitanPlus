<?php

namespace App\Support\Http;

use App\Http\Controllers\Concerns\OffersBranchScope;

/**
 * A query-string filter as the text it is supposed to be, or nothing.
 *
 * ## Why this exists rather than a `(string)` cast
 *
 * Every list screen normalises its filters through a small private helper that
 * began with `(string) $value` on a raw `$request->query(...)`. That is right for
 * what a form sends and wrong for what a URL can carry: `?search[]=x` hands the
 * helper an **array**, `(string) []` raises `Warning: Array to string
 * conversion`, and `HandleExceptions` promotes any warning inside the
 * error-reporting mask to an `ErrorException`. The screen 500s.
 *
 * It affected roughly every list in the product at once — sales, reports, login
 * history, employee performance, pending stocks, inventory, items, users and the
 * POS grid — because they all reached for the same idiom. So the answer is one
 * named thing rather than nine copies of a defensive cast: **a value that is not
 * text is not a filter**, and reads as no filter at all.
 *
 * Scalars are still accepted, because `?page=2`-style numeric input is
 * legitimately a string filter arriving as one; only the shapes that cannot be
 * text (arrays, objects) collapse to empty.
 *
 * ✗ Not for `scope`. That parameter decides which BRANCHES a screen reads, so
 * where it falls back matters in a way these do not — see
 * {@see OffersBranchScope::normalizeScope()},
 * which applies the same rule and then keeps going.
 */
final class FilterText
{
    /**
     * The value as trimmed text, or `''` when it cannot be text at all.
     */
    public static function of(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        // int|float|bool are all legitimately spellable in a query string and
        // convert without a warning. Everything else — array, object, null — is
        // not a filter anybody typed.
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The value as trimmed text, or null when blank — the shape a filter that is
     * "absent" wants, so `?search=` and no `search` at all mean the same thing.
     */
    public static function orNull(mixed $value): ?string
    {
        return self::of($value) ?: null;
    }
}
