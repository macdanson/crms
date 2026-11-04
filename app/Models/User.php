<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Notifications\ResetPasswordLink;
use App\Services\SecondFactor;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
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
     * The account with exactly this email address, or null. Two things must
     * not reach an account: text a database collation only treats as equal
     * (invisible characters, accents), which would get around limits keyed
     * on what was typed, and bytes that are not text (or a NUL, which is valid
     * UTF-8), which Postgres and strict MySQL refuse with an error.
     */
    public static function findByEmail(string $email): ?self
    {
        if (! mb_check_encoding($email, 'UTF-8') || str_contains($email, "\0")) {
            return null;
        }

        $email = self::normaliseEmail($email);
        $user = self::query()->where('email', $email)->first();

        return $user !== null && self::normaliseEmail($user->email) === $email ? $user : null;
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
        return $this->hasEnabledTwoFactorAuthentication() ? count($this->recoveryCodes()) : 0;
    }

    /**
     * The unused recovery codes. Unlike Fortify's version, codes that cannot
     * be read (for example after an app key change) count as none, so no
     * page that shows them fails.
     *
     * @return list<string>
     */
    public function recoveryCodes(): array
    {
        return self::decodeRecoveryCodes($this->two_factor_recovery_codes);
    }

    /**
     * The authenticator secret, or null when there is none or it cannot be
     * read (for example after an app key change). A failure is reported.
     */
    public function twoFactorSecret(): ?string
    {
        if ($this->two_factor_secret === null) {
            return null;
        }

        try {
            return Fortify::currentEncrypter()->decrypt($this->two_factor_secret);
        } catch (DecryptException $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Whether there is an authenticator secret that cannot be read. Unlike
     * twoFactorSecret(), this is not reported: its callers handle the case
     * by replacing the secret.
     */
    public function hasUnreadableTwoFactorSecret(): bool
    {
        if ($this->two_factor_secret === null) {
            return false;
        }

        try {
            Fortify::currentEncrypter()->decrypt($this->two_factor_secret);

            return false;
        } catch (DecryptException) {
            return true;
        }
    }

    /**
     * Decode a stored recovery codes value as Fortify writes it (encrypted
     * JSON list). Anything unreadable is no codes; a failure to decrypt is
     * reported.
     *
     * @return list<string>
     */
    public static function decodeRecoveryCodes(?string $encrypted): array
    {
        if ($encrypted === null) {
            return [];
        }

        try {
            $codes = json_decode(Fortify::currentEncrypter()->decrypt($encrypted), true);
        } catch (DecryptException $exception) {
            report($exception);

            return [];
        }

        return is_array($codes) ? array_values(array_filter($codes, is_string(...))) : [];
    }
}
