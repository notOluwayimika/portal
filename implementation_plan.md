# Task: Implement Bulk Guardian Import via Excel/CSV

## Context
We need to allow administrators to bulk import guardians and link them to existing students via an Excel or CSV file. The import must handle deduplication intelligently: if a guardian already exists (matched by email or phone), reuse them instead of creating duplicates. It must also handle the student-guardian linking logic carefully, using admission numbers to identify students.

This builds on the existing guardian registration logic (Case A new guardian / Case B existing guardian) — the import is essentially an automated, bulk version of that flow. The import file should support the SAME fields available in the Add Guardian form during student registration (with the exception of photos — see note below), so that an admin can fully onboard guardians via spreadsheet without needing to edit anything manually afterward.

### Photo Handling Note
Photos are NOT part of the bulk import. Guardian photos must be added or updated later via the guardian profile edit page. This keeps the import lightweight, avoids external URL fetching, and avoids SSRF/timeout/storage issues. The import template must NOT include a photo column.

## Expected File Format

The import file must contain the following columns (header row required). Columns mirror the Add Guardian form fields exactly (excluding photo), plus the linking fields needed for bulk processing.

### Required Columns

Linking fields (used to locate the student and define the relationship):
- `admission_number` — the student's admission number (used to locate the student)
- `relationship` — one of: father, mother, guardian, uncle, aunt, grandparent, other
- `is_primary` — yes/no or true/false (whether this guardian is the primary for the student)

Guardian identity (required to create or match a guardian):
- `first_name`
- `last_name`
- `phone` — also used for deduplication

### Optional Columns

Guardian personal details:
- `middle_name`
- `gender` — male / female / other
- `marital_status` — single / married / divorced / widowed
- `email` — used as login identifier and for deduplication

Contact information:
- `whatsapp_number`
- `emergency_contact`

Address information:
- `city`
- `state`
- `country`
- `postal_code`

Employment information:
- `occupation`
- `employer_name`

Identification:
- `id_type` — national_id / passport / drivers_license
- `id_number`
- `id_expiry_date` — date in YYYY-MM-DD format

Status and access:
- `status` — active / inactive / blocked (default: active)
- `can_login` — yes/no or true/false (whether to grant login access; default: no)
- `preferred_contact_channel` — email / sms / whatsapp (used when sending invitation if can_login = true; default: email if available, otherwise sms)

### Notes on Column Coverage

This column list MUST stay in sync with the Add Guardian form, with the single exception of the photo field. If new non-photo fields are added to the form in the future, they should be added to the import template and validator simultaneously. Treat the form as the source of truth — anything an admin can enter manually (except photos) should be importable via spreadsheet.

## Deliverables

### 1. Import Endpoint

- `POST /api/guardians/import` — accepts a multipart file upload (`.xls`, `.xlsx`, or `.csv`).
- Returns a job ID immediately if the file is queued for background processing.
- Returns synchronous results only for files with fewer than 50 rows.

Also provide:
- `GET /api/guardians/import/template` — downloads a blank Excel template with all supported columns, column descriptions in a second sheet, and a sample row.
- `GET /api/guardians/import/{jobId}/status` — returns import progress and final results.

### 2. Import Processing Logic

For each row in the file, execute the following logic in order:

#### Step 1: Validate Row
- Check all required columns are present and non-empty.
- Validate formats: phone, email (if provided), date fields, enum values.
- If any required field is missing or invalid, mark the row as failed with a reason and skip to the next row.

#### Step 2: Locate the Student
- Find a student in the current school by `admission_number`.
- If no student is found, mark the row as failed: "Student with admission number {X} not found."
- Skip to the next row.

#### Step 3: Check if Guardian Already Exists
Match against existing guardians in the current school using this priority:

1. **By email** (if email is provided in the row):
   - Look up `users` table by email (scoped to current school via the guardian relationship).
   - If found, retrieve the linked Guardian.

2. **By phone** (always check):
   - Look up `guardians` table by phone within the current school.
   - Also check `whatsapp_number` if `phone` lookup fails.

3. If both email and phone match different existing guardians, mark the row as failed with reason: "Conflicting match: email belongs to {Guardian A}, phone belongs to {Guardian B}. Resolve manually."

If a single existing guardian is matched, treat as Case B (existing). Otherwise, treat as Case A (new).

#### Step 4a: Case A — Guardian Does Not Exist
Create new records:

1. Create a `User` record:
   - Email: from row (if provided) OR a generated placeholder like `phone+{phone}@no-email.local` (flag for admin attention later).
   - Generate a secure random password.
   - Set `email_verified_at` to null (requires verification on first login if can_login is enabled).

2. Assign the `parent` role to the user.

3. Create the `Guardian` record with ALL fields from the row that map to guardian columns:
   - Names: first_name, middle_name, last_name
   - Demographics: gender, marital_status
   - Contact: phone, whatsapp_number, emergency_contact
   - Address: city, state, country, postal_code
   - Employment: occupation, employer_name
   - ID: id_type, id_number, id_expiry_date
   - Status: status (default 'active' if not provided)
   - Photo: `photo_id` is left null. Admins can attach a photo later via the guardian profile edit page.

4. Attach the guardian to the student via the pivot:
   - `relationship` from row
   - `is_primary` from row
   - `can_login` from row (default false)

5. If `can_login = true`:
   - Send invitation/credentials via the `preferred_contact_channel` (default email if available, else SMS).
   - Log notification dispatch.

6. Log the action in audit log as `created` and `attached`.

#### Step 4b: Case B — Guardian Already Exists
Do NOT create new Guardian or User records. Instead:

1. Check if the existing guardian is already linked to the located student (query the pivot table by `guardian_id` and `student_id`).

2. **If already linked:**
   - Skip the row.
   - Mark as "skipped — already linked" in the results (not a failure, just informational).
   - Optionally update pivot fields (relationship, is_primary, can_login) ONLY if a query parameter `update_existing_links=true` is passed to the import endpoint. Otherwise leave the existing link untouched.
   - Do NOT update the guardian's own details from the import (name, address, etc.) under any circumstance — bulk import is not the right tool for editing existing guardians. Admins should use the guardian profile page for that.

3. **If not linked:**
   - Attach the guardian to the student via the pivot using `relationship`, `is_primary`, `can_login` from the row.
   - If the row sets `can_login = true` and the guardian's user account currently has login disabled (or no user account at all), promote them using the existing promote-to-login logic, and send credentials via the row's `preferred_contact_channel`.
   - Log the action as `attached` (and `login_enabled` if applicable).

#### Step 5: Primary Guardian Enforcement
After linking, check the student's guardian links:
- If `is_primary = true` in the row, set all OTHER pivot rows for this student to `is_primary = false` (only one primary per student).
- If `is_primary = false` in the row AND the student now has no primary guardian, flag the row with a warning: "Student now has no primary guardian. Set one manually."

### 3. Validation Rules

Implement as a dedicated Form Request or import validator class:

Required:
- `admission_number`: required, must exist in students table within current school.
- `first_name`, `last_name`, `phone`: required, non-empty strings.
- `phone`: valid phone format (use existing phone validation rules).
- `relationship`: must be in allowed enum.
- `is_primary`: parse as boolean (accept yes/no, true/false, 1/0).

Optional, format-checked when present:
- `middle_name`: string.
- `email`: valid email format.
- `gender`: must be in allowed enum (male/female/other).
- `marital_status`: must be in allowed enum.
- `whatsapp_number`: valid phone format.
- `emergency_contact`: valid phone format.
- `city`, `state`, `country`, `postal_code`: strings.
- `occupation`, `employer_name`: strings.
- `id_type`: must be in allowed enum (national_id/passport/drivers_license).
- `id_number`: string (when id_type is provided, id_number must also be provided).
- `id_expiry_date`: valid date.
- `status`: must be in allowed enum (active/inactive/blocked), defaults to active.
- `can_login`: parse as boolean, default false.
- `preferred_contact_channel`: must be in allowed enum (email/sms/whatsapp), defaults to email if email is provided, else sms.

### 4. Batch Processing

For files larger than 50 rows:
- Queue the import as a background job.
- Process rows in chunks of 100.
- Each chunk runs inside its own database transaction — if a row fails, only that row fails; other rows in the chunk continue.
- Update progress (rows processed, succeeded, failed, skipped) on the job record after each chunk.

For files with 50 or fewer rows:
- Process synchronously and return results immediately.

### 5. Result Report

After processing completes, generate a downloadable result report (Excel file) with these columns:
- All original columns from the import file
- `import_status` — success / failed / skipped
- `import_message` — reason for failure or skip, or the action taken (created, linked, login_enabled, etc.)
- `guardian_id` — the resulting guardian ID (for traceability)

The report should be available via `GET /api/guardians/import/{jobId}/report`.

Also send an email to the admin who initiated the import with:
- Summary: "{X} succeeded, {Y} failed, {Z} skipped"
- Link to download the full report
- Link to the guardian index page filtered to show newly created guardians
- Reminder: "Photos are not included in bulk import. Add guardian photos individually via the guardian profile page."

### 6. Idempotency & Re-runnability

The import should be safely re-runnable:
- Re-importing the same file should produce mostly "skipped — already linked" results, not duplicates.
- A guardian matched by phone or email is never duplicated, even across multiple import runs.
- Admins can re-upload a corrected file after fixing data issues without worrying about creating duplicates.

### 7. Edge Cases to Handle

- **Same guardian appearing in multiple rows of the same file** (e.g., one parent listed for two siblings): the first occurrence creates/matches the guardian, subsequent occurrences treat as Case B and only attach to the additional students. The guardian's optional details (address, occupation, etc.) are taken from the FIRST occurrence; subsequent rows ignore those fields for that guardian.
- **Phone number formatting differences** (e.g., `+2348000000000` vs `08000000000`): normalize phone numbers before comparison (strip spaces, dashes, normalize country code).
- **Email case sensitivity**: lowercase emails before comparison and storage.
- **Multiple guardians marked is_primary for the same student in the same file**: only the LAST row wins; emit a warning for the overridden rows.
- **Student exists but is soft-deleted**: treat as not found, mark row as failed.
- **Guardian exists but is soft-deleted**: treat as not existing — create a new guardian (do NOT auto-restore soft-deleted records via import).
- **id_type provided without id_number (or vice versa)**: fail the row with a clear message — these two fields are coupled.
- **Date parsing**: accept YYYY-MM-DD; reject other formats with a clear message to enforce consistency.

### 8. Permissions

- `guardian.import` — required to access the import endpoint.
- Assign to admin roles by default. Registrars do not get this by default — bulk import is an admin-only operation.

### 9. UI

Build a simple import page at `/guardians/import`:

- File upload area (drag-and-drop + click to browse).
- "Download Template" button — fetches the template with all supported columns and a sample row.
- Link to a column reference: "View column descriptions" — opens a modal or side panel listing every column, whether it's required, the format, and example values.
- Informational note near the upload area: "📷 Photos are not included in bulk import. Add guardian photos individually via the guardian profile page after import."
- Checkbox: "Update existing student-guardian links if found" (controls the `update_existing_links` parameter).
- After upload, show progress with a spinner and live status: "Processing row 234 of 1,200..."
- On completion, show summary card with counts and download links for the report.
- Show recent imports below: last 10 imports with date, file name, summary stats, and report download.

### 10. Tests

Feature tests covering:
- Import a clean file with all new guardians and all optional columns populated — verify every field is persisted on the Guardian record.
- Import a file where a guardian (by email) already exists — verify no duplicate, only pivot attached, and existing guardian details are NOT overwritten.
- Import a file where a guardian (by phone) already exists — verify no duplicate.
- Import a file where guardian is already linked to the student — verify skipped, no changes.
- Import with `update_existing_links=true` and existing link — verify pivot updates but guardian details remain unchanged.
- Import a file with conflicting email/phone matches — verify row marked failed with correct reason.
- Import a file with invalid admission number — verify row marked failed.
- Import a file with multiple rows for the same guardian linking to siblings — verify single guardian, multiple pivot rows, optional details taken from first row.
- Import a file with `can_login = true` for a new guardian — verify user account creation and credential dispatch on the correct channel.
- Import a file with `can_login = true` for an existing guardian who had no login — verify promotion to login.
- Import enforces single primary guardian per student.
- Phone number normalization works across format variations.
- All created guardians have `photo_id` set to null (verifying no photo handling during import).
- id_type without id_number causes row failure.
- Invalid date format on id_expiry_date causes row failure.
- Soft-deleted student causes row failure.
- Large file (>50 rows) is queued and processed in chunks.
- Re-running the same import produces zero new records.
- Result report contains accurate per-row status.
- Permissions block non-admin users from importing.

## Constraints & Notes

- Use the existing Excel import library (Maatwebsite/Laravel-Excel or similar — detect from composer.json).
- Reuse the existing registration service for guardian creation; do NOT duplicate creation logic in the importer. The importer is a thin wrapper around the same service the registration form uses.
- All operations must be scoped to the current `school_id` (multi-tenancy).
- Use database transactions per chunk; do NOT wrap the entire file in a single transaction (would lose partial progress on failure).
- Audit log entries must be created for every guardian created, attached, or promoted to login during the import.
- Phone normalization helper should be a shared utility used by registration, import, and lookup endpoints to ensure consistent matching across the system.
- Do not block the HTTP request for large imports — always queue.
- Keep the import column list in sync with the Add Guardian form (excluding photo). When non-photo fields are added to the form, update the import template, validator, and creation logic together.
- Photos are intentionally out of scope for bulk import. The guardian profile page is the only place to add or update guardian photos.