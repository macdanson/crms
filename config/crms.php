<?php

/*
 * A setting left blank (a "KEY=" line, or a compose variable that is not set)
 * or not understood takes its default. Otherwise a cast would quietly turn
 * these protections off.
 */
$flag = static fn (string $key, bool $default): bool => filled(env($key)) ? (filter_var(env($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default) : $default;
$number = static fn (string $key, int $default): int => filter_var(env($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false ? $default : (int) env($key);
$text = static fn (string $key, ?string $default): ?string => filled(env($key)) ? (string) env($key) : $default;

return [

    /*
    |--------------------------------------------------------------------------
    | Product Identity
    |--------------------------------------------------------------------------
    |
    | Names used across the interface, notifications and generated documents.
| The organisation name is optional; screens fall back to the product name.
    |
    */

    'product' => [
        'short_name' => 'CRMS',
        'full_name' => 'Crime Records Management System',
        'organisation' => $text('CRMS_ORGANISATION', null),
        'tagline' => $text('CRMS_TAGLINE', 'Evidence-led crime analysis'),
        'support_contact' => $text('CRMS_SUPPORT_CONTACT', 'your system administrator'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication & Session Policy
    |--------------------------------------------------------------------------
    |
    | These values implement section 6 and 16 of the developer guide. Final
    | values must be approved by the institution's security team.
    |
    | require_mfa:              Every user must enrol an authenticator app.
    | idle_timeout:             Minutes of inactivity before forced sign-out.
    | idle_warning:             Seconds of warning shown before an idle sign-out.
    | absolute_lifetime:        Maximum minutes a session may live, active or not.
    | absolute_warning:         Seconds of warning shown before that maximum is reached.
    | notify_new_device:        Email users when a new device signs in.
    | challenge_timeout:        Minutes allowed between the password and the
    |                           code steps of signing in (0 = no limit).
    |
    */

    'auth' => [
        'require_mfa' => $flag('CRMS_REQUIRE_MFA', true),
        'idle_timeout' => $number('CRMS_IDLE_TIMEOUT', 15),
        'idle_warning' => $number('CRMS_IDLE_WARNING', 60),
        'absolute_lifetime' => $number('CRMS_SESSION_ABSOLUTE_LIFETIME', 720),
        'absolute_warning' => (int) env('CRMS_SESSION_ABSOLUTE_WARNING', 300),
        'challenge_timeout' => $number('CRMS_CHALLENGE_TIMEOUT', 10),
        'notify_new_device' => $flag('CRMS_NOTIFY_NEW_DEVICE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser Security
    |--------------------------------------------------------------------------
    |
    | The Content Security Policy is disabled by default in local development
    | so the Vite dev server can inject scripts and styles.
    |
    */

    'security' => [
        'csp' => $flag('CRMS_CSP_ENABLED', env('APP_ENV', 'production') !== 'local'),
    ],

];
