<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a user that someone gave their correct password, then too many
 * wrong codes (authenticator or recovery codes), so code checks at sign-in
 * are paused.
 *
 * It states when the pause ends rather than how long it lasts, so it stays
 * true however long the queued email takes to arrive.
 */
class SignInCodesLocked extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  CarbonInterface|null  $pausedUntil  When code checks at sign-in resume.
     */
    public function __construct(public ?CarbonInterface $pausedUntil = null) {}

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
        $pause = $this->pausedUntil === null
            ? 'Signing in with a code was paused for a while.'
            : 'Signing in with a code is paused until '.$this->pausedUntil->timezone(config('app.timezone'))->format('j M Y, H:i T').'.';

        return (new MailMessage)
            ->subject('Someone may know your '.config('crms.product.short_name').' password')
            ->line('Someone signed in with your password, then entered too many wrong security codes.')
            ->line($pause)
            ->line('If this was not you, your password is known to someone else. Change it as soon as you can, and report it to '.config('crms.product.support_contact').'.');
    }
}
