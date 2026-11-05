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
        'absolute_warning' => $number('CRMS_SESSION_ABSOLUTE_WARNING', 300),
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

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    |
    | seal_key: A dedicated secret that makes changed audit events detectable
    |           (`php artisan audit:verify`). Keep it out of the database's
    |           security boundary; with no key, events are not sealed.
    |
    */

    'audit' => [
        'seal_key' => $text('CRMS_AUDIT_SEAL_KEY', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Protection
    |--------------------------------------------------------------------------
    |
    | Developer guide §9 to §11. Generate with `php artisan security:generate-keys`.
    | In production these must come from the approved KMS or secret manager.
    |
    | keys:       "<version>:<base64 key>" pairs, comma-separated. The highest
    |             version encrypts; older ones stay to read existing values.
    | lookup_key: A separate secret for the identifier blind index.
    |
    */

    'data_protection' => [
        'keys' => $text('CRMS_DATA_KEYS', null),
        'lookup_key' => $text('CRMS_IDENTIFIER_LOOKUP_KEY', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exceptional Access
    |--------------------------------------------------------------------------
    |
    | exceptional_hours: How long approved access to a case outside the user's
    |                    normal scope lasts (developer guide §8). There is no
    |                    "break-glass" route: build one only if required.
    |
    */

    'access' => [
        'exceptional_hours' => $number('CRMS_EXCEPTIONAL_ACCESS_HOURS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | Developer guide §19. Files can only be downloaded after a clean scan.
    |
    | scanner:         "clamd" (a ClamAV daemon on a private network) or "none".
    | allow_unscanned: Whether files can be downloaded with the scanner set to
    |                  "none". For local development with synthetic files only.
    | max_kilobytes:   The largest upload.
    | allowed_types:   The MIME types accepted, read from each file's content.
    |
    */

    'documents' => [
        'disk' => 'documents',
        'scanner' => $text('CRMS_DOCUMENT_SCANNER', 'clamd'),
        'clamd_host' => $text('CRMS_CLAMD_HOST', '127.0.0.1'),
        'clamd_port' => $number('CRMS_CLAMD_PORT', 3310),
        'allow_unscanned' => $flag('CRMS_ALLOW_UNSCANNED_DOCUMENTS', false),
        'max_kilobytes' => $number('CRMS_DOCUMENT_MAX_KB', 20480),
        'allowed_types' => [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'text/plain' => 'txt',
        ],
        'previewable_types' => ['application/pdf', 'image/jpeg', 'image/png'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    |
    | Developer guide §17. Bulk export is off until switched on.
    |
    | approval_threshold: Exports with more rows than this need another
    |                     officer's approval. Must be set by the institution.
    | expiry_hours:       When a prepared file that was not collected is destroyed.
    | fields:             The only fields any export can contain. None of them
    |                     identifies a person.
    |
    */

    'exports' => [
        'enabled' => $flag('CRMS_EXPORTS_ENABLED', false),
        'approval_threshold' => $number('CRMS_EXPORT_APPROVAL_THRESHOLD', 50),
        'expiry_hours' => $number('CRMS_EXPORT_EXPIRY_HOURS', 24),
        'fields' => ['reference', 'station', 'district', 'category', 'status', 'incident_date'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    |
    | suppression_threshold: A count below this is never shown in de-identified
    |                        statistics (developer guide §18.2). Provisional:
    |                        it needs a re-identification risk review.
    |
    */

    'statistics' => [
        'suppression_threshold' => $number('CRMS_STATISTICS_SUPPRESSION_THRESHOLD', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Alerts
    |--------------------------------------------------------------------------
    |
    | Developer guide §12.4. An alert is raised when one user's matching events
    | reach the threshold within a window. These starting values are
    | PROVISIONAL: tune them against a baseline with the security team.
    |
    */

    'alerts' => [
        'rules' => [
            'identity_searches' => ['label' => 'Unusual volume of identity number searches', 'events' => ['search.person.identity'], 'threshold' => 30, 'window_minutes' => 60, 'severity' => 'medium'],
            'name_searches' => ['label' => 'Unusual volume of searches', 'events' => ['search.person.name', 'search.case'], 'threshold' => 100, 'window_minutes' => 60, 'severity' => 'medium'],
            'person_views' => ['label' => 'Large number of person profile views', 'events' => ['person.viewed'], 'threshold' => 100, 'window_minutes' => 60, 'severity' => 'medium'],
            'identifier_reveals' => ['label' => 'Repeated full identifier reveals', 'events' => ['person.identifier.revealed'], 'threshold' => 10, 'window_minutes' => 60, 'severity' => 'high'],
            'denied_access' => ['label' => 'Repeated refused access attempts', 'events' => ['auth.access.denied'], 'threshold' => 20, 'window_minutes' => 60, 'severity' => 'high'],
            'document_downloads' => ['label' => 'Unusual volume of document downloads', 'events' => ['document.downloaded', 'document.previewed'], 'threshold' => 50, 'window_minutes' => 60, 'severity' => 'medium'],
            'exports' => ['label' => 'Unusual number of exports', 'events' => ['export.requested'], 'threshold' => 5, 'window_minutes' => 1440, 'severity' => 'high'],
            'printing' => ['label' => 'Unusual printing', 'events' => ['case.printed'], 'threshold' => 30, 'window_minutes' => 60, 'severity' => 'medium'],
            'privilege_changes' => ['label' => 'Role granted (privilege change)', 'events' => ['role.granted'], 'threshold' => 1, 'window_minutes' => 60, 'severity' => 'low'],
            'audit_integrity' => ['label' => 'Audit trail verification failed', 'events' => ['audit.verification.completed'], 'outcome' => 'failure', 'threshold' => 1, 'window_minutes' => 60, 'severity' => 'critical'],
        ],
    ],

];
