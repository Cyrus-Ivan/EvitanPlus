<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to shared business data was attempted without a connection (M6.5).
 *
 * Its own class because it is not an error in the ordinary sense and must never
 * be reported as one. Products, prices, staff and settings belong to the whole
 * business, so a change to them has to reach the business database — and a
 * device with no connection cannot make one. Nothing has gone wrong; the
 * operation simply has a precondition that is not met right now.
 *
 * Selling, returns, defects and printing are entirely unaffected. That is the
 * distinction the message has to carry, because the person reading it is
 * standing at a working till and needs to know the till is still working.
 */
class OfflineWriteException extends RuntimeException
{
    public static function forSubject(string $subject): self
    {
        return new self(
            "{$subject} is shared across all your branches, so it can only be changed while connected "
            .'to the business database. This device is offline right now — selling, returns and receipts '
            .'carry on working as normal. Reconnect and try again.'
        );
    }
}
