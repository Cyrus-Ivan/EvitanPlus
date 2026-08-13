<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessSettingsUpdateRequest;
use App\Models\Setting;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SettingsDiff;
use App\Support\Settings\BusinessSettings;
use App\Support\Sync\OnlineWrite;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business identity is SHARED (§5.1) and the receipt printer is not — the same
 * split `Hydrator` already makes, and the reason the three sections of the
 * business-settings area are three controllers rather than one.
 *
 * The six business keys (`BusinessSettings::KEYS`) name the business itself and
 * print on every receipt at every branch, so changing one is an ONLINE WRITE.
 * The printer keys live in the same local table and are device hardware: they
 * have no upstream row, they are never mirrored, and `PrinterSettingsController`
 * is deliberately untouched by M6.5. The report-email keys are device-local for
 * the same reason and one more — the sender's password is sealed under the
 * per-install APP_KEY, which does not travel between installs.
 */
class BusinessSettingsController extends Controller
{
    public function __construct(
        private readonly BusinessSettings $settings,
        private readonly OnlineWrite $online,
    ) {}

    /**
     * Show the business identity section — the first of the three, and the one
     * this area's own URL lands on.
     *
     * The only section that pays for the connection probe: it is the only one
     * whose write is an online write, and the notice it feeds says so.
     */
    public function edit(): Response
    {
        return Inertia::render('business-settings/business', [
            'settings' => $this->settings->all(),
            'online' => $this->online->possible(),
        ]);
    }

    /**
     * Update the business identity settings.
     */
    public function update(BusinessSettingsUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $before = $this->settings->all();

        $this->online->run(function () use ($request, $audit, $before): void {
            $this->settings->update($request->validated());

            // Mirrored by key and restricted to the six business keys, so a
            // printer row in the same table is never sent to a schema that has
            // no column for it.
            $this->online->mirror(...Setting::query()->whereIn('key', BusinessSettings::KEYS)->get()->all());

            $diff = SettingsDiff::changed($before, $this->settings->all());

            if ($diff !== null) {
                $audit->record(
                    action: 'settings.updated',
                    description: 'Updated business settings: '.implode(', ', array_keys($diff['new'])).'.',
                    old: $diff['old'],
                    new: $diff['new'],
                );
            }
        }, 'Business settings');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Business settings saved.',
        ]);
    }
}
