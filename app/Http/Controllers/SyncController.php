<?php

namespace App\Http\Controllers;

use App\Models\SyncStatus;
use App\Support\Sync\SyncCycle;
use Illuminate\Http\JsonResponse;

/**
 * The one way a sync cycle starts (M6).
 *
 * Three callers, one route: the POS page fires it after a successful checkout
 * (the opportunistic sync of §11.1), the sync indicator's "Sync now" button
 * fires it on demand, and M7's `online` event and backstop timer will fire the
 * same route rather than adding paths of their own. §11.3 is explicit that the
 * opportunistic sync "detects nothing — it just asks for a cycle at the moment
 * there is something to push".
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
        ]);
    }
}
