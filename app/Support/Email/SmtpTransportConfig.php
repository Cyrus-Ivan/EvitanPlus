<?php

namespace App\Support\Email;

/**
 * Maps the stored report-email settings onto the runtime mailer array Laravel's
 * MailManager expects.
 *
 * A class of its own rather than four lines inside {@see ReportMailer} because
 * `Mail::fake()` replaces the manager entirely and never reaches a transport —
 * so every feature test in the suite passes whatever this mapping says, right
 * or wrong. It is unit-tested directly instead.
 */
class SmtpTransportConfig
{
    /**
     * Long enough for a slow handshake, short enough that an operator waiting
     * on an Inertia POST gets an answer. Laravel ships `null` here, which is
     * PHP's default socket timeout — far too long for a desktop UI, and the
     * send is synchronous because this app runs no queue worker.
     */
    public const TIMEOUT_SECONDS = 15;

    /**
     * @return array<string, mixed>
     */
    public static function from(ReportEmailSettings $settings): array
    {
        return [
            'transport' => 'smtp',
            // Symfony derives implicit TLS from the scheme. There is no knob
            // that FORBIDS opportunistic STARTTLS, so 'none' means "do not
            // start encrypted", not "will stay in the clear" — which is what
            // the settings field says too.
            'scheme' => $settings->encryption() === 'ssl' ? 'smtps' : 'smtp',
            'host' => $settings->host(),
            'port' => $settings->port(),
            // Null, never '': an empty string makes Symfony attempt AUTH with
            // an empty user, which a server needing no authentication rejects —
            // and the operator reads that as "wrong password".
            'username' => $settings->username(),
            'password' => $settings->password(),
            'timeout' => self::TIMEOUT_SECONDS,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
        ];
    }
}
