<?php

namespace App\Notifications;

use App\Services\AccountAttemptLimiter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a user that someone gave their correct password, then too many
 * wrong authenticator codes, so code checks at sign-in are paused.
 */
class SignInCodesLocked extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) ceil(AccountAttemptLimiter::DECAY_SECONDS / 60);

        return (new MailMessage)
            ->subject('Someone may know your '.config('crms.product.short_name').' password')
            ->line('Someone signed in with your password, then entered too many wrong authenticator codes.')
            ->line("Signing in with a code is paused for {$minutes} minutes.")
            ->line('If this was not you, your password is known to someone else. Change it as soon as you can, and report it to '.config('crms.product.support_contact').'.');
    }
}
