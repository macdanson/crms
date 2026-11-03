<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends a newly provisioned user a single-use account activation link.
 *
 * The message deliberately contains no case or personal information.
 */
class AccountInvitation extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $url = route('activation.create', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $hours = (int) round(config('auth.passwords.invitations.expire') / 60);

        return (new MailMessage)
            ->subject('Activate your '.config('crms.product.short_name').' account')
            ->greeting('Welcome to '.config('crms.product.short_name'))
            ->line('An account has been created for you on the '.config('crms.product.full_name').'.')
            ->line('Use the button below to set your password. You will then be asked to set up an authenticator app on your phone.')
            ->action('Activate account', $url)
            ->line("This link can be used once and expires in {$hours} hours.")
            ->line('If you were not expecting this message, do not use the link. Report it to '.config('crms.product.support_contact').'.');
    }
}
