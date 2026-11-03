<?php

namespace Tests;

use App\Models\User;
use App\Services\SessionPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sign in as the user, stamping the session as a real sign-in does.
     * EnsureSessionIsCurrent signs out any session without a stamp.
     *
     * @param  string|null  $guard
     */
    public function be(Authenticatable $user, $guard = null): static
    {
        parent::be($user, $guard);

        if ($user instanceof User) {
            $this->withSession([SessionPolicy::STAMP => app(SessionPolicy::class)->stampFor($user)]);
        }

        return $this;
    }
}
