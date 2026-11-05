# Input for the Data Protection Impact Assessment (Phase 0)

**Status: DRAFT input, not a completed DPIA and not legal advice.** Written for the DPO and legal team, who own the assessment (§2.3). It records what the software does, so they do not have to work it out. Anything needing a legal view is marked **For the DPO/legal**, and nothing here states what the law requires.

Applicable obligations under Uganda's Data Protection and Privacy Act, 2019 and Regulations, 2021 must be confirmed by the institution's legal team and DPO.

## Data subjects

People linked to cases: suspects, arrested and accused people, complainants, victims and witnesses. Also system users (police personnel).

**For the DPO/legal:** witnesses and victims may need stronger protection than the software currently gives them. Children may appear in records, and nothing treats them differently today.

## Data categories

See the [data dictionary](data-dictionary.md). In summary: names, date of birth, sex, nationality, occupation, identity numbers, contact details, addresses, allegations, roles in cases, narratives, documents and court outcomes.

Not collected: biometrics and photographs.

## Purposes and lawful basis

**For the DPO/legal:** the purposes and lawful basis of each processing activity. The software holds only placeholders (`App\Enums\AccessPurpose`).

## Data flows and recipients

| From              | To                     | What                                                 | Control                                                  |
| ----------------- | ---------------------- | ---------------------------------------------------- | -------------------------------------------------------- |
| Officer's browser | Application            | All records                                          | HTTPS required in production                             |
| Application       | Database               | All records, restricted fields encrypted             | Hosting decision                                         |
| Application       | Private file store     | Encrypted documents                                  | Hosting decision                                         |
| Application       | Malware scanner        | **Decrypted** document content                       | Must be on a private network                             |
| Application       | Audit log and platform | Events without restricted values                     | Platform not yet chosen                                  |
| Application       | Mail server            | Generic notices and sign-in links only               | Hosting decision                                         |
| Application       | HaveIBeenPwned API     | Partial password hash (k-anonymity), production only | **For the DPO/legal:** is this external call acceptable? |
| Authorised user   | Download or print      | Case list exports; printed case details              | Purpose, approval, watermark, audit                      |

There are no integrations with other agencies, and no disclosure feature.

**For the DPO/legal:** rules for disclosure to courts and agencies. Until these exist, staff may copy information out by hand, which the system cannot control.

## Necessity and proportionality

- Fields are limited to those in the data dictionary. Each should be justified or removed in review.
- Full identifiers and contact details are hidden unless a permitted user states a purpose and re-enters their password.
- Exports contain no personal data and are off by default.
- Statistics hold aggregates only.

## Accuracy and fairness

- A person's role belongs to a case, never to the person.
- Police status, role and court outcome are separate, and closing a case implies nothing about guilt.
- A conviction exists only because of a court outcome verified by a second officer, with a named source. Reversals are kept.
- Identity numbers start as unverified, and a name match is labelled as not being proof.
- Duplicates are merged only after human review, with history kept.

## Data-subject rights

Corrections are supported, with a reason and history kept. There is no feature for handling access requests, restriction requests or objections.

**For the DPO/legal:** how rights apply, and any lawful limitations, so that the workflows can be specified.

## Retention and disposal

Nothing is ever deleted, which is itself a risk: records are currently kept indefinitely. Audit logs have a configurable local retention of 90 days by default in the log file (`LOG_AUDIT_DAYS`), and that default has not been approved. Prepared exports are destroyed after 24 hours.

**For records management and legal:** the retention schedule (§13.2). This must be settled before real data is loaded.

## Risks to individuals (for the assessment to rate)

| Risk                                                 | Main safeguards in the software                   | Residual concern                                      |
| ---------------------------------------------------- | ------------------------------------------------- | ----------------------------------------------------- |
| Exposure of a witness or victim's identity           | Scope rules, masking, audit, restricted lifecycle | Names are not encrypted; insiders within scope        |
| Someone being treated as guilty because of a record  | Separate status and outcome; wording              | Depends on training and how records are read          |
| Wrongful link between two people through a bad merge | Human review; history                             | No un-merge screen yet                                |
| Misuse by an authorised officer                      | Audit, alerts, purpose capture                    | Purpose is self-declared unless approval is required  |
| Mass breach                                          | Encryption of restricted fields, separated keys   | Hosting and key management not in place               |
| Inaccurate information spreading                     | Correction workflow                               | Corrections do not reach earlier exports or printouts |

## Breach response

The audit trail can show which records were accessed, by whom, when, from which address and session, and whether they were viewed, changed, printed or exported (§26). The institution must own the response plan and notification decisions.
