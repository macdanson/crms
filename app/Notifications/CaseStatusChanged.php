<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsGenericNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Says only that a case changed, not how (developer guide §20).
 */
class CaseStatusChanged extends Notification implements ShouldQueue
{
    use Queueable, SendsGenericNotice;

    public function __construct(public string $caseId) {}

    protected function title(): string
    {
        return 'A case assigned to you has been updated';
    }

    protected function link(): string
    {
        return route('cases.show', $this->caseId);
    }
}
