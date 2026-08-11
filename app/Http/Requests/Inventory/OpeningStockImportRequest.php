<?php

namespace App\Http\Requests\Inventory;

use App\Models\Instance;
use App\Support\Inventory\OpeningStockImporter;
use App\Support\Inventory\OpeningWindow;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload validation for the opening-stock CSV. Only the file envelope is checked
 * here — per-row content validation lives in {@see OpeningStockImporter} so it
 * can produce row-attributed errors.
 *
 * Authorization is four things stacked, and all four are refusals rather than
 * field errors: none of them is fixed by choosing a different file.
 *
 *  1. The `role:admin` route group.
 *  2. The `manage-inventory` gate.
 *  3. The opening window ({@see OpeningWindow}) — this branch has never sold
 *     anything, so there is no shelf history for a spreadsheet to contradict.
 *  4. The stated target branch ({@see targetsThisBranch()}), below.
 *
 * ## Why the request has to NAME the branch it means
 *
 * `/inventory` carries a branch scope, and it changes exactly one thing: the
 * Stock column. The product list, the filters and the paging are read locally in
 * every scope — so under `?scope=<other branch>` the screen is THIS device's
 * catalog wearing ANOTHER branch's numbers, under that branch's heading.
 *
 * An import fired from that screen cannot do what the heading implies, and every
 * layer beneath would hide it rather than complain: the importer's
 * `quantity − current stock` is computed against this device's own shelf,
 * `OnlineWrite` stamps `branch_id` from `instance` whatever the URL said, and
 * `OpeningWindow` reads the local ledger and so answers only for this branch.
 * The result is not an error — it is six hundred rows landing on the wrong
 * branch under a toast that says they succeeded.
 *
 * So the caller states the branch it believes it is writing to and this refuses
 * any answer but "this one", the way `confirm_updates` makes the item import
 * state that somebody looked. The page hiding the button when the scope is
 * elsewhere is the convenience; this is the rule. A request that names no branch
 * at all is refused too — omission is the one bypass a stated intent must not
 * have.
 */
class OpeningStockImportRequest extends FormRequest
{
    /** The scope value meaning "this device's own shelf" (docs/glossary.md §"Branch scope"). */
    private const SCOPE_LOCAL = 'local';

    public function authorize(): Response
    {
        if (! ($this->user()?->can('manage-inventory') ?? false)) {
            return Response::deny();
        }

        if (! OpeningWindow::isOpen()) {
            return Response::deny(
                'This branch has already made sales, so opening stock can no longer be imported. '
                .'Use a stock batch on the Inventory page — those are confirmed by somebody at the '
                .'branch before stock moves.',
            );
        }

        if (! $this->targetsThisBranch()) {
            return Response::deny(
                'An opening stock import always sets THIS branch\'s stock. '
                .'Switch the branch filter back to this branch and try again.',
            );
        }

        return Response::allow();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Same envelope as the item import: spreadsheets stamp text/plain on
            // .csv often enough to accept it, and 5 MB is far past any realistic
            // opening file.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    /**
     * Does the caller say it is writing to this branch?
     *
     * Accepts `local` — what the picker actually submits — or this branch's own
     * id, which `OffersBranchScope` treats as the same thing everywhere else.
     * Anything else, including nothing, is a no: a missing value is a caller that
     * never considered the question, which is precisely the case the screen's
     * heading would have answered wrongly.
     *
     * `Instance::current()` is null only on a device that was never hydrated, and
     * the empty string it yields cannot match a scope that has already been
     * checked for emptiness — so an un-hydrated device is refused rather than
     * accidentally matched.
     */
    private function targetsThisBranch(): bool
    {
        $scope = $this->input('scope');

        if (! is_string($scope) || $scope === '') {
            return false;
        }

        return $scope === self::SCOPE_LOCAL
            || $scope === (string) Instance::current()?->branch_id;
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
