# Pilot and rollout readiness checklist (Phases 5 and 6)

**Status: nothing on this list has been completed.** Written for the project owner and the people who will sign off go-live. It adds nothing to the release gate in guide §25.3: it turns it into a list of what is still needed.

**The software being built and tested does not mean it is ready.** Do not load real police data until every item is signed off.

## Decisions and approvals

- [ ] Every row of the [decision register](../phase-0/decision-register.md) is approved by a named person.
- [ ] DPIA completed (input: [DPIA notes](../phase-0/dpia-input.md)).
- [ ] Data dictionary, access matrix and retention schedule approved.
- [ ] Registration and notification obligations confirmed with the Personal Data Protection Office.
- [ ] Hosting, data residency and network arrangements approved.

## Environment

- [ ] Production and staging exist, with separate credentials and keys.
- [ ] Keys are in the approved KMS or secret manager, with named custodians and a recovery procedure.
- [ ] `php artisan ops:preflight` passes in production.
- [ ] Database, Redis and file store are on private networks, and storage volumes are encrypted.
- [ ] TLS enforced; WAF and firewall configured; stations connect over an approved network.
- [ ] ClamAV is running and tested with a test file through a real upload.
- [ ] Tests pass on the production database engine (so far they have run only on SQLite).
- [ ] Queue worker and scheduler are running and monitored.

## Audit and monitoring

- [ ] Audit log is shipped to a separate, access-controlled platform.
- [ ] A test proves that removing an event from the database is detected from the off-server copy.
- [ ] Alert thresholds tuned against a baseline; someone is assigned to respond.
- [ ] Audit retention approved.

## Backup and recovery

- [ ] Encrypted backups running, with separate credentials and administrative boundary.
- [ ] RPO and RTO approved.
- [ ] Restore test completed and `backup:verify-restore` passes on the restored copy.
- [ ] Disaster recovery exercise completed.

## Security validation

- [ ] Threat model reviewed by the security architect.
- [ ] OWASP ASVS target agreed and assessed.
- [ ] Independent penetration test completed (see the [abuse cases](../phase-0/threat-model.md)).
- [ ] Critical and high findings resolved or formally risk-accepted.
- [ ] Load and performance testing against agreed targets.
- [ ] Incident response plan approved and exercised.

## Known gaps to close or accept

From [implementation status](../implementation-status.md). Each one needs a decision:

- [ ] Retention, disposal and retention-review workflow.
- [ ] Rights-request handling.
- [ ] Un-merge procedure for a wrong merge.
- [ ] Access recertification reports.
- [ ] Re-index command for the identifier lookup key.
- [ ] Phishing-resistant MFA for privileged users.
- [ ] Concurrent-session policy.

## People

- [ ] User acceptance testing completed with real officers and synthetic data.
- [ ] Wording reviewed with legal, especially around roles, status and outcomes.
- [ ] Users and administrators trained.
- [ ] Support contact set (`CRMS_SUPPORT_CONTACT`).
- [ ] Offence catalogue and role matrix loaded from approved sources.

## Go-live approvals (§30)

| Approval             | Name | Date |
| -------------------- | ---- | ---- |
| Legal                |      |      |
| DPO                  |      |      |
| Information security |      |      |
| Records management   |      |      |
| Operations           |      |      |
| Infrastructure       |      |      |

## Onboarding each station (pilot and Phase 6 rollout)

National rollout is a separate operational activity and should not be assumed to be part of the pilot (§29).

For each wave:

- [ ] Selected stations approved; capacity reviewed for the added load.
- [ ] Connectivity tested over the approved network; workstations meet §14.2.
- [ ] Application Administrator adds the district, station and departments.
- [ ] Commander confirms the staff list. Security Administrator invites each person individually: **no shared accounts**.
- [ ] Each person activates their account and enrols an authenticator.
- [ ] Roles granted, then checked with the commander.
- [ ] Training delivered.
- [ ] First-week review of alerts and access patterns.
- [ ] Rollout acceptance recorded before the next wave.
