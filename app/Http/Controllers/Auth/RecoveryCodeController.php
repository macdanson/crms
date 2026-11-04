<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController as FortifyRecoveryCodeController;

/**
 * Fortify's recovery codes endpoint, reading the codes through
 * User::recoveryCodes(), which treats codes that cannot be read (for example
 * after an app key change) as none instead of failing.
 */
class RecoveryCodeController extends FortifyRecoveryCodeController
{
    /**
     * Get the user's recovery codes.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($user instanceof User && $user->two_factor_secret !== null ? $user->recoveryCodes() : []);
    }
}
