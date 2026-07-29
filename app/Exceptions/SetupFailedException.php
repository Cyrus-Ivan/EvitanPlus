<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A setup attempt that got far enough to fail for a reason the developer
 * standing at the device can act on: an unreadable file, a document shape we do
 * not know, a credential that will not connect, a JSON belonging to another
 * schema, a build the schema refuses to talk to.
 *
 * Every one of these leaves the device exactly as it was — the wizard persists
 * nothing until the whole cycle succeeds — so the message is the entire remedy,
 * and it is shown verbatim on the wizard's first step. Which is why they are
 * written as instructions rather than as diagnoses.
 */
class SetupFailedException extends RuntimeException
{
    //
}
