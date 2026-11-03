<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The password reset email. It is queued so the time taken to send it does
 * not tell whoever requested it that the account exists. The queued job
 * holds a working reset token, so its payload is encrypted with the app key
 * (the broker itself only stores a hash of the token).
 */
class ResetPasswordLink extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
}
