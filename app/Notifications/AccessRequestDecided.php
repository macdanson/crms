<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsGenericNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Generic wording only, with a link to sign in (developer guide §20).
 */
class AccessRequestDecided extends Notification implements ShouldQueue
{
    use Queueable, SendsGenericNotice;

    public function __construct(public bool $approved) {}

    protected function title(): string
    {
        return $this->approved ? 'Your access request was approved' : 'Your access request was not approved';
    }

    protected function link(): string
    {
        return route('access-requests.index');
    }
}
