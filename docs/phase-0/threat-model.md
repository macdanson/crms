# Architecture and threat model (Phase 0)

**Status: DRAFT — a starting point for the security architect.** Written for security reviewers and developers. It is the developers' own view, not an independent assessment.

## Architecture as built

A modular Laravel monolith (§28.1), using Inertia and Vue for the interface.

| Boundary                    | Main code                                                              |
| --------------------------- | ---------------------------------------------------------------------- |
| Authentication and identity | Fortify, `FortifyServiceProvider`, `SessionPolicy`, `AccountLifecycle` |
| Organisation and access     | `Controllers/Admin`, `Role`, `Permission`, `UserRoles`                 |
| Person records              | `PersonRecords`, `DuplicateDetector`, `PersonPolicy`                   |
| Case management             | `CaseRecords`, `RecordCorrections`, `CriminalCasePolicy`               |
| Court and outcomes          | `CourtRecords`                                                         |
| Search and matching         | `SearchController`, `DataProtection`                                   |
| Exceptional access          | `AccessRequests`, `AccessJustification`                                |
| Documents                   | `DocumentStore`, `ScanDocument`, `MalwareScanner`                      |
| Exports                     | `DataExports`, `GenerateExport`                                        |
| Reporting and analytics     | `ReportController`, `Statistics`                                       |
| Audit and monitoring        | `AuditLogger`, `SecurityAlerts`, `audit:verify`                        |

### The rule that matters most

Whether a user can see a record is decided in one place: the `visibleTo()` scopes on `CriminalCase` and `Person`. Policies, lists, search, reports, exports and dashboards all use them, so they cannot disagree. Anything not explicitly matched is denied. There is no `Gate::before` bypass, and no role skips a policy.

## Assets and who might want them

**Assets:** identity numbers; the link between a person and a case (an allegation is damaging in itself); narratives and witness details; court outcomes; audit records; keys.

**Actors:** an officer curious about someone they know; a corrupt insider paid for information; a compromised account; an administrator exceeding their authority; an outside attacker; malware on a station workstation; someone with a stolen database or backup.

## Threats and controls

| Threat                                      | Controls in the software                                                                                           | Still needed                                                         |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------- |
| Officer browses records beyond their duties | Scope rules; assignment-only access for investigators; every view audited; volume alerts                           | Audit-review routine; agreed baselines; disciplinary process         |
| Changing an ID in a URL (IDOR)              | Policy on every route; child records checked against their parent; answered as 404                                 | Independent testing                                                  |
| Learning whether a record exists            | Same answer for hidden and absent records in search, access requests and URLs                                      | Timing analysis under load                                           |
| Administrator reads records                 | Technical roles hold no operational permission; tested for each role                                               | Database access is outside the application: needs PAM and monitoring |
| Self-escalation                             | Nobody can change their own access; enforced in policy and service, including the CLI                              | Regular access recertification                                       |
| Two people colluding                        | Second officer for outcomes, access and exports; all recorded                                                      | Review of who approves whom                                          |
| Stolen session or left-open terminal        | Idle and absolute timeouts; sessions end on any privilege change; no-store pages                                   | Managed endpoints (§14.2)                                            |
| Credential stuffing and phishing            | Throttling; MFA; breached-password screening; new-device notices                                                   | Phishing-resistant MFA for privileged users; WAF                     |
| Theft of a database dump                    | Restricted fields encrypted; identifiers only as keyed tokens; data keys not in the database                       | Names and case links remain plain text; KMS; volume encryption       |
| Theft of keys with the database             | Keys are separate from the database and from `APP_KEY`                                                             | **Keys in environment variables are not adequate for production**    |
| Reversing identity tokens                   | Keyed HMAC with its own secret, not a plain hash                                                                   | Protect the key; assess equality and frequency leakage (§10)         |
| Tampering with audit records                | Model refuses changes; keyed seals; `audit:verify`; critical alert on failure                                      | **Off-server copy: without it, deleted events cannot be detected**   |
| Malicious upload                            | Type read from content; allow-list; malware scan before any download; private storage; sandboxed preview           | Live test against ClamAV                                             |
| Bulk data exfiltration                      | Exports off by default, limited fields, approval, one download, watermark, expiry, alerts; result limits on search | DLP and endpoint controls; printing is only logged                   |
| Spreadsheet formula injection in exports    | Cell values neutralised                                                                                            | —                                                                    |
| Sensitive data in URLs and logs             | Searches are POST; identifiers stripped from audit context; refused values not flashed                             | Web-server and WAF log configuration                                 |
| Sensitive data in email or SMS              | Notifications are generic text and a link, by construction                                                         | —                                                                    |
| Re-identification from statistics           | Aggregates only; suppression including a second cell                                                               | **Formal re-identification review before anything is shared**        |
| An allegation read as a conviction          | Police status, role and judicial outcome are separate; convictions come only from verified outcomes                | Training; wording review with legal                                  |
| Wrong merge of two people                   | Human review only; both records and what moved are kept                                                            | There is no un-merge screen: a wrong merge needs a manual fix        |
| Queued work outliving someone's access      | Jobs carry only IDs; exports re-check access when they run                                                         | —                                                                    |
| Loss of data or keys                        | Key rotation; `backup:verify-restore`                                                                              | **Backups, restore tests and key escrow are not in place**           |
| Denial of service                           | Rate limits on sensitive endpoints                                                                                 | WAF, DDoS protection, capacity testing                               |

## Abuse cases to give the penetration testers

1. As an investigator, reach a case or person you are not assigned to through every route: URL, search, link dialog, notification, document, print, export.
2. Replay a document URL, an export download and a reveal after losing access.
3. Approve your own request by any path. Collude using two accounts and see whether it shows in the audit log.
4. Use search responses or timing to confirm that a number or reference exists.
5. Upload a polyglot file, and try to get a preview to run script.
6. Change an audit row directly and confirm that `audit:verify` raises it.
7. Move or suspend a user mid-session and confirm that their access ends at once.
8. Work out a hidden count from the statistics tables.
