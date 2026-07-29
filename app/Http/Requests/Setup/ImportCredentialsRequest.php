<?php

namespace App\Http\Requests\Setup;

use App\Support\Printing\PrinterSettingsRules;
use App\Support\Sync\CredentialsDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The setup wizard's single atomic submit (M5): the path to a branch credentials
 * JSON, whether to delete that file once it has been imported, and — optionally
 * — this device's printer configuration.
 *
 * Everything the old wizard collected about the BUSINESS is gone: the Owner,
 * the business name, TIN, receipt footer, timezone and currency all exist
 * already in Postgres, created when the business was provisioned. Printer config
 * is the only thing here that belongs to the device rather than to the business,
 * which is exactly why it is the only step that survived.
 *
 * No authorization — the wizard runs pre-auth on a device that has no users yet,
 * gated by RequireInitialSetup.
 *
 * The document's own shape is NOT validated here. It is validated by
 * {@see CredentialsDocument}, which has to reject unknown
 * envelope versions with an explanation, and a `json` rule would only get to say
 * "invalid".
 */
class ImportCredentialsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A path on this machine, not an upload: the file is a 0600
            // credential delivered on a stick, and knowing where it lives is
            // what lets the wizard offer to remove it afterwards.
            'credentials_path' => ['required', 'string', 'max:4096'],
            'delete_after_import' => ['required', 'boolean'],

            'configure_printer' => ['required', 'boolean'],
            ...($this->boolean('configure_printer') ? PrinterSettingsRules::rules($this) : []),
        ];
    }

    public function credentialsPath(): string
    {
        return trim((string) $this->validated('credentials_path'));
    }

    /**
     * The printer-settings payload, with booleans normalised to the '1'/'0'
     * strings the settings table stores. Only valid when configure_printer.
     *
     * @return array<string, mixed>
     */
    public function printerPayload(): array
    {
        $data = $this->validated();

        $data['auto_print'] = $this->boolean('auto_print') ? '1' : '0';
        $data['open_cash_drawer'] = $this->boolean('open_cash_drawer') ? '1' : '0';

        return collect($data)->only([
            'receipt_paper_size',
            'receipt_connection',
            'receipt_printer_name',
            'receipt_device_path',
            'receipt_network_host',
            'receipt_network_port',
            'auto_print',
            'open_cash_drawer',
        ])->all();
    }
}
