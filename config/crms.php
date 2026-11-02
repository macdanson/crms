<?php

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
        'organisation' => env('CRMS_ORGANISATION'),
        'tagline' => env('CRMS_TAGLINE', 'Evidence-led crime analysis'),
        'support_contact' => env('CRMS_SUPPORT_CONTACT', 'your system administrator'),
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
    | activation_expire:        Minutes an account activation link remains valid.
    | notify_new_device:        Email users when a new device signs in.
    |
    */

    'auth' => [
        'require_mfa' => (bool) env('CRMS_REQUIRE_MFA', true),
        'idle_timeout' => (int) env('CRMS_IDLE_TIMEOUT', 15),
        'idle_warning' => (int) env('CRMS_IDLE_WARNING', 60),
        'absolute_lifetime' => (int) env('CRMS_SESSION_ABSOLUTE_LIFETIME', 720),
        'activation_expire' => (int) env('CRMS_ACTIVATION_EXPIRE', 4320),
        'notify_new_device' => (bool) env('CRMS_NOTIFY_NEW_DEVICE', true),
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
        'csp' => (bool) env('CRMS_CSP_ENABLED', env('APP_ENV', 'production') !== 'local'),
    ],

];
