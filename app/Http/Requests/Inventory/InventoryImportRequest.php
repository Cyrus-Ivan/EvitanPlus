<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload validation for the inventory CSV import (Phase 7). Only the file
 * envelope is checked here — per-row content validation lives in
 * CsvInventoryImporter so it can produce user-friendly, row-attributed errors.
 * Authorization is the `role:admin` route group plus a second `manage-inventory`
 * gate check in authorize() (defense-in-depth).
 */
class InventoryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-inventory') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // NativePHP desktop app: CSVs are exported from spreadsheets, so also
            // accept the text/plain mime some tools stamp on .csv files. 5 MB cap.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            // "I have seen which existing products this file changes and I mean
            // it." Absent on the preview post and on the modal's first attempt;
            // what it unlocks is decided by the importer, which is the only place
            // that knows whether the file touches anything that already exists.
            'confirm_updates' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to upload.',
            'file.mimes' => 'The file must be a CSV.',
            'file.max' => 'The file may not be larger than 5 MB.',
        ];
    }
}
