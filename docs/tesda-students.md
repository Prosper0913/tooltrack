# TESDA students

TESDA trainees are stored as **Borrower Type = Student, Program = TESDA**. They use the existing Student borrowing rules and keep the same borrower ID and loan/history links across imports.

## Staff workflow

Staff can open **Add Borrower** or **Import Masterlist** from the Borrowers Directory. In Add Borrower, choosing TESDA automatically sets the type to Student. Year/grade is not applicable, and section is optional. An optional training batch can be entered using the Section / Batch field. Existing manual-edit permissions remain unchanged: Admin manages edits to existing borrowers; Staff can register and import students.

The directory's Program filter is **All, FPST, TVL, TESDA**. All is only a filter, not a stored program. Changing programs clears a section selection from the previous program. Student/TESDA filtering includes students without a section. Academic Info and Borrow-page suggestions display the saved program.

## Masterlist headers and example

The downloadable CSV template uses this exact order:

```csv
Student ID,Full Name,Year Level,Section,Course / Strand,Phone Number
000123,Juan Dela Cruz,,,TESDA,
```

For the program column, staff can use **Course / Strand**, **Course/Strand**, **Course/Program**, **Course / Program**, or **Program**. The existing **Course** header also remains accepted. Headers are matched without case or punctuation differences. **Batch** and **Training Batch** are accepted aliases for Section. **Grade Level** is accepted for Year Level. **Last Name** and **First Name**, optionally **Middle Name**, can replace Full Name.

Program values are trimmed and normalized to uppercase: `TESDA`, `tesda`, and ` TESDA ` all save as TESDA. A single file may mix programs:

- FPST: an 8–10 digit Student ID, Year 1 or 2, and a section.
- TVL: an 8–10 digit Student ID, Grade 11 or 12, and a section.
- TESDA: a numeric Student ID of 1–50 digits; year and section may be blank. No FPST/TVL year is assigned.

Phone Number may be blank. If supplied, it uses the existing mobile-number validation and normalization. Format Student ID as **Text** in spreadsheet software to preserve leading zeros before saving. The importer reads IDs as strings and also retains existing supported Excel zero-padding formats.

Rows use the mapped academic columns, including intentionally blank TESDA cells. The class selections above the upload are optional **defaults for absent columns**. Existing Student ID / Full Name-only files still work when the required FPST/TVL program, year and section defaults are selected. A missing required value or unknown program is reported against its file row; an invalid program's value appears in the explanation.

Check File previews without writing. Import re-parses and revalidates the uploaded file inside the existing transaction/import lock. Existing IDs follow the selected skip/fill-blank/overwrite policy. Reimporting does not insert another borrower, change the borrower primary key, or change its transactions. Conflicting duplicate rows in a file remain rejected; identical rows are imported once.

## Database and compatibility

**No database migration is required.** Programs remain in `borrower_enrollments.course` (`VARCHAR(80)`); student IDs remain in `borrowers.id_number` (`VARCHAR(50)`). A TESDA enrollment is saved even when its section is blank. Moving a student to TESDA through an authorized academic overwrite clears the former school year. No separate qualification field exists in the current borrower schema; this change does not introduce one.

The active path is `index.php` → `assets/js/student-import.js` → `api/student_import.php`. Unloaded v2 archive files are not part of that path. Existing directory queries, Borrow lookup, reports, and exports do not restrict students to FPST/TVL and continue to include TESDA. Directory CSV exports label the field Program.

CSV imports require no new configuration. As before, XLSX reading requires PHP's ZIP extension. The local default PHP configuration has ZIP disabled; CSV is available, and XLSX reader tests were run with `-d extension=zip` for the test process only. No persistent PHP configuration was changed.

## Verification

Tests use a disposable cloned database, not live borrower records:

The focused suite passed **31 checks**, including an integration run with **18 actual browser workflow assertions**. PHP syntax checks passed for all changed PHP files.

- Staff manual registration, Admin reopening/editing the saved TESDA program, blank school academics, and an optional batch.
- Real Staff multipart preview/commit of FPST/TVL/TESDA together using Course / Strand, including `000123` with blank year and section.
- Student/TESDA directory filtering; lookup by name and `000123`; a real Student checkout; repeat imports preserving the borrower and its existing loan.
- Program header alias, invalid program row/value, legacy FPST/TVL files, two-column class-default files, the downloadable template, and existing borrower reports.
- Actual headless Chrome app forms: Staff Add Borrower, Admin Edit Borrower, file selection, Check File, enabled Import button, mixed-file commit, program/section filtering, Borrow suggestions and selection by name and leading-zero ID, and a repeat-import preview. Scanner and chart libraries are stubbed only in this offline fixture; PHP APIs and file upload are real.
- XLSX reader checks for text IDs and formatted leading zeros, with ZIP temporarily enabled for the test process.
- The existing full workflow suite passed **558 checks**, including its FPST/TVL import cases.

Run the focused tests with:

```powershell
$env:TOOLTRACK_TESDA_BROWSER_TEST='1'
C:\xampp\php\php.exe -d extension=zip tests/tesda_workflow.php
```

The browser integration expects Chrome at its standard Windows installation path. The ordinary API checks still run without Chrome. The XLSX fixture is explicitly skipped if ZIP is unavailable.

## Files changed

- `index.php`
- `includes/student_validation.php`
- `api/borrowers.php`
- `api/student_import.php`
- `assets/js/borrowers.js`
- `assets/js/student-import.js`
- `assets/js/table-redesign.js`
- `tests/damage_loss_workflow.php`
- `tests/student_workflow_cases.php`
- `tests/tesda_workflow.php`
- `tests/tesda_workflow_cases.php`
- `tests/tesda_browser_cases.php`
- `docs/tesda-students.md`
