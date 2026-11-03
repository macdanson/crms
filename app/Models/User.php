<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Notifications\ResetPasswordLink;
use App\Services\SecondFactor;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * An individual user account. Shared or generic accounts are prohibited.
 *
 * @property int $id
 * @property string $name
 * @property string|null $staff_number
 * @property string|null $job_title
 * @property string $email
 * @property AccountStatus $status
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Carbon|null $password_changed_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property string|null $remember_token
 * @property string|null $session_stamp
 * @property string|null $session_stamp_holder
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'staff_number', 'job_title', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'session_stamp', 'session_stamp_holder', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Default attribute values for newly provisioned accounts.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * The one way an email address is compared and stored: trimmed and
     * lower-cased (multibyte-safe).
     */
    public static function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Use up a recovery code, atomically (SecondFactor::consumeRecoveryCode),
     * keeping Fortify's contract: a code that is not, or no longer, one of
     * the user's is refused. (The sign-in challenge uses the code up while
     * checking it instead; see TwoFactorAuthenticatedSessionController.)
     *
     * @param  string  $code
     *
     * @throws ValidationException
     */
    public function replaceRecoveryCode($code): void
    {
        if (! app(SecondFactor::class)->consumeRecoveryCode($this, (string) $code)) {
            throw ValidationException::withMessages([
                'recovery_code' => __('The provided two factor recovery code was invalid.'),
            ]);
        }
    }

    /**
     * Send the password reset link (queued; see ResetPasswordLink).
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }

    /**
     * Every account has a session stamp from the start, so signing in never
     * has to create one (see App\Services\SessionPolicy).
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->session_stamp ??= Str::random(64);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    public function isPending(): bool
    {
        return $this->status === AccountStatus::Pending;
    }

    /**
     * Initials used for the avatar in the application shell.
     */
    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /**
     * Unused recovery codes, or 0 when no authenticator is linked.
     */
    public function remainingRecoveryCodeCount(): int
    {
        return $this->hasEnabledTwoFactorAuthentication() ? app(SecondFactor::class)->remainingRecoveryCodes($this) : 0;
    }
}
