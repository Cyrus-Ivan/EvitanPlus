<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when something attempts to create or promote a second Owner. Evitan Plus
 * requires exactly one Owner — a core business invariant enforced at the model
 * layer so no code path (factory, seeder, service, import) can breach it.
 */
class MultipleOwnersException extends RuntimeException
{
    public function __construct(string $message = 'Only one Owner account is allowed.')
    {
        parent::__construct($message);
    }
}
