<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsGenericNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Says only that something was assigned. The link needs sign-in and a fresh
 * authorisation check; no case detail travels by email (developer guide §20).
 */
class CaseAssigned extends Notification implements ShouldQueue
{
    use Queueable, SendsGenericNotice;

    public function __construct(public string $caseId) {}

    protected function title(): string
    {
        return 'A case has been assigned to you';
    }

    protected function link(): string
    {
        return route('cases.show', $this->caseId);
    }
}
