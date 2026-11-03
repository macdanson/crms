<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The password reset email. It is queued so the time taken to send it does
 * not tell whoever requested it that the account exists.
 */
class ResetPasswordLink extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
