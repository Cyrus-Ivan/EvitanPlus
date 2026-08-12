<?php

namespace App\Support\Email;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The single send path for anything this app emails.
 *
 * Sends SYNCHRONOUSLY. There is no queue worker in the packaged build
 * (`config/nativephp.php` → `queue_workers` is empty and nothing in the app is
 * ShouldQueue), so a queued mail would sit in the jobs table until somebody
 * noticed the report had never arrived. The cost is a request that blocks for
 * up to {@see SmtpTransportConfig::TIMEOUT_SECONDS}.
 *
 * Every recipient goes on one message's To: header rather than one message per
 * address. They are the business's own people and the document is identical, so
 * N sends would only multiply the ways it can half-fail — and a partial success
 * is a state with no honest thing to say on screen.
 */
class ReportMailer
{
    /**
     * A mailer of its own, so the stock `smtp` configuration is never
     * overwritten and a second mail path (if one ever arrives) is unaffected.
     */
    public const MAILER = 'report_email';

    public function __construct(private readonly ReportEmailSettings $settings) {}

    /**
     * @param  list<string>  $recipients
     *
     * @throws ReportEmailException
     */
    public function send(array $recipients, Mailable $mail): void
    {
        if (! $this->settings->isConfigured()) {
            throw new ReportEmailException(
                'Report email is not set up yet. An owner can configure it in Business Settings.',
            );
        }

        if ($recipients === []) {
            throw new ReportEmailException('There is nobody to send this to.');
        }

        config(['mail.mailers.'.self::MAILER => SmtpTransportConfig::from($this->settings)]);

        try {
            Mail::mailer(self::MAILER)->to($recipients)->send($mail);
        } catch (Throwable $e) {
            report($e);

            throw new ReportEmailException($this->humanise($e), previous: $e);
        } finally {
            // MailManager caches resolved mailers and the NativePHP PHP process
            // is long-lived, so without this a settings change would be ignored
            // until the app restarted — and the decrypted password would sit in
            // the config repository for the life of the process.
            Mail::purge(self::MAILER);
            config(['mail.mailers.'.self::MAILER => null]);
        }
    }

    /**
     * A sentence naming the remedy, for the toast. The original exception is
     * already in the log by the time this runs, so nothing is lost by not
     * showing a stack trace to somebody standing at a till.
     */
    private function humanise(Throwable $e): string
    {
        $message = $e->getMessage();

        if (! $e instanceof TransportExceptionInterface) {
            return 'The report could not be sent: '.$message;
        }

        return match (true) {
            str_contains($message, '535') || stripos($message, 'authentication') !== false => 'The mail server rejected the username or password. Check the sender credentials in Business Settings.',
            stripos($message, 'ssl') !== false || stripos($message, 'tls') !== false => 'The secure connection to the mail server failed. Check the port and encryption in Business Settings.',
            stripos($message, 'connection') !== false || stripos($message, 'timed out') !== false => 'The mail server could not be reached. Check the server address, the port, and this device\'s connection.',
            default => 'The report could not be sent: '.$message,
        };
    }
}
