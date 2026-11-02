<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Called by the browser while the officer is actively working on a page that
 * makes no other requests (e.g. a long form), or when they choose "Stay signed
 * in". The EnforceSessionTimeouts middleware records the activity.
 */
class SessionHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $absoluteEndsAt = (int) $request->session()->get('crms.authenticated_at', now()->getTimestamp())
            + (int) config('crms.auth.absolute_lifetime') * 60;

        return response()->json([
            'idle_timeout' => (int) config('crms.auth.idle_timeout') * 60,
            'absolute_remaining' => max(0, $absoluteEndsAt - now()->getTimestamp()),
        ]);
    }
}
