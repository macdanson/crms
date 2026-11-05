# Data dictionary (Phase 0)

**Status: DRAFT — for approval.** Written for the DPO, legal team, records management and developers. It answers the questions of guide §3.1 for the fields the software holds today.

Every field needs to be justified or removed. Retention is blank throughout because no schedule has been approved (§13.2).

**Classification:** RP = restricted personal data · HR = highly restricted case data · CO = confidential operational · IA = internal administrative · DS = de-identified statistical

**Protection:** E = encrypted in the application · M = masked on screen, full value only through a recorded reveal · B = keyed lookup token · P = plain text in the database (relies on database and volume encryption)

## People

| Field              | Why it is needed                                | Required | Class | Protection | Who can view                 | In reports or exports |
| ------------------ | ----------------------------------------------- | -------- | ----- | ---------- | ---------------------------- | --------------------- |
| Given names        | Identify the person                             | Yes      | RP    | P          | `persons.view`, within scope | No                    |
| Family name        | Identify the person                             | Yes      | RP    | P          | As above                     | No                    |
| Other names        | Identify the person                             | No       | RP    | P          | As above                     | No                    |
| Search name        | Name search and duplicate checks                | Derived  | RP    | P          | Never displayed              | No                    |
| Sex                | To be justified                                 | No       | RP    | P          | As above                     | No                    |
| Date of birth      | Distinguish people with the same name           | No       | RP    | E, M       | Reveal only                  | No                    |
| Birth year         | Duplicate checks; display without the full date | Derived  | RP    | P          | `persons.view`, within scope | No                    |
| Nationality        | To be justified                                 | No       | RP    | P          | As above                     | No                    |
| Occupation         | Listed as an approved attribute in §27.3        | No       | RP    | P          | As above                     | No                    |
| Registered station | Who is responsible; access scope                | Yes      | IA    | P          | As above                     | No                    |

## Identity documents

| Field                                      | Why it is needed                              | Required | Class | Protection | Who can view            |
| ------------------------------------------ | --------------------------------------------- | -------- | ----- | ---------- | ----------------------- |
| Type                                       | Which document                                | Yes      | RP    | P          | `persons.view`          |
| Number                                     | Exact identification, if legally approved     | Yes      | RP    | E, M, B    | Reveal only             |
| Lookup token                               | Exact search without decrypting               | Derived  | RP    | Keyed      | Never leaves the server |
| Issuing country                            | Interpret the document                        | No       | RP    | P          | `persons.view`          |
| Issuing authority                          | Interpret the document                        | No       | RP    | P          | `persons.view`          |
| Verification status, source, officer, date | Say whether, and how, it was confirmed (§5.2) | Yes      | RP    | P          | `persons.view`          |

**For the security architect:** the lookup token reveals when two records hold the same number, and how often a number occurs (§10). This needs a risk assessment.

## Contact details and addresses

| Field          | Why it is needed  | Required | Class | Protection | Who can view   |
| -------------- | ----------------- | -------- | ----- | ---------- | -------------- |
| Contact value  | Reach the person  | Yes      | RP    | E, M       | Reveal only    |
| Address        | Locate the person | Yes      | RP    | E, M       | Reveal only    |
| Valid from, to | Keep a history    | No       | RP    | P          | `persons.view` |

## Cases

| Field                | Why it is needed             | Required | Class | Protection | Who can view | In reports or exports        |
| -------------------- | ---------------------------- | -------- | ----- | ---------- | ------------ | ---------------------------- |
| Reference            | Unique identifier            | Yes      | HR    | P          | Case scope   | Export, not statistics       |
| Station, district    | Responsibility; access scope | Yes      | IA    | P          | Case scope   | Yes (district in statistics) |
| Offence category     | Classification               | Yes      | HR    | P          | Case scope   | Yes                          |
| Incident date        | The facts of the case        | Yes      | HR    | P          | Case scope   | Export; month in statistics  |
| Incident location    | The facts of the case        | Yes      | HR    | P          | Case scope   | **No**                       |
| Narrative            | The facts of the case        | Yes      | HR    | E          | Case scope   | **No**                       |
| Status, lifecycle    | Workflow                     | Yes      | CO    | P          | Case scope   | Yes                          |
| Person's role        | Their part in this case      | Yes      | HR    | P          | Case scope   | **No**                       |
| Investigation events | History                      | Yes      | CO    | E          | Case scope   | **No**                       |

**Open question:** the incident location is plain text so it can be displayed and searched in future. A precise location could identify a victim. Should it be encrypted?

## Court records

| Field             | Why it is needed                      | Required | Class | Protection | Who can view                                  |
| ----------------- | ------------------------------------- | -------- | ----- | ---------- | --------------------------------------------- |
| Court, reference  | Identify the proceeding               | Yes      | HR    | P          | Case scope                                    |
| Outcome, date     | What the court decided                | Yes      | HR    | P          | Case scope                                    |
| Source, reference | Why the record can be trusted         | Yes      | HR    | P          | Case scope                                    |
| Verification      | Whether a second officer confirmed it | Yes      | HR    | P          | Case scope                                    |
| Conviction        | Derived from a verified outcome       | Derived  | HR    | P          | Not yet shown on any screen as its own record |

## Documents

| Field                         | Why it is needed            | Class | Protection                                     |
| ----------------------------- | --------------------------- | ----- | ---------------------------------------------- |
| File content                  | Attached to the case        | HR    | E, private disk, random name, proxied download |
| Original name                 | Recognise the file          | HR    | P (a file name can itself be revealing)        |
| Type, size, hash, scan status | Safe handling and integrity | IA    | P                                              |

## Accounts and audit

| Data                          | Why it is needed              | Class | Note                                                                            |
| ----------------------------- | ----------------------------- | ----- | ------------------------------------------------------------------------------- |
| Name, email, staff no., title | Individual accountability     | IA    | No shared accounts                                                              |
| Station, district, department | Access scope                  | IA    |                                                                                 |
| Role grants                   | History of who held what      | IA    | Never deleted                                                                   |
| Audit events                  | Accountability                | HR    | Contain free-text reasons: users are told not to enter personal details in them |
| Access justifications         | Why sensitive access happened | HR    |                                                                                 |

## De-identified statistics

`statistic_snapshots` holds only month, district, offence category, status and a count. Small counts are hidden when read back (§18.2). **Review needed:** a rare offence in a small district in one month could still identify someone, even with suppression.
