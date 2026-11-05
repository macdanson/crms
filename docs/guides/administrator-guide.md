# CRMS administrator guide

For Application Administrators, Security Administrators and Auditors. **This is a draft**, and the roles are provisional until the institution approves them. Infrastructure staff should read the [runbook](../operations/runbook.md).

## Administrators do not get access to records

Technical roles hold **no** access to people or cases (§7.2). This is intentional. If you need to see an operational record, you need a separate, approved role for it.

| Role                      | Can do                                                                 | Cannot do                        |
| ------------------------- | ---------------------------------------------------------------------- | -------------------------------- |
| Application Administrator | Organisation, offence catalogue, view users                            | Change users, roles or records   |
| Security Administrator    | Invite and manage users, roles, MFA resets, audit log, security alerts | Organisation settings or records |
| Auditor                   | Read the audit log, users and statistics                               | Change anything                  |

See Admin → Roles for the full matrix.

## Appointing the first Security Administrator

From the server, once the person has activated their account:

```bash
php artisan user:invite first.admin@example.org --name="Full Name" --by="your name"
php artisan user:grant-role first.admin@example.org security_administrator \
    --reason="Appointed by <authority>" --by="your name"
```

After that, use the screens. **Nobody can change their own access**, on screen or from the command line under their own email address.

## Setting up a new district or station (Application Administrator)

Admin → Organisation. A station's code and district cannot change once created, because case references and access rules rely on them. Check them before saving. Deactivate a station instead of removing it: it then accepts no new records.

## Managing users (Security Administrator)

| Task             | How                                             | What happens                                                       |
| ---------------- | ----------------------------------------------- | ------------------------------------------------------------------ |
| New user         | Users → Invite user                             | A single-use activation link is emailed                            |
| Give access      | User → place them at a station, then Grant role | **Signs them out**, so the change applies at once                  |
| Transfer         | User → change station                           | **Signs them out.** Their access moves with them                   |
| Remove a role    | User → Revoke, with a reason                    | Signs them out. The history is kept                                |
| Suspected misuse | User → Suspend, with a reason                   | Signs them out everywhere immediately                              |
| Someone has left | User → Deactivate (leaver)                      | Signs them out and revokes all roles                               |
| Lost phone       | User → Reset authenticator                      | **Confirm their identity first.** They enrol again at next sign-in |

Sensitive actions ask you to confirm your password. If you are returned to the page after confirming, submit again.

Authorised National/CID access must have an end date, so that it has to be renewed on purpose.

Accounts are never deleted, so that the audit trail stays attributable.

## Reviewing activity

**Audit log (Auditor, Security Administrator):** who did what, when, and to which record. Reading it is itself recorded. Filter by event prefix (such as `person.identifier`), outcome, record ID or date.

Events worth reviewing regularly:

| Event                                         | Why                                          |
| --------------------------------------------- | -------------------------------------------- |
| `person.identifier.revealed`                  | Full identity numbers being read             |
| `access.request.approved`                     | Exceptional access, and who approved it      |
| `auth.access.denied`                          | Someone trying to go beyond their access     |
| `role.granted`                                | Who gained access                            |
| `export.requested`                            | Data leaving the system                      |
| `audit.verification.completed` with a failure | **Possible tampering: treat as an incident** |

**Security alerts (Security Administrator):** suspicious patterns from the audit trail. Thresholds are starting values, not a baseline: expect to tune them with the security team (`crms.alerts.rules`). Record what you found on each alert.

## Access reviews

The system has no dedicated recertification screen yet. Until it does, use Users, filtered by station, to confirm with each commander who should hold which role, on a schedule the institution sets.
