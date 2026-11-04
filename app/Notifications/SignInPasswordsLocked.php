<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a user that too many wrong passwords were given for their account,
 * from any address, so sign-in is paused. The sign-in form does not say so
 * (it would reveal that the account exists), so this is how the user learns.
 *
 * It states when the pause ends rather than how long it lasts, so it stays
 * true however long the queued email takes to arrive.
 */
class SignInPasswordsLocked extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  CarbonInterface  $pausedUntil  When sign-in resumes.
     */
    public function __construct(public CarbonInterface $pausedUntil) {}

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
        return (new MailMessage)
            ->subject('Sign-in to your '.config('crms.product.short_name').' account is paused')
            ->line('Too many wrong passwords were given for your account.')
            ->line('Signing in is paused until '.$this->pausedUntil->timezone(config('app.timezone'))->format('j M Y, H:i T').', even with the right password. Resetting your password ends the pause.')
            ->line('If this was not you, someone may be trying to get into your account. Report it to '.config('crms.product.support_contact').'.');
    }
}
