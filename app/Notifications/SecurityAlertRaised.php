<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsGenericNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells security staff to look, without saying who or what (developer guide §20).
 */
class SecurityAlertRaised extends Notification implements ShouldQueue
{
    use Queueable, SendsGenericNotice;

    protected function title(): string
    {
        return 'A new security alert needs review';
    }

    protected function link(): string
    {
        return route('admin.alerts.index');
    }
}
