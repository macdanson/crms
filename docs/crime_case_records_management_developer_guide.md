# Crime / Case Records Management System

## Developer Implementation & Security Guide

**Document type:** Technical implementation guide  
**Audience:** Software developers, solution architects, DevOps engineers, QA/security testers, and project stakeholders  
**Jurisdictional context:** Uganda  
**Status:** Working implementation baseline — subject to requirements validation and formal institutional approval

---

## 1. Purpose and objectives

This guide translates the initial 26 security and architecture recommendations into an actionable development baseline for a centralised Crime / Case Records Management System (CRMS).

The platform is intended to support authorised police personnel at participating police stations and districts to:

- Register and maintain person records.
- Create and manage crime/case records.
- Link people to cases using clearly defined roles.
- Record case progress and verified judicial outcomes.
- Search records across authorised organisational boundaries.
- Produce operational reports and privacy-preserving crime statistics.
- Maintain reliable accountability records for access and changes.

The system processes highly sensitive personal information. Security, privacy, accuracy, lawful use and accountability are core product requirements, not optional enhancements.

### 1.1 Guiding principles

1. **Lawful purpose:** Every processing activity must have an approved and documented purpose.
2. **Least privilege:** Users receive only the access required for their duties.
3. **Need to know:** A user’s organisational role alone does not automatically justify access to every record.
4. **Privacy by design:** Collect, display, retain and disclose only what is necessary.
5. **Accuracy and fairness:** An allegation, arrest or charge must never be represented as a conviction.
6. **Accountability:** Sensitive access and changes must be attributable to an individual user.
7. **Defence in depth:** Apply controls at application, database, infrastructure, network and operational levels.
8. **Secure defaults:** Deny access unless it is explicitly authorised.
9. **Separation of duties:** Technical administration must not automatically grant access to operational records.
10. **National scalability:** Build a centrally managed architecture that can be expanded through controlled rollout.

---

## 2. Governance, legal review and project prerequisites

Development may proceed using synthetic data and approved requirements. Production processing must not begin until the responsible institution has approved the legal, operational and security arrangements.

### 2.1 Required institutional stakeholders

Identify and involve:

- Police project owner and operational representatives.
- Police legal team.
- Designated Data Protection Officer (DPO), where applicable.
- Information-security personnel / security operations representatives.
- Records-management and archives representatives.
- District and station representatives.
- Infrastructure / hosting authority.
- Relevant justice-sector stakeholders for any proposed integrations.

### 2.2 Decisions requiring institutional approval

The development team must not invent or independently decide:

- The lawful basis and authorised purposes for each processing activity.
- Which personnel and institutions may access each class of record.
- Retention periods for each record category.
- Rules for correction, restriction, archival and lawful deletion.
- Disclosure to courts, other agencies or third parties.
- Cross-district and national search authority.
- Whether and how identity verification services may be integrated.
- Hosting location, data residency and cross-border processing arrangements.
- Incident escalation and regulatory notification responsibilities.
- Whether photographs, biometrics or other especially sensitive data are necessary.

### 2.3 Privacy impact assessment

Complete a Data Protection Impact Assessment (DPIA) or equivalent privacy impact assessment before production deployment. It should document:

- Data categories and data subjects.
- Purpose and lawful basis for each processing activity.
- Data flows and recipients.
- Necessity and proportionality of collection.
- Access roles and disclosure rules.
- Retention and disposal requirements.
- Risks to individuals and the institution.
- Technical and organisational safeguards.
- Data-subject rights and applicable lawful limitations.
- Breach response and accountability arrangements.

Confirm applicable obligations under Uganda’s Data Protection and Privacy Act, 2019 and Data Protection and Privacy Regulations, 2021 with the institution’s legal/DPO team. Confirm current registration, notification, hosting and cross-border requirements with the relevant authorities. This guide is not a substitute for legal advice.

---

## 3. Data classification and handling rules

Classify data before implementing screens, APIs, reports or exports.

| Classification                 | Examples                                                                           | Minimum handling                                                                   |
| ------------------------------ | ---------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Restricted personal data       | NIN, passport, driving permit, phone, address, date of birth                       | Application-level encryption where appropriate, masking, tightly controlled access |
| Highly restricted case data    | Allegations, suspect links, investigation details, court outcomes, conviction data | Explicit policy checks, access logging, purpose limitation, strict export controls |
| Confidential operational data  | Officer notes, assignments, internal case workflow                                 | Role and case-based access; audit material changes                                 |
| Internal administrative data   | Station configuration, user roles, non-sensitive settings                          | Authenticated access and administrative audit                                      |
| De-identified statistical data | Aggregated crime counts and trends                                                 | Re-identification risk review; suppress small groups where appropriate             |

### 3.1 Data minimisation

For every field, record:

- Why it is required.
- Which workflow uses it.
- Whether it is mandatory or optional.
- Who may view or update it.
- Whether it requires encryption or masking.
- How long it may be retained.
- Whether it is included in analytics or exports.

Do not collect biometric data, extra identity documents or unrelated personal information merely because the system can store them.

---

## 4. Domain model: people, cases and judicial outcomes

Do not model a person as a permanent “criminal” or “suspect” record. A person may participate in multiple cases and may have different roles in each case.

### 4.1 Recommended logical entities

The following is a starting domain model, to be refined during requirements analysis:

- `persons` — core person profile and internal UUID.
- `person_identities` — identity type, encrypted identifier, keyed lookup token, issuing country/authority and verification metadata.
- `person_contacts` — contact details and validity dates.
- `person_addresses` — address details, type and effective dates.
- `cases` — case reference, originating station, district, dates, category, description and workflow status.
- `case_persons` — many-to-many link between people and cases, including role and role-specific status.
- `offences` / `crime_categories` — controlled offence classification catalogue.
- `case_assignments` — officers and units assigned to a case, with assignment history.
- `investigation_events` — authorised case progress events.
- `court_proceedings` — court reference and proceeding details, where authorised.
- `court_outcomes` — outcome, date, source and verification status.
- `convictions` — verified conviction information, linked to the relevant proceeding and person/case relationship.
- `documents` — metadata and private storage references.
- `stations`, `districts`, `departments`, `users`.
- `audit_events` — security and business audit records, preferably exported to a separate protected audit platform.
- `access_justifications` — reason, case reference and approval details for sensitive access where required.

### 4.2 Person-to-case roles

Use controlled values, for example:

- Suspect
- Arrested person
- Accused person
- Complainant
- Victim
- Witness
- Other approved role

Do not assume a person is a suspect merely because their details exist in the database.

### 4.3 Case status and judicial outcome are separate

Do not use one overloaded `status` field to represent the entire justice process. Separate:

- Police operational status (e.g. open, under investigation, submitted, closed administratively).
- Person’s role/status in a case.
- Charge/prosecution status, where in scope.
- Court proceeding status.
- Judicial outcome.

Possible outcomes may include acquitted, convicted, dismissed, withdrawn, pending, appeal pending or conviction overturned, but the final catalogue and definitions must be approved by the Police/legal stakeholders.

A case being closed administratively does not itself prove conviction or acquittal. Only record a conviction when supported by an authorised and verified source. Preserve appeal and reversal history.

---

## 5. Identity matching and duplicate prevention

The platform should help authorised users identify possible duplicate person profiles without automatically merging records based on a weak match.

### 5.1 Matching approach

- Use exact keyed lookup for verified identity numbers where legally approved.
- Support name and demographic matching as a possible-match workflow.
- Display a confidence/reason indicator to authorised reviewers.
- Require human review before merging profiles.
- Preserve source records, merge history and references after an approved merge.
- Do not treat a name match as proof that two records concern the same person.

### 5.2 Identity verification

Store verification state separately from the identifier itself, for example:

- Unverified
- Submitted for verification
- Verified
- Failed verification
- Verification expired / requires review

Record the source, date, officer or service that performed verification. Do not imply that an identity has been verified unless an authorised process actually confirmed it.

---

## 6. Authentication and account security

Every user must have an individual account. Shared station or generic officer accounts are prohibited.

### 6.1 Minimum controls

- Strong password policy and breached-password screening.
- Multi-factor authentication (MFA).
- Prefer phishing-resistant passkeys or hardware-backed authentication for privileged and high-risk users where feasible.
- Login throttling and protection against credential stuffing.
- Secure password reset and account recovery.
- Session regeneration after authentication and privilege changes.
- Automatic idle timeout.
- Account suspension and immediate revocation when an officer leaves, changes role or loses authorisation.
- Alerts for suspicious login behaviour.
- No default or shared production credentials.

### 6.2 Laravel implementation notes

Use Laravel’s supported authentication mechanisms or a vetted identity provider. Enforce MFA through the chosen identity/authentication architecture. Do not rely on frontend checks.

Example route grouping (illustrative only; middleware names must match the implemented application):

```php
Route::middleware([
    'auth',
    'verified',
    'mfa',
])->group(function () {
    // Protected application routes
});
```

For a system of this sensitivity, consider central identity management, single sign-on and managed device authentication if the institution provides suitable infrastructure.

---

## 7. Authorisation: RBAC, attributes and record-level policies

Use role-based access control (RBAC) together with attribute-based and relationship-based checks.

An access decision may depend on:

- User identity and active account status.
- Role and explicit permission.
- District, station, department and unit.
- Case assignment or supervisory responsibility.
- Record classification.
- Requested action (view, edit, reveal, export, print, administer).
- Purpose of access and case reference.
- Device and network trust, where deployed.
- Additional approval for exceptional or bulk access.

### 7.1 Suggested roles

The exact roles and permissions must be approved by the institution. Candidate roles include:

- Records Officer
- Investigating Officer
- Station Commander
- District Supervisor
- Authorised National/CID Officer
- Records Quality Reviewer
- Auditor
- Application Administrator
- Security Administrator
- Database/Infrastructure Administrator

### 7.2 Separation of technical and operational access

A technical administrator must not automatically be authorised to view person or case records. Separate:

- Application configuration privileges.
- User/role administration.
- Database and infrastructure administration.
- Operational records access.
- Security monitoring and audit review.

Use privileged access management, time-limited elevation and approval for exceptional administrative access.

### 7.3 Laravel policy example

```php
public function view(User $user, CriminalCase $case): bool
{
    if (! $user->is_active) {
        return false;
    }

    if ($user->hasPermissionTo('view-national-cases')
        && $user->hasApprovedNationalAccess()) {
        return true;
    }

    if ($case->assignedInvestigators()
        ->whereKey($user->id)
        ->exists()) {
        return $user->hasPermissionTo('view-assigned-cases');
    }

    return $user->district_id === $case->district_id
        && $user->hasPermissionTo('view-district-cases');
}
```

This is illustrative. The final policy must include all required constraints, exceptional access rules and audit behaviour. Default to deny.

### 7.4 Enforce authorisation everywhere

Apply policy checks to:

- Web routes and controllers.
- Livewire actions and components.
- API endpoints.
- Search results and autocomplete.
- Exports and report generation.
- Document downloads and previews.
- Background jobs.
- Bulk operations.
- Administrative actions.

Hiding a menu item is not authorisation. Test direct URL and API access with users from other stations and districts.

---

## 8. Purpose-based access and exceptional access

For sensitive searches, cross-district searches or access outside a user’s normal assignment, require a documented purpose where approved by policy.

Possible justification fields:

- Purpose/reason category.
- Related case reference.
- Free-text explanation where necessary.
- Requesting officer.
- Approving officer, if approval is required.
- Timestamp and decision.

Example purposes might include an assigned investigation, an authorised supervisory review or an approved records-quality exercise. The final list must be institutionally approved.

Implement “break-glass” emergency access only if the institution requires it. It should be time-limited, reason-bound, prominently flagged, fully audited and reviewed after use. It must not become a routine bypass.

---

## 9. Encryption and key management

Encryption must cover data in transit, at rest and in backups.

### 9.1 Data in transit

- Enforce HTTPS for all application traffic.
- Use modern TLS configuration; TLS 1.2 minimum where compatibility requires it and prefer TLS 1.3.
- Use VPN or an approved government network for station connectivity.
- Secure service-to-service connections.
- Disable insecure protocols and redirect HTTP to HTTPS.

### 9.2 Data at rest

- Encrypt database and storage volumes.
- Encrypt highly sensitive fields at application level where appropriate.
- Encrypt private documents and backups.
- Restrict database access to application and approved administration services.

### 9.3 Key management

Do not treat a key stored in the same database or beside an encrypted backup as adequate key separation.

Use an approved KMS or HSM where available. Define:

- Key ownership and custodians.
- Access approval and separation of duties.
- Rotation schedule.
- Revocation and recovery procedure.
- Backup and escrow arrangements, where approved.
- Key-use audit logs.
- Incident procedure for suspected key compromise.

Laravel encrypted casts may be useful for selected fields, but the production key architecture must be designed around managed key storage, rotation and recovery. Do not change `APP_KEY` casually: doing so can make existing encrypted values unreadable unless a planned re-encryption/key-versioning process exists.

---

## 10. Searchable encrypted identifiers and blind indexes

Encrypted values cannot normally be searched with ordinary SQL equality. Where exact matching is required, use a keyed blind index.

Example logical columns:

```text
identifier_ciphertext
identifier_lookup_hmac
identifier_key_version
```

Illustrative HMAC generation:

```php
$normalised = strtoupper(trim($identifier));

$lookup = hash_hmac(
    'sha256',
    $normalised,
    $dedicatedLookupSecret
);
```

Important requirements:

- Use a dedicated secret separate from the encryption key and `APP_KEY`.
- Keep the secret in an approved secret manager/KMS-backed configuration.
- Never store the plaintext identifier in logs or analytics.
- Normalise identifiers consistently before encryption and hashing.
- Restrict access to lookup endpoints and rate-limit them.
- Audit sensitive identifier searches.
- Plan key rotation and re-indexing.
- Understand that deterministic lookup tokens reveal equality and frequency patterns; assess this risk with the security architect.
- Do not use an ordinary unsalted hash for low-entropy identifiers.

Do not expose blind-index values to the client.

---

## 11. Data masking and controlled reveal

Default screens should mask highly sensitive identifiers and contact information.

Examples:

```text
NIN:      CM***********89
Phone:    +256 7** *** 483
Passport: UG*****13
```

Full reveal must require a specific permission and, where required, a purpose or re-authentication. Log each reveal event with user, record, time, purpose and device/session context.

Avoid returning full identifiers in API payloads when the interface only needs masked values. Do not send hidden sensitive values to the browser and merely cover them with CSS.

---

## 12. Audit trails and security monitoring

Auditability is a core system requirement. Record access as well as changes.

### 12.1 Events to capture

At minimum:

- Successful and failed authentication.
- Logout and session revocation.
- MFA enrolment, challenge and recovery events.
- Person search and person profile view.
- Case search and case view.
- Full identifier reveal.
- Document preview and download.
- Create, update, link, unlink, archive and lawful deletion actions.
- Case status and outcome changes.
- Export, print and report generation.
- User creation, suspension and role/permission changes.
- Exceptional access and approvals.
- Security configuration changes.
- Failed authorisation attempts.

### 12.2 Audit event fields

Capture, as appropriate:

- Unique event ID.
- Timestamp in UTC.
- Actor/user ID.
- Station, district and department.
- Action and outcome.
- Record type and internal record UUID.
- Related case reference where permitted.
- Source IP and device/session identifier.
- Purpose/justification and approval reference.
- Correlation/request ID.
- Changed field names and appropriately protected before/after values.

Do not log passwords, access tokens, session cookies, encryption keys or full identity numbers. Avoid copying entire sensitive record payloads into logs.

### 12.3 Tamper resistance

Do not rely exclusively on an audit table in the same database and security boundary as the application. Forward events to a separate, access-controlled audit/logging platform or SIEM. Use append-only or tamper-evident storage, restricted administration and monitored retention.

Audit logs themselves contain sensitive information and must be protected.

### 12.4 Alerts

Define alerts for suspicious patterns, such as:

- Unusual search volume.
- Repeated failed access attempts.
- Large numbers of person-profile views.
- Repeated full identifier reveals.
- Bulk export or unusual printing.
- Access from unusual devices or locations.
- Privilege escalation.
- Changes to audit or security settings.

Thresholds should be risk-based and tuned to avoid excessive false positives.

---

## 13. Record lifecycle, correction, archival and deletion

Do not provide unrestricted permanent deletion of police records.

Implement an approved lifecycle with separate states and actions, for example:

- Active
- Under review
- Restricted
- Closed
- Archived
- Retention review due
- Approved for lawful disposal

The final states and rules must be approved by records-management and legal stakeholders.

### 13.1 Corrections

- Allow authorised corrections through a controlled workflow.
- Record who made the correction, when and why.
- Preserve the audit history required by policy.
- Do not silently overwrite material case history.
- Distinguish factual corrections from changes in case status or judicial outcome.

### 13.2 Retention and disposal

Implement configurable retention schedules by record category only after formal approval. Include legal holds and suspension of disposal where required. A scheduled job must not independently delete records without the required approvals and evidence.

A lawful disposal workflow should include authorisation, verification, execution, audit evidence and handling of copies/backups under the approved policy.

---

## 14. Centralised hosting and station connectivity

Prefer a centrally managed platform hosted in an institutionally approved, secure data centre or government cloud. Police stations should connect through an approved secure network, VPN or equivalent protected access architecture.

### 14.1 Logical architecture

```text
Police Stations / District Offices / Headquarters
                    |
           Approved secure network
                    |
             Firewall / WAF
                    |
             Load balancer
                    |
          Laravel application tier
             /      |       \
            /       |        \
       Database   Queue    Private file store
            |       |        |
            +-------+--------+
                    |
          Central audit / SIEM
                    |
       Encrypted backup and DR environment
```

### 14.2 Station endpoint requirements

- Managed and patched workstations.
- Device encryption.
- Endpoint protection.
- Screen lock and inactivity controls.
- No local persistent storage of case records unless specifically approved.
- Restricted USB/removable media.
- No shared user sessions.
- Remote device revocation and incident reporting.
- Browser and operating-system security baselines.

A stolen workstation should not expose a locally cached national database.

### 14.3 Offline operation

Do not introduce offline case-data synchronisation by default. Offline mode creates difficult risks around local copies, conflicting edits, device loss and delayed revocation. If operationally required, design it as a separately assessed feature with encrypted storage, strict device controls, expiry and secure synchronisation.

---

## 15. Network security and perimeter controls

Apply layered network protection:

- Network segmentation between public-facing, application, database, management and backup services.
- Firewall allow-lists and least-open-port rules.
- WAF and reverse proxy protections.
- DDoS protection appropriate to the hosting environment.
- IDS/IPS or equivalent monitoring.
- Private database and Redis endpoints; never expose them directly to the public Internet.
- Administrative access through a controlled bastion or privileged access gateway.
- VPN or approved government network for station access.
- TLS for all connections.
- Rate limiting and abuse detection.
- Centralised network and security logging.

Do not rely on IP allow-listing as the only authentication or authorisation control.

---

## 16. Session and browser security

Configure secure session behaviour and test it.

Example production settings:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
```

Validate compatibility with required authentication redirects and integrations before choosing the final SameSite policy.

Additional controls:

- Short, policy-approved idle timeout for sensitive terminals.
- Absolute session lifetime.
- Session rotation after login and privilege changes.
- Re-authentication for high-risk actions.
- Remote session revocation.
- Concurrent-session policy.
- CSRF protection.
- Content Security Policy.
- Secure headers.
- No sensitive data in browser local storage.
- Cache-control headers preventing sensitive pages from being stored by shared browsers.
- Clear logout behaviour and session invalidation.

---

## 17. Exports, printing and bulk access

Exports and printing are high-risk data-exfiltration channels.

### 17.1 Default restrictions

- Disable bulk exports by default.
- Provide only approved report formats and fields.
- Enforce record-level authorisation before generating output.
- Require a documented purpose for sensitive exports.
- Require supervisor approval above institutionally defined thresholds.
- Log requester, approver, filters, fields, row count, time and destination where known.
- Apply short-lived, one-time or access-controlled download links.
- Expire generated export files automatically under an approved policy.
- Prevent public or guessable file URLs.
- Watermark sensitive reports where appropriate.
- Restrict print functionality by role and record type.

### 17.2 Monitoring

Alert on unusual search, export and print patterns. Do not rely on arbitrary thresholds alone; establish baselines with the Police security team.

---

## 18. Analytics and de-identification

Operational case management and statistical analysis should be separated logically and, where appropriate, physically.

### 18.1 Analytics design

```text
Operational case database
          |
Approved extraction process
          |
Minimisation / de-identification
          |
Analytics store / reporting layer
          |
Aggregated dashboards and reports
```

### 18.2 Analytics safeguards

- Exclude direct identifiers unless an approved analytic purpose requires them.
- Use age bands rather than full birth dates where sufficient.
- Use district or broader geography where exact addresses are unnecessary.
- Suppress small cell counts where they could identify individuals.
- Limit analyst access to the analytics layer.
- Audit data extracts and report access.
- Review re-identification risk before publishing or sharing results.
- Document data lineage and refresh schedules.

Do not assume that removing names alone makes a dataset anonymous. Combinations of age, location, dates and rare offence types may still identify a person.

---

## 19. Documents, photographs and attachments

Store case documents in private object storage or a protected file service, not in the public web root.

### 19.1 Required controls

- Encryption at rest and in transit.
- Random, non-guessable storage keys.
- File type and size allow-list.
- Content-type verification; do not trust filename extensions.
- Malware scanning and quarantine.
- Access checks on every preview and download.
- Short-lived signed URLs only after authorisation.
- Download and preview audit events.
- Metadata minimisation.
- Retention and legal-hold handling.
- No public bucket or unauthenticated file endpoint.

### 19.2 Laravel example

```php
$this->authorize('viewDocument', $document);

return Storage::disk('private-evidence')->temporaryUrl(
    $document->storage_path,
    now()->addMinutes(2)
);
```

The storage provider and disk must be configured as private. Confirm that the URL cannot be used beyond its intended lifetime and that access is logged before it is issued. For especially sensitive files, proxy downloads through an authorised service rather than exposing a direct storage URL.

---

## 20. Notifications and integrations

Notifications must not disclose sensitive case details unnecessarily.

- Use generic notification text with a secure link to the application.
- Require authentication and authorisation after following the link.
- Do not send NINs, allegations, statements or full case details over ordinary SMS or email.
- Record notification delivery status without logging sensitive payloads.
- Protect webhook endpoints with authentication, signature verification, replay protection and rate limiting.

Future integrations with identity, courts, prisons/corrections, immigration or other government systems must be treated as separate work packages. Each requires an approved legal basis, data-sharing agreement, technical specification, security assessment and authorised interface. Never scrape or connect to an external government database without explicit permission.

---

## 21. Backup, recovery and business continuity

### 21.1 Backup controls

- Encrypted database backups.
- Encrypted file/document backups.
- Separate backup credentials and administrative boundary.
- Immutable or offline copies where feasible.
- Restricted restore permissions.
- Key management separate from backup storage.
- Backup monitoring and failure alerts.
- Documented retention schedule.
- Regular restore tests.

### 21.2 Disaster recovery

Agree Recovery Point Objective (RPO) and Recovery Time Objective (RTO) with the institution. Do not promise values before infrastructure and operational requirements are assessed.

Document:

- Primary and disaster-recovery locations.
- Database replication and failover.
- Backup restoration process.
- DNS/network recovery.
- Key recovery and access approvals.
- Roles and escalation contacts.
- Communication procedures.
- Regular disaster-recovery exercises.

A backup is not proven until it has been restored and validated.

---

## 22. Production operations and secure deployment

### 22.1 Production baseline

```env
APP_ENV=production
APP_DEBUG=false
```

Also:

- Keep `.env` and secrets outside public access.
- Use least-privilege service accounts.
- Restrict SSH and administrative interfaces.
- Keep database, Redis and queue services on private networks.
- Disable development tools and debug endpoints in production.
- Apply operating-system and runtime security updates.
- Use supported PHP and Laravel versions.
- Pin and review dependencies.
- Run dependency vulnerability checks.
- Use secure CI/CD with protected branches and approvals.
- Keep deployment credentials in a secret manager.
- Maintain rollback procedures.
- Monitor application, infrastructure and security health.

### 22.2 Laravel-specific controls

- Use Laravel validation and explicit request DTOs/forms.
- Avoid mass assignment of sensitive fields.
- Use policies/gates for every protected resource.
- Protect Livewire actions as carefully as controllers.
- Validate ownership and tenant/station scope in every query.
- Use parameterised queries and avoid unsafe raw SQL.
- Escape output and sanitise rich content.
- Apply CSRF protection.
- Rate-limit authentication, search and sensitive endpoints.
- Use queues carefully: jobs must re-check authorisation or carry an approved, auditable service identity.
- Do not place sensitive records in queue payloads unless necessary and protected.
- Protect Horizon, Telescope, Pulse and other operational dashboards behind strong access controls; disable or isolate tools not approved for production.
- Avoid sensitive data in exception messages and logs.

### 22.3 CI/CD security checks

Run appropriate automated checks, such as:

```bash
composer audit
npm audit
```

Add static analysis, secret scanning, code review, container/image scanning where applicable, infrastructure-as-code scanning and deployment approval gates.

---

## 23. Passwords, privileged accounts and administrator controls

- Require long, unique passwords and screen against known compromised passwords.
- Prefer passkeys or hardware-backed MFA for privileged users.
- Apply login throttling and suspicious-login detection.
- Prohibit shared administrator accounts.
- Use separate named privileged accounts.
- Require approval and time-limited elevation for sensitive administrative actions.
- Review privileged access regularly.
- Remove access promptly when no longer required.
- Audit role changes, permission grants and recovery operations.
- Protect emergency accounts and test them under controlled procedures.

Password complexity alone is not a substitute for MFA, monitoring and sound account lifecycle management.

---

## 24. Development, testing and staging data

Never use real police records in local development or ordinary staging environments.

Use:

- Synthetic records.
- Generated test identities that cannot be mistaken for real records.
- Irreversibly anonymised datasets only where formally approved.
- Separate development, test, staging and production credentials.
- Separate encryption keys and storage.
- Restricted access to staging.
- No production database dumps on developer laptops.
- No sensitive data in screenshots, bug reports or support tickets.

If production support requires access to real data, use a formally approved, logged and time-limited process with minimum necessary access.

---

## 25. Security testing and release acceptance

Adopt OWASP ASVS as a verifiable application-security requirements baseline. A practical target is ASVS Level 2 across the application, with selected stronger controls for authentication, cryptography, access control, audit and the most sensitive workflows. The security architect and institution should confirm the final target.

### 25.1 Required testing

- Threat modelling and abuse-case analysis.
- Secure code review.
- Static application security testing (SAST).
- Dependency and secret scanning.
- Dynamic application security testing (DAST).
- Infrastructure and configuration vulnerability scanning.
- Penetration testing by qualified independent testers.
- Authentication and MFA testing.
- Authorisation and IDOR testing.
- Cross-station and cross-district isolation testing.
- Search and blind-index abuse testing.
- Export and document access testing.
- Audit integrity testing.
- Backup restoration and disaster-recovery testing.
- Load and performance testing.
- Incident-response exercise.

### 25.2 Essential authorisation test cases

Test at least that:

1. An officer from Station A cannot access Station B records without explicit authority.
2. A user cannot access a case by changing its ID in a URL or API request.
3. A user cannot reveal a full NIN without the required permission.
4. An investigator cannot view an unrelated case merely because they know its reference.
5. A suspended officer cannot continue using an existing session.
6. A system administrator cannot automatically view operational records.
7. A report/export cannot bypass record-level restrictions.
8. A document URL cannot be reused by an unauthorised user.
9. A user cannot grant themselves permissions.
10. An exceptional-access event is logged and available for review.

### 25.3 Release gate

Do not release to production while there are unresolved critical or high-risk vulnerabilities, failed access-control tests, untested backup restoration, missing legal approvals or missing operational incident procedures.

---

## 26. Breach response, incident management and ongoing assurance

Build the ability to investigate and respond to a suspected breach.

The system and operating environment should help answer:

- Which records may have been accessed?
- Which users and service accounts were involved?
- When did the activity occur?
- From which devices, sessions or network locations?
- Were records viewed, changed, printed or exported?
- Were documents accessed?
- Were credentials or keys compromised?
- What containment actions were taken?

### 26.1 Incident response lifecycle

1. Detect and report.
2. Triage and classify severity.
3. Contain affected accounts, devices, services or keys.
4. Preserve relevant evidence and logs.
5. Investigate scope and likely impact.
6. Notify institutional incident owners, legal counsel and the DPO.
7. Make regulatory and data-subject notifications where legally required and within applicable time limits.
8. Eradicate the cause and recover securely.
9. Document lessons learned and corrective actions.

The Police/institution must own the incident-response plan and notification decisions. Developers must provide the technical evidence and support required.

### 26.2 Ongoing assurance

- Regular access recertification.
- Periodic vulnerability assessments.
- Penetration testing after major changes and at an approved cadence.
- Security patch management.
- Audit-log review.
- Backup restoration exercises.
- Disaster-recovery exercises.
- Review of retention and disposal operations.
- Review of integrations and data-sharing arrangements.
- Security awareness training for users and administrators.

---

# 27. Functional scope and implementation modules

The security controls above apply across the functional platform. The following modules form the initial product scope.

## 27.1 Organisation management

- Districts.
- Police stations.
- Departments and units.
- Organisational hierarchy.
- Station status and configuration.
- User-to-station and user-to-district assignments.

## 27.2 User and access administration

- Individual user accounts.
- Role and permission management.
- MFA enrolment and recovery.
- Account activation, suspension and revocation.
- Station/district assignment.
- Session management.
- Privileged access workflow.

## 27.3 Person records

- Core biodata.
- Identity documents and verification status.
- Contact details.
- Address history.
- Occupation and other approved attributes.
- Photograph only where approved.
- Duplicate candidate review.
- Correction and merge workflows.
- Masking and controlled reveal.

## 27.4 Case management

- Unique case reference.
- Originating station and district.
- Incident date and location.
- Crime/offence category.
- Case narrative and approved structured fields.
- Case assignment.
- Case events and status history.
- Person-to-case roles.
- Related cases, where authorised.
- Closure and archival workflow.

## 27.5 Court and outcome records

- Court reference and relevant dates.
- Charges/proceedings where in scope.
- Verified outcomes and source.
- Appeal status and subsequent changes.
- Conviction records only where supported by an authorised source.
- Audit history for all outcome changes.

## 27.6 Search and record verification

- Search by case reference.
- Search by approved identity number using a blind index.
- Name and other permitted search.
- Station/district filters.
- Possible duplicate review.
- Purpose capture and approval for sensitive searches.
- Search throttling and audit logging.

## 27.7 Documents and evidence attachments

- Private file upload.
- File validation and malware scanning.
- Document categorisation.
- Controlled preview/download.
- Access audit.
- Retention and legal-hold controls.

This module should not be represented as a full digital evidence chain-of-custody solution unless that functionality is separately specified and designed.

## 27.8 Reports and dashboards

- Case counts by station and district.
- Crime category trends.
- Time-based trends.
- Case status summaries.
- Demographic aggregates where lawful and necessary.
- Verified court outcome summaries.
- Restricted operational reports.
- De-identified statistical reporting.

## 27.9 Notifications

- Case assignment notifications.
- Workflow/status notifications.
- Review reminders.
- Security alerts.
- Generic email/SMS notifications without sensitive case details.

## 27.10 Audit and administration

- Security event monitoring.
- Business audit history.
- Access review reports.
- User and role change history.
- Configuration audit.
- Export/print audit.
- Retention review queues.

---

# 28. Recommended technical architecture

The final technology choices must be confirmed against hosting, support and procurement requirements. A possible implementation using the Laravel ecosystem is:

| Layer                     | Suggested approach                                                      |
| ------------------------- | ----------------------------------------------------------------------- |
| Web application           | Supported Laravel version                                               |
| UI                        | Livewire or another agreed frontend                                     |
| Database                  | PostgreSQL or MySQL, selected after architecture review                 |
| Cache/session/queues      | Redis on private network                                                |
| Private documents         | Approved encrypted object/file storage                                  |
| Authentication            | Laravel authentication plus MFA, or institutional identity provider     |
| Authorisation             | Policies/gates with RBAC and contextual checks                          |
| Encryption keys           | Approved KMS/HSM or managed key service                                 |
| Audit/security monitoring | Separate protected audit pipeline and SIEM                              |
| Edge security             | Firewall, reverse proxy and WAF                                         |
| Deployment                | Hardened Linux, Nginx and PHP-FPM or approved platform                  |
| Backups/DR                | Encrypted, isolated backup and recovery environment                     |
| CI/CD                     | Protected repository, automated checks and controlled release approvals |

### 28.1 Logical application boundaries

Keep distinct services or modules for:

- Authentication and identity.
- Organisation and access administration.
- Person records.
- Case management.
- Court/outcome records.
- Search and matching.
- Document management.
- Reporting/analytics.
- Notifications.
- Audit and security monitoring.

A modular monolith may be a sensible initial implementation approach in Laravel. Do not introduce microservices unless scale, integration boundaries, operational capacity or security isolation justify the additional complexity.

---

# 29. Suggested development phases

A suggested sequence for delivery:

### Phase 0 — Discovery and approvals

- Confirm operational workflows.
- Confirm data dictionary and classifications.
- Map users, stations and districts.
- Agree lawful purposes and access matrix.
- Complete DPIA/privacy assessment.
- Agree hosting, network and security requirements.
- Confirm retention, disclosure and correction policies.
- Produce architecture and threat model.

**Exit criteria:** Approved requirements, data model, access matrix, privacy/security decisions and deployment direction.

### Phase 1 — Platform foundation

- Repository and CI/CD.
- Environments and secret management.
- Organisation structure.
- Authentication and MFA.
- User lifecycle.
- RBAC/ABAC foundations.
- Audit pipeline.
- Secure application shell and baseline hardening.

**Exit criteria:** Users can authenticate securely and access only permitted administrative functions; core security events are recorded.

### Phase 2 — Core records and case management

- Person profiles.
- Identity and contact records.
- Case records.
- Person-to-case relationships.
- Case assignments and workflow.
- Crime categories.
- Correction and duplicate-review workflows.

**Exit criteria:** Approved synthetic scenarios can be completed end to end with correct authorisation and audit history.

### Phase 3 — Search, documents and outcomes

- Secure search and blind indexes.
- Purpose-based access.
- Document storage and scanning.
- Court/outcome data model.
- Controlled reveal.
- Export/print controls.

**Exit criteria:** Sensitive search, document and outcome workflows pass security tests.

### Phase 4 — Reporting and operational readiness

- Operational dashboards.
- De-identified analytics.
- Notifications.
- Backup and restore.
- Monitoring and alerts.
- User and administrator documentation.

**Exit criteria:** Reports meet approved requirements and do not expose unnecessary personal data; restore tests succeed.

### Phase 5 — Security validation and pilot

- Independent penetration test.
- Remediation.
- User acceptance testing.
- Security and privacy sign-off.
- Training.
- Pilot at selected approved stations.
- Feedback and controlled improvements.

**Exit criteria:** Formal go-live approval by the designated institutional authorities.

### Phase 6 — Progressive rollout

- Enrol additional districts/stations.
- Train users and administrators.
- Monitor capacity and security.
- Review access and operational controls.
- Complete rollout acceptance for each wave.

National rollout is a separate operational activity and should not be assumed to be included in the initial pilot estimate.

---

# 30. Developer delivery checklist

Use this as a working checklist. Items requiring institutional decisions must remain blocked until approved.

## Governance and privacy

- [ ] Project owner and operational stakeholders identified.
- [ ] Legal and DPO review completed.
- [ ] DPIA/privacy impact assessment completed.
- [ ] Lawful purposes and processing basis documented.
- [ ] Data dictionary and classification approved.
- [ ] Retention and disposal schedule approved.
- [ ] Disclosure and correction rules approved.
- [ ] Hosting and cross-border processing approved.

## Identity and access

- [ ] Individual accounts only.
- [ ] MFA enabled.
- [ ] Account lifecycle and revocation implemented.
- [ ] RBAC/ABAC access matrix approved.
- [ ] Record-level policies implemented.
- [ ] Cross-station/district access tests passed.
- [ ] Privileged access separated.
- [ ] Purpose-based access implemented where required.
- [ ] Session expiry and remote revocation tested.

## Data protection

- [ ] TLS enforced.
- [ ] Storage/database encryption enabled.
- [ ] Sensitive field encryption implemented.
- [ ] KMS/HSM or approved key management configured.
- [ ] Blind indexes use separate secret keys.
- [ ] Masking and controlled reveal implemented.
- [ ] No sensitive data in ordinary logs.
- [ ] No production data in development/staging.

## Audit and monitoring

- [ ] Sensitive views and searches logged.
- [ ] Changes and outcome updates logged.
- [ ] Full identifier reveal logged.
- [ ] Document access logged.
- [ ] Export and print actions logged.
- [ ] Role/permission changes logged.
- [ ] Logs protected outside the application database.
- [ ] Security alerts configured and tested.

## Application and infrastructure

- [ ] Production debug disabled.
- [ ] Secrets protected.
- [ ] Database and Redis private.
- [ ] WAF/firewall/network segmentation configured.
- [ ] Private document storage configured.
- [ ] Upload validation and malware scanning enabled.
- [ ] Rate limits configured.
- [ ] CI/CD security checks enabled.
- [ ] Vulnerability remediation process defined.

## Operations and release

- [ ] Encrypted backups configured.
- [ ] Restore test completed.
- [ ] RPO/RTO approved.
- [ ] Disaster recovery plan tested.
- [ ] Incident response plan approved.
- [ ] Penetration test completed.
- [ ] Critical/high findings resolved or formally risk-accepted.
- [ ] User acceptance testing completed.
- [ ] Training and operating documentation delivered.
- [ ] Legal, DPO, security and operational go-live approvals obtained.

---

# 31. Key non-functional requirements

Confirm measurable targets with the client and infrastructure team. Do not assume these values without agreement.

| Area             | Requirement to define                                          |
| ---------------- | -------------------------------------------------------------- |
| Availability     | Service hours, uptime target and maintenance windows           |
| Performance      | Search response time and concurrent-user expectations          |
| Scalability      | Expected districts, stations, users, records and annual growth |
| Security         | Security baseline, MFA, encryption and independent testing     |
| Audit            | Events captured, retention and review responsibilities         |
| Recovery         | Approved RPO, RTO and recovery test frequency                  |
| Accessibility    | Supported devices, browsers and accessibility requirements     |
| Usability        | Training, language and station workflow needs                  |
| Interoperability | Approved APIs and external system interfaces                   |
| Maintainability  | Supported versions, patching and support arrangements          |

---

# 32. Important scope boundaries and assumptions

The following must be clarified before a fixed implementation commitment:

1. Whether the first release is a pilot or a complete national deployment.
2. Number of pilot stations, districts and expected users.
3. Whether historical records will be migrated and their quality/format.
4. Whether identity verification is manual or integrated with an authorised service.
5. Whether the project includes full evidence management or only case-document attachments.
6. Whether court proceedings and conviction data are entered manually or received through an authorised integration.
7. Whether the system requires offline operation.
8. Hosting/data-centre procurement and network connectivity responsibilities.
9. Hardware, endpoint management and station training responsibilities.
10. Independent security testing and certification costs.
11. Ongoing hosting, monitoring, maintenance and support costs.
12. SMS/email gateway and other third-party service charges.
13. National rollout and per-station/district onboarding costs.
14. Data protection registration, legal review and institutional approval responsibilities.

A district/station onboarding fee is not the same as the cost of developing, hosting, securing and supporting the national platform. Keep these cost categories separate in the commercial proposal.

---

# 33. Definition of done

A feature is not complete merely because the screen works.

For each feature, completion requires:

- Approved business workflow.
- Data minimisation and classification reviewed.
- Server-side authorisation implemented and tested.
- Validation and error handling implemented.
- Sensitive values masked and encrypted as required.
- Audit events generated where required.
- No sensitive information leaked through logs, URLs or client payloads.
- Automated tests covering positive and negative access cases.
- User acceptance criteria met.
- Documentation updated.
- Security review completed for high-risk functionality.

The overall system is ready for production only when functional acceptance, security testing, operational readiness and institutional/legal approvals have all been completed.

---

# 34. Reference standards and resources

Use current official versions during implementation and verify requirements at project start.

- Uganda Data Protection and Privacy Act, 2019: https://ulii.org/en/akn/ug/act/2019/9/eng@2019-05-03
- Uganda Data Protection and Privacy Regulations, 2021: https://pdpo.go.ug/
- Personal Data Protection Office (Uganda): https://pdpo.go.ug/
- OWASP Application Security Verification Standard (ASVS): https://owasp.org/www-project-application-security-verification-standard/
- OWASP Cheat Sheet Series: https://cheatsheetseries.owasp.org/
- OWASP Top 10: https://owasp.org/www-project-top-ten/
- NIST Cybersecurity Framework: https://www.nist.gov/cyberframework
- Laravel documentation: https://laravel.com/docs

Confirm the current version and applicability of each standard, regulation and government security framework before relying on it for compliance.

---

## Final implementation instruction

Treat this document as the development team's initial security and architecture baseline. It is not permission to process real police data or a declaration that the system is already compliant.

Start with discovery, threat modelling, data classification, the data dictionary, access-control matrix and DPIA. Build and test using synthetic data. Obtain formal Police legal, DPO, records-management, security and infrastructure approval for the relevant decisions before production data is loaded or the system is deployed.

**The central engineering rule is: no record should be collected, viewed, changed, disclosed, exported or retained unless the action is authorised, necessary for an approved purpose, appropriately protected and auditable.**
