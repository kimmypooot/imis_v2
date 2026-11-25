# SALN in IMIS — design

Date: 2026-11-25. Status: approved by the user, 2026-11-25.

## Intent

Every CSC RO VIII employee who is required to file a Sworn Statement of
Assets, Liabilities and Net Worth (RA 6713 §8) should be able to encode it in
IMIS, print it on the official form, and have it go through HRD, the SALN
Committee and the Regional Director. The signed, sworn copy then ends up
in the employee's 201 File. IMIS keeps the entries as a record so next year's
SALN starts from this one, and HRD can see who has filed and who hasn't.

Signing stays on paper. The employee signs in person and swears before the
Regional Director, or before a notary outside the office. IMIS produces the
form and tracks it, but never replaces the wet signature or the oath.

## The form

- **Current form:** the 2025 SALN Form, per CSC Resolution No. 2500632 (MC
  No. 14, s. 2025), "Updated Form as of 3 February 2026". It is mandatory
  for all entry, annual and exit SALNs from 1 January 2026, and no 2026 form
  exists.
- **Reference files (the official Word documents), now in
  `docs/reference/saln/`:**
  - `2025-saln-form.docx`: the SALN form (2 pages)
  - `2025-saln-as1-declarant.docx`: AS-1, the additional sheet for the
    declarant
  - `2025-saln-as2-spouse-children.docx`: AS-2, the additional sheet for the
    spouse and children

  CSC's site blocks automated downloads, so these are the copies an LGU
  republished. Their internal edit dates (3 Feb 2026) match CSC's version
  note. **These files are the reference for every measurement and for the
  fidelity check.** The user should still compare them once with a copy
  downloaded from csc.gov.ph in a browser.
- **Page setup (from the Word files):**
  - paper: 8.5 × 13 in (long bond)
  - margins: top 0.17 in (245 twips), right 0.19 in (270), left 0.25 in (360)
  - bottom margin: 0.125 in (180) on the form, 0.19 in (274) on AS-1 and AS-2
- **Type:** Bookman Old Style, mostly 10 pt and 8 pt, with some 9, 7 and
  6 pt, and 13 pt for the title. The check boxes use MS Gothic ☐.
- **Footer:** "Page X of Y". Every page carries "Signature/Initial of
  Declarant".
- **Page 1:**
  - header: "2025 SALN Form / Per CSC Resolution No. 2500632 / Promulgated
    on 25 June 2025"
  - title and "(As required by R.A. No. 6713)"
  - COMPLIANCE FOR:
    - ☐ Assumption of office as of ___
    - ☐ Annual filing as of December 31, ___
    - ☐ Exit as of ___
  - declarant: name, position, agency/office, office address
  - spouse: name, position, agency/office, office address
  - joint / separate / not applicable
  - multiple marriages: the names of the other spouses, or ☐ Not Applicable
  - unmarried children below 18 living in the household: name and age
  - 1. Assets
    - a. Real Properties: description, kind, exact location, assessed
      value, current fair market value, acquisition year and mode,
      acquisition cost, and a subtotal
    - b. Personal Properties: description, acquisition year, acquisition
      cost or amount, and a subtotal
    - TOTAL ASSETS
- **Page 2:**
  - 2. Liabilities: nature, name of creditor, outstanding balance, and
    TOTAL LIABILITIES
  - NET WORTH: Total Assets less Total Liabilities
  - Business Interests and Financial Connections, or ☐ "I/We do not have
    any…"
  - Relatives in the Government Service, within the fourth degree,
    including bilas, balae and inso, or ☐ "I/We do not know of any…"
  - the certification and the Ombudsman authorization
  - Date
  - two "Signature of Declarant" blocks, each with Government Issued ID,
    ID No. and Date Issued
  - "SUBSCRIBED AND SWORN to before me this ___ day of ___…" and the line
    for the (Person Administering Oath)
- **Footnotes i–iv:** printed as on the form.

## Decisions made with the user

1. **The printout is a print page in IMIS, like the PDS print (option B).**
   It must reproduce the official form exactly: page size, margins,
   spacing, padding, borders, fonts and line breaks. It is printed, or saved
   as PDF, from the browser. Filling the CSC Word file was considered and
   rejected (see Approaches).
2. **Process:**
   1. The employee encodes the SALN in IMIS.
   2. They generate and print it, then hand the hard copy to HRD for
      consolidation.
   3. The SALN Committee reviews it.
   4. The Regional Director signs it as the Person Administering Oath.
   5. The signed and sworn copy is scanned and uploaded to IMIS, into the
      employee's 201 File.
3. **Oath:** the Regional Director is the Person Administering Oath by
   default. A private notary outside the office is the exception, chosen by
   the employee.
4. **Uploading the final scan:** either the employee or HRD can do it.
5. **HRD can override the Committee.**
6. **Spouses always file separately.** Many employees' spouses work in other
   agencies. "Joint Filing" is never offered.
7. **Only new SALNs are filed in IMIS.** No legacy or past-year import.
8. **The SALN Committee will be created by the office.** In IMIS it is a
   permission held by its members.

## Goals

- Encode entry, annual and exit SALNs, pre-filled from what IMIS already
  knows, with totals and net worth computed by the server.
- Print a page that matches the official form, plus AS-1 and AS-2 when
  needed.
- Track each SALN from encoding to filing, with returns for correction.
- File the final scan in the 201 File, linked to the record.
- Give HRD a per-year compliance list: required to file, filed, missing,
  returned and exempt.
- Remind employees of their due dates.

## Non-goals (for now)

- Electronic signatures or online oath-taking. Paper only.
- Joint filing.
- Importing past SALNs.
- Third-party requests for copies, and redaction (Rule X). These are
  handled by the repository agency.
- Producing the transmittal to the repository agency, including CSC's
  "Summary List of Filers". This can come later; the compliance list
  covers the tracking.
- Executive Dashboard figures.

## Anti-goals (failure even if it "works")

- A printout that drifts from the official layout: shifted rows, a
  different font that changes line breaks, a wrong page size or margins.
- A paper copy in HRD's hands that doesn't match the record, for example
  because the employee edited after printing.
- Net worth or any declared figure stored or shown in plain text, or seen
  by anyone without a need to see it.
- An employee filing their own SALN to the 201 File without HRD's
  clearance.

## Constraints

- SALN contents are personal data under RA 10173.
  - The whole statement is encrypted with `APP_KEY`, like the PDS
    submissions and items 34–40.
  - Every view by someone other than the employee is audited.
- Every route has `permission:` middleware and a policy check. New
  permissions go in `App\Enums\PermissionName`.
- Business logic stays in a single writer, `App\Support\HR\Saln\...`;
  controllers stay thin.
- The UI uses the design system components and tokens. The print page stays
  light and follows the form, not the theme.
- No new Composer or npm dependency.

## Approaches considered

- **A. Fill the official CSC Word file** (rejected). It is exact by
  construction, but people would print from Word, and LibreOffice renders
  it slightly differently. The user preferred staying in the browser, like
  the PDS.
- **B. Print page in IMIS (chosen).** It matches how the PDS, leave form,
  DTR and service record are printed, and needs no Word. Its risk is
  fidelity: the layout must be copied carefully from the Word files, and
  the screen must tell people the print settings to use.
- **C. Server-side PDF** (rejected). It would need LibreOffice or a
  headless browser on the server, a new dependency, and adds nothing to B.

## Design

### People and permissions

| Who | Permission | Can |
|---|---|---|
| Employee | none (their own) | Encode, generate and print their SALN; see its status and the Committee's remarks; upload the final scan once the SALN is cleared |
| SALN Committee member | `saln.review` (new) | See SALNs that HRD has received, and clear or return them with remarks. Never their own. |
| HRD | `saln.manage` (new) | Mark the hard copy received; override the Committee (clear or return); upload the final scan; record exemptions; see the compliance list; open any SALN |

- Both permissions open the Human Resources area.
- Seed `saln.manage` to the HR roles that hold `employee_documents.manage`.
  `saln.review` goes to no role by default; HRD assigns it to the Committee.

### Who must file

`EmploymentStatus` decides who is required to file by default:
- **Required:** permanent, temporary, coterminous and contractual.
- **Not required:** casual, job order and contract of service. The rules
  exempt "casual or temporary workers", meaning people hired for work
  outside the usual operations. That is not the same as a *temporary
  appointment*, which still files. Laborers are also exempt, and JO and COS
  staff are not government employees.
- **Annual filing for year X** uses the employee's status on December 31
  of X, taken from their plantilla or non-plantilla period on that date. If
  there is none, it uses the current status. (My call.)

HRD can mark an individual exemption with a reason, for example a laborer
position or an honorary role. (The user confirmed this list on
2026-11-25.)

### HRD encoding on behalf of an employee

An HRD user with `saln.manage` can encode, generate and upload a SALN for
any employee, with the same screens and rules. This covers two cases:

- **Exit filers:** their account is deactivated by `AccountLifecycle` the
  night after `separation_date`.
- **Employees with no IMIS account.**

Each action records the acting user and is audited as done on someone's
behalf. HRD encoding a SALN does not let the same person decide it (see
Conflicts).

### Conflicts

- **Nobody clears or returns their own SALN.** This includes HRD's
  override. Another HRD member or a Committee member decides it.
- **The Regional Director's own SALN** cannot have the RD as administering
  officer. It defaults to a notary. The RD may instead enter another
  administering officer's name and title, for example someone the RD has
  delegated in writing, as the rules allow.
- **The same applies to whoever `OfficeHeads` returns** for the Office of
  the Regional Director, including an OIC.

### Filing types and due dates

| Type | As of (reckoning date) | Due |
|---|---|---|
| Entry (assumption, including re-entry) | the first day of service | 30 days after assumption |
| Annual | December 31 of the year before | April 30 |
| Exit | the last day in office | 30 days after separation |

- **Entry:** the assumption date is the employee's
  `original_appointment_date`, or the re-entry date where IMIS records one.
  Entry and exit SALNs only become due for assumptions or separations after
  go-live.
- **Exit:** `separation_date` triggers the exit SALN.
- **Annual:** everyone required to file who was in service on December 31.
- **Reminders** go through the existing bell and push notifications:
  - when a SALN becomes due
  - 7 days before it is due
  - on the day it is due
  - when it is returned for correction

  The exact schedule is the builder's call.

### States

```
draft ──generate──▶ generated ──HRD receives hard copy──▶ received
  ▲                                                         │
  └────────── returned (remarks) ◀── Committee / HRD ───────┤
                                                            ▼
                                                         cleared ──scan uploaded──▶ filed
```

- **draft:** the employee is encoding and can edit.
- **generated:** the employee has printed a numbered version and can still
  edit. Editing and generating again makes a new version, and the old
  printout is void.
- **received:** HRD has marked the hard copy of version N as received.
  Editing is locked. HRD checks that the paper shows version N's reference
  number.
- **returned:** the Committee or HRD has returned it with remarks. The
  employee can edit again and generate a new version, which goes back to
  HRD as a hard copy.
- **cleared:** the Committee or HRD has cleared it. Next is the RD's
  signature, or a notary, done on paper.
- **filed:** the signed and sworn scan has been uploaded and the SALN is
  closed.

Other rules:
- **HRD override:** HRD can clear or return a received SALN without the
  Committee. The record keeps who decided and whether it was an override.
- **One open SALN per employee, type and reckoning date.** A filed one is
  never edited; a correction would be a new filing, outside this scope.
- **The record keeps every transition:** who, when and remarks.

### Versions and the reference number

- **Generating freezes the entries into a version.** Each version is
  immutable and encrypted, like `PdsSubmission`.
- **Every printed page shows a small reference** next to the page number,
  for example `IMIS SALN-2026-0042 v2`. HRD and the Committee use it to
  match the paper to the record.
- **The reference goes in the footer margin and must not move any form
  content.**

### Data

- **`salns`: one row per filing.**
  - employee, type, reckoning date, and year for annual filings
  - status and current version
  - oath mode: `regional_director` or `notary`
  - the timestamps and users for received, cleared or returned, and filed
  - the 201 document id of the uploaded scan
  - `override` flags
- **`saln_versions`: an immutable snapshot per generation**, holding the
  encrypted statement JSON plus the administering officer's name and title.
- **`saln_events`, or the audit log:** the transition history. Using the
  audit log alone is the builder's call, if it can show the timeline on the
  page.
- **What is encrypted:** the statement JSON (all entries and totals) is
  encrypted as a whole. Only the status and dates are plain, which is all
  the compliance list needs.

### Encoding (My SALN)

The employee page is `/my/saln`: a list of their SALNs, plus the one that is
due.

**Pre-filled from IMIS (editable unless noted):**
- **Declarant:** name from the employee record; position from their current
  plantilla item or position.
- **Agency/office:** "Civil Service Commission Regional Office VIII", with
  the office.
- **Office address:** from the organizational unit's `address`.
- **Spouse:** from PDS item 22. If the spouse is in government, the employee
  fills in position, agency and office address. If not, those stay blank,
  as the 2025 rules allow.
- **Joint / separate:** fixed to "Separate Filing" when the spouse is in
  government, otherwise "Not Applicable". Not editable.
- **Multiple marriages:** a list of names, or "Not Applicable".
- **Children:** from PDS item 23. The list shows children under 18 as of the
  reckoning date, with their age as of that date. The employee confirms
  each one is unmarried and living in the household, or dependent if living
  apart while studying.

**Tables:**
- real properties, personal properties, liabilities, business interests and
  relatives, each with an add-row control
- an "I/We do not have / do not know of any" switch for business interests
  and for relatives

**Ownership for AS-2:** each property row records whose it is: the declarant
(including conjugal or community property), the spouse's exclusive
property, or a child's. Spouse and child property prints on AS-2.

**Amounts and totals:**
- **Amounts:** pesos with 2 decimals. Assessed and fair market values accept
  "N/A", for example memorial lots.
- **Totals and net worth:** computed on the server and shown live. The client
  may preview them, but the server's figures are what get saved and printed.

**Other fields:**
- **Government ID:** type, ID number and date issued. Printed in the first
  declarant signature block; the second block stays blank because there is
  no joint filing.
- **Oath:** "Before the Regional Director" (default) or "Before a notary
  public".

**Starting the next SALN:** "Start from my last filed SALN" copies the
entries, with the type and date reset.

**Validation:** what the form requires. Every applicable field is filled in,
or explicitly "N/A".

### The print page (`Pages/HR/SalnPrint.vue`)

- **It is a standalone print page, like `PdsPrint.vue`.**
  - It uses `@page { size: 8.5in 13in; margin: 0 }` and draws the official
    margins itself.
  - It reproduces each table's column widths, row heights, borders, shading,
    font sizes, check boxes and footnotes from the Word files, measured in
    twips and converted to inches.
- **Row counts:**
  - Page 1 and page 2 keep the official number of blank rows in each table.
  - Overflow continues on **AS-1** for the declarant, as the rules intend.
  - Spouse-exclusive and children's property prints on **AS-2**.
  - The page count and "Page X of Y" cover every sheet printed.
- **Fonts** (my call):
  - **Bookman Old Style is the target.** It ships with Office on the
    office's Windows PCs, and the fidelity check is run against it.
  - **Fallback:** a self-hosted copy of TeX Gyre Bonum, a freely licensed
    clone of URW Bookman. Its widths are close but **not identical**, so
    line breaks can differ slightly.
  - **Warning:** the on-screen bar checks `document.fonts.check()`. If
    Bookman Old Style is missing, it warns that the printout may differ
    slightly and suggests printing from an office PC.
  - **Check boxes:** MS Gothic ☐ is replaced by a drawn box of the same
    size.
  - The font file is the one asset added. It is not a package dependency.
- **Oath block:**
  - With the Regional Director: IMIS prints the name and title of the head
    of the Office of the Regional Director under "(Person Administering
    Oath)". It uses `OfficeHeads`, so an OIC designation is respected, and
    the name is captured when the version is generated.
  - With a notary: the oath block is blank.
  - The day, month and place of the oath are left blank for handwriting.
- **On screen only (hidden when printing):** a bar with "Print", the
  reference number, and the print settings to use: paper 8.5 × 13 / Long,
  margins None, scale 100%, background graphics on.
- **Who can open it:** the owner, `saln.review` on received SALNs, and
  `saln.manage`. Others' views are audited.
- **Fidelity check (required before release):**
  1. Print the official Word file to PDF.
  2. Print the IMIS page to PDF with the same entries.
  3. Overlay the two. Lines, boxes and text must line up.

  Include a screenshot comparison in the PR.

### HRD and Committee pages (Admin → Human Resources → SALN)

- **Queue:** received SALNs awaiting review, oldest first.
  - The review page shows the entries read-only, the version and reference,
    and the print view.
  - Actions: **Clear**, or **Return** (remarks required).
  - Committee members never see their own SALN.
- **Receiving:** HRD searches by employee or reference and marks version N
  as received. Only the latest generated version can be received.
- **Filing:** on a cleared SALN, the employee (on My SALN) or HRD uploads
  the scan.
  - The upload goes through `EmployeeDocuments` as an `EmployeeDocumentType::Saln`
    document. Its title is "SALN — Annual 2025" (or Entry or Exit with the
    date), and its document date is the reckoning date.
  - The SALN is then `filed`. The document shows in the 201 File like any
    other.
  - An employee uploads only for their own cleared SALN. This is a narrow
    exception to "employees don't upload to their own 201 File", allowed
    only through the SALN service.
- **Compliance list:** a year and type filter.
  - For each person required to file: status, due date, days late, and
    exemption.
  - Counts at the top, using StatTile.
  - Export is the builder's call.
- **Exemptions:** HRD marks an employee exempt for a year, with a reason.

### Audit events

- `saln.generated`
- `saln.received`
- `saln.returned`
- `saln.cleared` (with an override flag)
- `saln.filed`
- `saln.exempted`
- `saln.viewed`, for each view by someone other than the employee

Add the morph map entries.

### Release notes

The work goes into the current month's What's New draft (`3.0.0-beta.N`); no
new version.

## Left to the builder

- The table and column names, and whether `saln_events` exists or the audit
  log is enough.
- The reminder schedule details.
- The page and component structure, reusing the design system components.
- The exact reference-number format.
- The compliance list export.
- The tests: the state machine, permissions and policy, encryption at rest,
  totals, the children's age filter, the AS-1 and AS-2 split, the upload
  to the 201 File, and the audit events.

## Calls made by the designer (the user accepted these on 2026-11-25)

- **Encoding on behalf:** HRD can encode a SALN for an employee (exit
  filers, people with no account).
- **No self-decisions:** nobody clears or returns their own SALN.
- **The RD's own SALN:** it goes to a notary, or to another named
  administering officer.
- **Font:** Bookman Old Style is the target. TeX Gyre Bonum is a fallback
  with a warning.
- **Status year:** whether someone must file for a year depends on their
  status on December 31 of that year.
- **Out of scope for now:** transmittal to the repository agency and
  third-party access.
