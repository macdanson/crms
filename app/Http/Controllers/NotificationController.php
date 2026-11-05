<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's own notifications. They hold only generic text and a link, and
 * the page behind the link makes its own authorisation check.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('notifications/Index', [
            'notifications' => $user->notifications()->paginate(25)->through(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'title' => (string) data_get($notification->data, 'title'),
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $record = $user->notifications()->findOrFail($notification);

        $record->markAsRead();

        return redirect((string) data_get($record->data, 'url', route('dashboard')));
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
