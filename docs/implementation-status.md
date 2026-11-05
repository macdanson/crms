# Implementation status against the suggested development phases

**Reference:** [Developer guide §29](crime_case_records_management_developer_guide.md)
**Status:** Development baseline on synthetic data. **Not approved for real police data or production use.**

"Built" means implemented and covered by automated tests on synthetic data. It does not mean a phase's exit criteria are met: most of them need decisions and sign-off that only the institution can give.

## Summary

| Phase                                   | Software                                                   | What remains                                           | Exit criteria met? |
| --------------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------ | ------------------ |
| 0 — Discovery and approvals             | Draft inputs in [`phase-0/`](phase-0/decision-register.md) | Every approval. Nothing in the drafts is agreed        | **No**             |
| 1 — Platform foundation                 | Built                                                      | Hosting, environments, secret manager, audit platform  | Partly             |
| 2 — Core records and case management    | Built                                                      | Stakeholder walk-through of the synthetic scenarios    | Pending review     |
| 3 — Search, documents and outcomes      | Built                                                      | Independent security testing                           | Pending testing    |
| 4 — Reporting and operational readiness | Built, except backups (see below)                          | Backups and restore tests, SIEM, requirements approval | **No**             |
| 5 — Security validation and pilot       | Readiness material only                                    | Penetration test, UAT, training, sign-offs, pilot      | **No**             |
| 6 — Progressive rollout                 | Onboarding checklist only                                  | All of it. A separate operational activity             | **No**             |

## Phase 1 — Platform foundation

| Item                       | Status  | Where                                                                                                    |
| -------------------------- | ------- | -------------------------------------------------------------------------------------------------------- |
| Repository and CI/CD       | Partial | Tests, lint, static analysis and dependency audits run in CI. Deployment pipeline depends on hosting     |
| Environments and secrets   | Partial | Separate keys per environment, `security:generate-keys`, `ops:preflight`. Secret manager/KMS not chosen  |
| Organisation structure     | Built   | Districts, stations, departments; Admin → Organisation                                                   |
| Authentication and MFA     | Built   | Fortify with TOTP and recovery codes (delivered earlier on this branch). Passkeys not built              |
| User lifecycle             | Built   | Invite, place, suspend, deactivate, restore; Admin → Users                                               |
| RBAC/ABAC foundations      | Built   | `App\Enums\Role`, `Permission`, `visibleTo()` scopes, policies. The matrix is provisional                |
| Audit pipeline             | Partial | Events, seals, `audit:verify` and a log channel to ship. **The separate audit platform is not in place** |
| Secure shell and hardening | Built   | Security headers, CSP, session controls, idle and absolute timeouts                                      |

## Phase 2 — Core records and case management

Built: person profiles, identity documents, contacts, address history, cases, person-to-case roles, assignments, workflow, offence catalogue, correction workflow and duplicate review with merge history.

## Phase 3 — Search, documents and outcomes

Built: blind-index and name search, purpose capture, exceptional access with approval, encrypted private documents with malware-scan gating, court proceedings and verified outcomes, controlled reveal, and export and print controls.

Exports are **off by default** (`CRMS_EXPORTS_ENABLED`).

## Phase 4 — Reporting and operational readiness

Built: operational reports, de-identified statistics with small-count suppression, generic notifications, security alerts, restore validation (`backup:verify-restore`), and user and administrator guides.

**Backups are deliberately not implemented in the application.** The guide requires separate backup credentials and an administrative boundary (§21.1), which an application-level job running with the application's own credentials would break. They belong with the hosting decision. See the [runbook](operations/runbook.md). This phase's exit criterion that "restore tests succeed" is therefore **not met**.

## Known gaps

These are deliberate limits of the current build, listed so nobody assumes otherwise.

| Gap                                                            | Why                                                       | Guide     |
| -------------------------------------------------------------- | --------------------------------------------------------- | --------- |
| Audit events are not yet held outside the application database | Needs the audit platform; a log channel is ready to ship  | §12.3     |
| Sealing detects altered events, not deleted ones               | Only an off-server copy can show a removal                | §12.3     |
| Keys are read from the environment, not a KMS/HSM              | Key management must be approved                           | §9.3      |
| No automated retention, disposal or retention-review queue     | The schedule needs formal approval first                  | §13.2     |
| No record deletion anywhere                                    | Intentional until a lawful disposal workflow is approved  | §13       |
| No break-glass access                                          | Only if the institution requires it                       | §8        |
| No identity-service or court integrations                      | Separate work packages needing legal basis and agreements | §20       |
| Demographic aggregates are not reported                        | Lawfulness and necessity must be decided first            | §27.8     |
| Passkeys for privileged users are not built                    | Candidate hardening; TOTP is in place                     | §6.1, §23 |
| Concurrent-session policy is not enforced                      | Policy decision needed                                    | §16       |
| Only local document storage is configured                      | The approved object or file store is a hosting decision   | §19       |
| Exports cover the case list only                               | Other reports need approved formats and fields            | §17.1     |

## How this was verified

- Automated tests, including the ten essential authorisation cases of §25.2 in `tests/Feature/Security/AuthorisationAcceptanceTest.php`. All run on synthetic data and SQLite.
- Static analysis (PHPStan level 7), Pint, `vue-tsc`, lint and format checks, a production asset build, and `composer audit` and `npm audit`.

**Not verified:** MySQL or PostgreSQL (the tests run only on SQLite), a live ClamAV daemon, and a manual walk through the screens in a browser. None of this is independent security testing.
