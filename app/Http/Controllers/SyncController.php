<?php

namespace App\Http\Controllers;

use App\Models\SyncStatus;
use App\Support\Sync\SyncCycle;
use Illuminate\Http\JsonResponse;

/**
 * The one way a sync cycle starts (M6).
 *
 * Every trigger comes here and there are no others: the outbox listener (a row
 * landed in the backlog), the webview `online` event, the five-minute backstop,
 * and the indicator's "Sync now" button. §11.3 is explicit that these are
 * triggers rather than mechanisms — the opportunistic sync "detects nothing, it
 * just asks for a cycle at the moment there is something to push" — which is why
 * they share one route and one {@see SyncCycle} rather than growing paths of
 * their own.
 *
 * ## Why it answers JSON and never fails
 *
 * The post-checkout call happens with a completed sale on screen. An Inertia
 * redirect would redraw that screen; a 500 would surface as an error over a sale
 * that is already committed, printed and paid for. So this is a plain JSON
 * endpoint, and {@see SyncCycle::run()} has no throwing path — every outcome,
 * including a revoked credential, comes back as a state the indicator can show.
 */
class SyncController extends Controller
{
    public function __invoke(SyncCycle $cycle): JsonResponse
    {
        $result = $cycle->run();

        return response()->json([
            'sync' => SyncStatus::snapshot(),
            'pushed' => $result['pushed'],
            'pulled' => $result['pulled'],
        ]);
    }
}
