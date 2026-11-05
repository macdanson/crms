# Operations runbook

Written for the DevOps and infrastructure team. Hosting has not been decided (§2.2), so this describes what the application needs, not a finished environment.

## Before any environment goes live

```bash
php artisan ops:preflight
```

It fails on insecure settings it can see, such as debug mode, a missing key or a disabled malware scan. A pass is **not** the release gate: see the [readiness checklist](../phase-5/readiness-checklist.md).

## What must be running

| Process       | Command                                | Notes                                                              |
| ------------- | -------------------------------------- | ------------------------------------------------------------------ |
| Web           | PHP-FPM behind Nginx                   | Set `TRUSTED_PROXIES`, and `upload_max_filesize` of at least 20 MB |
| Queue worker  | `php artisan queue:work`               | Document scans, exports and notifications depend on it             |
| Scheduler     | `php artisan schedule:run` each minute | See below                                                          |
| ClamAV daemon | `clamd` on a private network           | It receives decrypted documents                                    |

Scheduled by the application:

| Task                 | When        | If it stops                                                  |
| -------------------- | ----------- | ------------------------------------------------------------ |
| `alerts:scan`        | Every 5 min | No security alerts are raised                                |
| `exports:prune`      | Hourly      | Uncollected export files stay on disk past their expiry      |
| `statistics:refresh` | 01:00 daily | Statistics go stale; the page shows when they were generated |
| `audit:verify`       | 02:00 daily | Altered audit events go unnoticed                            |

**Monitor these.** A failed `audit:verify` is a security incident until shown otherwise.

## Keys

Generate per environment; never share them between environments:

```bash
php artisan security:generate-keys
```

| Key                          | Protects                            | If lost                                    | If stolen                                            |
| ---------------------------- | ----------------------------------- | ------------------------------------------ | ---------------------------------------------------- |
| `CRMS_DATA_KEYS`             | Encrypted fields and documents      | **That data is permanently unreadable**    | Rotate, and treat encrypted data as exposed          |
| `CRMS_IDENTIFIER_LOOKUP_KEY` | Identity search tokens              | Identity search stops working              | Tokens could be tested offline; a re-index is needed |
| `CRMS_AUDIT_SEAL_KEY`        | Tamper evidence for audit events    | Older events can no longer be verified     | Seals could be forged; rely on the off-server copy   |
| `APP_KEY`                    | Sessions, MFA secrets, keyed hashes | Everyone must re-enrol their authenticator | Rotate and end all sessions                          |

Environment variables are acceptable for development only. Production needs the approved KMS or secret manager (§9.3), plus agreed custodians, escrow and an incident procedure.

### Rotating data keys

1. Add the new key, keeping the old: `CRMS_DATA_KEYS=1:<old>,2:<new>`.
2. Reload the application and queue workers.
3. Run `php artisan security:rotate-data-keys --by=<your name>`. It can safely be run again.
4. Run `php artisan backup:verify-restore`.
5. Take a new backup. **Keep the old key for as long as any backup encrypted with it is retained.**
6. Remove the old key.

There is no re-index command for `CRMS_IDENTIFIER_LOOKUP_KEY` yet. Plan one before relying on rotating that key.

## Backup and restore

The application does not take its own backups. The guide requires separate credentials and an administrative boundary (§21.1), so an application job would be the wrong tool. Backups must be set up with the hosting decision.

Back up, encrypted, with immutable or offline copies where feasible:

1. The database.
2. The document disk (`storage/app/private/documents`).
3. The keys, **separately from the data**, under the key-custody procedure.

A backup is not proven until it has been restored. For each restore test:

1. Restore into an isolated environment with no route to production and no outgoing mail.
2. Load the keys for that backup.
3. Run:

    ```bash
    php artisan migrate:status
    php artisan backup:verify-restore --sample=500
    ```

    This checks for unrun migrations, audit seals, decryption of a sample of each protected field, and that sampled documents are present and unchanged.

4. Record the date, backup used, time taken and result. Compare the time taken with the approved RTO.
5. Destroy the restored copy under the approved procedure.

RPO and RTO must be agreed with the institution (§21.2). Do not promise values before then.

## Shipping the audit trail

Audit events go to the database and to the `audit` log channel (`storage/logs/audit-*.log`), one JSON-like line per event including its seal. Ship these to the separate audit platform or SIEM and keep them append-only (§12.3).

Until that is done, someone with database and server access can remove events undetected. `audit:verify` only finds changes to events still present.

The local file keeps 90 days by default (`LOG_AUDIT_DAYS`). That value has not been approved.

## Responding to a suspected incident

The institution owns the plan and all notification decisions (§26). Developers provide evidence.

| To do this                   | Do this                                                                                  |
| ---------------------------- | ---------------------------------------------------------------------------------------- |
| Cut off an account now       | `php artisan user:suspend <email> --reason=... --by=...` (ends its sessions immediately) |
| Cut off someone who has left | Admin → Users → Deactivate (also revokes their roles)                                    |
| See what an account did      | Admin → Audit log, filtered by user; or query `audit_events` by `user_id`                |
| See who touched a record     | Admin → Audit log, filtered by record ID                                                 |
| Check for tampering          | `php artisan audit:verify`, and compare with the off-server copy                         |
| Respond to a stolen key      | See the keys table above, and rotate                                                     |

Preserve logs before changing anything.

## Local development

Use synthetic data only (§24). The seeder refuses to run outside local and testing environments.

```bash
composer setup
php artisan security:generate-keys   # add the output to .env
php artisan migrate:fresh --seed
composer dev
```

Demo users are `records`, `investigator`, `commander`, `supervisor`, `national`, `quality`, `auditor`, `appadmin`, `security` and `infrastructure`, all `@crms.test`. Each must enrol an authenticator at first sign-in.
