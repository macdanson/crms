# Decision register (Phase 0)

**Status: DRAFT — nothing here has been approved.** Written for the project owner, legal team, DPO, security and records-management stakeholders.

The guide says the development team must not decide these questions (§2.2). So that development could continue on synthetic data, the software has a placeholder for each one. A placeholder is **not a proposal that it is right**. It exists to make the question concrete and is easy to change.

Production must not begin until every row has a named approver and date.

| #   | Decision needed                                       | Placeholder in the software                                         | Where it changes                                      | Owner                       | Approved (who, when) |
| --- | ----------------------------------------------------- | ------------------------------------------------------------------- | ----------------------------------------------------- | --------------------------- | -------------------- |
| 1   | Lawful basis and authorised purposes                  | Five example purposes                                               | `App\Enums\AccessPurpose`                             | Legal, DPO                  |                      |
| 2   | Who may access each class of record                   | Provisional role matrix ([access matrix](access-matrix.md))         | `App\Enums\Role`, `visibleTo()` scopes                | Project owner, legal        |                      |
| 3   | Retention periods                                     | None. Nothing is ever deleted automatically                         | To be built once approved                             | Records management          |                      |
| 4   | Correction, restriction, archival and lawful deletion | Reasoned corrections; lifecycle states; **no deletion path**        | `App\Enums\RecordLifecycle`, `RecordCorrections`      | Legal, records              |                      |
| 5   | Disclosure to courts, agencies and third parties      | None. No disclosure feature exists                                  | To be built once approved                             | Legal                       |                      |
| 6   | Cross-district and national search authority          | National role must have an end date; others limited to their scope  | `Role::requiresExpiry()`, `visibleTo()`               | Project owner, legal        |                      |
| 7   | Identity verification services                        | Manual status with a named source; no integration                   | `PersonRecords::setVerification`                      | Legal, project owner        |                      |
| 8   | Hosting, data residency, cross-border processing      | None. Runs anywhere Laravel runs                                    | Deployment                                            | Infrastructure, legal       |                      |
| 9   | Incident escalation and regulatory notification       | Evidence available; no process in software                          | [Runbook](../operations/runbook.md)                   | Institution, DPO            |                      |
| 10  | Whether photographs or biometrics are necessary       | **Not collected.** Photographs are not built                        | —                                                     | Legal, DPO                  |                      |
| 11  | Identity documents collected                          | National ID, passport, driving permit                               | `App\Enums\IdentityType`                              | Legal, DPO                  |                      |
| 12  | Offence catalogue                                     | Empty in production; demo values in local seed data only            | Admin → Offence catalogue                             | Police, legal               |                      |
| 13  | Person-to-case roles                                  | The six example roles in §4.2                                       | `App\Enums\CasePersonRole`                            | Police, legal               |                      |
| 14  | Case workflow and statuses                            | Open → Under investigation → Submitted → Closed administratively    | `App\Enums\CaseStatus`                                | Police operations           |                      |
| 15  | Court outcome catalogue and definitions               | The examples in §4.3                                                | `App\Enums\CourtOutcomeType`                          | Police, legal               |                      |
| 16  | Exceptional access: rules and duration                | Approved by a commander or supervisor who can see the case; 8 hours | `CRMS_EXCEPTIONAL_ACCESS_HOURS`                       | Legal, security             |                      |
| 17  | Break-glass emergency access                          | **Not built**                                                       | —                                                     | Institution                 |                      |
| 18  | Which actions need a purpose                          | Identity and name search, reveal, export                            | `SearchController`, `RevealController`                | Legal, DPO                  |                      |
| 19  | Export use, threshold and expiry                      | Off; 50 rows needs approval; 24 hours; case fields only             | `crms.exports`                                        | Security, legal             |                      |
| 20  | Statistics suppression                                | Counts below 5 hidden                                               | `crms.statistics`                                     | DPO, statistician           |                      |
| 21  | Security alert thresholds                             | Starting values, not based on a baseline                            | `crms.alerts.rules`                                   | Security                    |                      |
| 22  | Session limits                                        | 15 minutes idle; 12 hours absolute                                  | `CRMS_IDLE_TIMEOUT`, `CRMS_SESSION_ABSOLUTE_LIFETIME` | Security                    |                      |
| 23  | Password policy                                       | 12 or more mixed characters; breached-password check in production  | `AppServiceProvider`                                  | Security                    |                      |
| 24  | Key custody, rotation and recovery                    | Environment variables; versioned keys; rotation command             | [Runbook](../operations/runbook.md)                   | Security, infrastructure    |                      |
| 25  | RPO and RTO                                           | None                                                                | Hosting                                               | Institution, infrastructure |                      |

## Scope questions to settle first (§32)

These change the estimate and the design, so they should be answered early:

1. Is the first release a pilot or a national deployment?
2. How many pilot stations, districts and users?
3. Will historical records be migrated? In what state are they?
4. Is identity verification manual or through an authorised service?
5. Evidence management, or case-document attachments only? (Attachments only are built.)
6. Is court data entered manually or received through an authorised integration? (Manual entry is built.)
7. Is offline operation required? (Not built, and the guide advises against it.)
8. Who is responsible for hosting, network, endpoints and training?
