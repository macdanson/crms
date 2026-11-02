<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts a user that their account was used from an unrecognised device.
 */
class NewDeviceSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $device,
        public ?string $ipAddress,
        public CarbonInterface $signedInAt,
    ) {}

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
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in to your '.config('crms.product.short_name').' account')
            ->line('Your account was just used to sign in from a device we have not seen before.')
            ->line('Device: '.$this->device)
            ->line('Network address: '.($this->ipAddress ?? 'unknown'))
            ->line('Time: '.$this->signedInAt->timezone(config('app.timezone'))->format('j M Y, H:i T'))
            ->action('Review account security', route('security.show'))
            ->line('If this was not you, change your password straight away and report it to '.config('crms.product.support_contact').'.');
    }
}
