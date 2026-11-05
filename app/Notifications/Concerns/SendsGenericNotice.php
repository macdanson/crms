<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * The one shape of a workflow notification: a generic line and a link that
 * needs sign-in. Nothing sensitive can reach email or the notification table
 * because nothing else is sent (developer guide §20).
 */
trait SendsGenericNotice
{
    abstract protected function title(): string;

    abstract protected function link(): string;

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array{title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title(), 'url' => $this->link()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(config('crms.product.short_name').': '.$this->title())
            ->line($this->title().'.')
            ->action('Sign in to view', $this->link())
            ->line('Details are only available after you sign in.');
    }
}
