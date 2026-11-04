<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Called by the browser while the user is actively working on a page that
 * makes no other requests (e.g. a long form), or when they choose "Stay signed
 * in". The EnforceSessionTimeouts middleware records the activity.
 */
class SessionHeartbeatController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
