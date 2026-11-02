<?php

namespace App\Services;

use App\Enums\AuditOutcome;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the current session because a policy requires it (idle timeout,
 * maximum session length, account no longer active), records why, and
 * tells the browser.
 */
class SessionTermination
{
    /**
     * Request attribute marking a sign-out the user did not ask for, so it is
     * not also recorded as an ordinary "signed out" event.
     */
    public const ATTRIBUTE = 'crms.session_terminated';

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function end(
        Request $request,
        User $user,
        string $event,
        AuditOutcome $outcome,
        array $context,
        string $message,
    ): Response {
        $this->audit->record($event, $outcome, $user, $context);

        $request->attributes->set(self::ATTRIBUTE, true);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->wantsJsonResponse()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('status', $message);
    }

    /**
     * Whether the sign-out in progress was started by end().
     */
    public static function isTerminating(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) === true;
    }
}
