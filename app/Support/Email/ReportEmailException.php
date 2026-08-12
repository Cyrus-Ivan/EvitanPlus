<?php

namespace App\Support\Email;

use App\Support\Printing\PrinterException;
use RuntimeException;

/**
 * Thrown when a report fails to reach its recipients (no sender configured,
 * connection refused, authentication rejected, TLS handshake failed). Carries a
 * message safe to surface to the operator.
 *
 * Parallel to {@see PrinterException} and for the same
 * reason: a delivery failure is a toast, never a 500 and never a lost page. The
 * underlying exception is always report()ed before this one is thrown, so the
 * detail survives in the log even though the operator sees a sentence.
 */
class ReportEmailException extends RuntimeException {}
