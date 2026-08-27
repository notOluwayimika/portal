# Drive — the `scholarships.kind` writer on the Scholarships tab

Branch `feat/scholarship-kind-writer` at `3086811`, driven 2026-08-27 on the throwaway
`APP_ENV=drive` instance (port 8001, `pnpm run build` first, `localhost` and never
`127.0.0.1`). Never the dev database, never a production copy.

**Why this drive exists.** The acceptance suite on that commit proves the HTTP contract —
`kind` required on create, `sometimes` on update, 422 on a bad value, the classification
persisted and audited, and the end-to-end unblock of `AwardStudentDiscount`. It is
structurally blind to everything the operator actually sees: whether an unconfigured row
LOOKS unconfigured, whether the two labels say what the schemes DO, whether a classification
that renders was ever persisted, and whether the 422 the tests assert reaches a person as
anything usable. Those are the observations below.

**A drive observes and does not fix.** Three findings are recorded in § 6 unfixed. The
fixture work in § 1 is the skill's named exception — a precondition of the drive, not a
finding from it.

Privacy: `user#<id>` / `school#<id>`, ids and counts. `Drive School A`, `Admin Drive` and
`BSS`/`C2C` in the screenshots are seeded fixture strings, not people or real schemes.

---

## 1. The fixture could not reach this screen's state, and now can

`DriveCastSeeder`, `SeedDriveFixture` and `DriveFinanceStates` contained the string
`scholarship` **zero times** — re-derived, not carried:

```
scholarship hits: database/seeders/DriveCastSeeder.php:0
                  app/Finance/Console/DriveFinanceStates.php:0
                  app/Console/Commands/SeedDriveFixture.php:0
```

So the tab would have opened onto an empty list and proved nothing. Per the skill, the
fixture needed the state before the drive needed a browser.

**What was added:** `DriveCastSeeder::seedScholarships()`, two rows per school — `BSS` and
`C2C` — **both with `kind` NULL**, which is the shape production actually has (the local
production copy holds two scholarships, both NULL).

**It writes the row directly, and that is the skill's `Payments (migrated)` exemption, not a
shortcut.** NULL is a state that exists in production — `2026_08_26_100000` backfilled every
row to it deliberately — and that **no current code path can create**, because `3086811`
makes `kind` REQUIRED on create. Routing this through the endpoint would mint two
*classified* scholarships and destroy the only state this screen acts on, while making the
fixture look more principled than it was. That argument is written at the write site in
`DriveCastSeeder`, in those terms, and the skill's "every state is produced by executing the
real Actions" sentence has been corrected to name the exception rather than be quietly false.

**No students were seeded onto them.** Surveyed first, as the brief asked: the tab renders a
name, a kind control and row actions. It does not count holders, does not list students and
does not read `students.scholarship_id` anywhere. Holders would matter only to `destroy()`,
which this drive does not exercise — named in § 7 rather than half-staged.

**Names are identical across the two schools**, exactly as `First Term` and `JSS 1` already
are. A screen showing "BSS" therefore proves nothing about which school's row it is, which
forces the isolation check onto ids and uuids — see § 5.

### The count columns, and the skill's enumeration corrected

The tab depends on something no column counted, so two were added to table 2:
**`Scholarships`** and **`Scholarships (unconfigured)`**. The split is the point, for the
reason `Payments (portal)/(migrated)` is split: the tab's only actionable state is
`kind IS NULL`, and a single total would read as coverage on a fixture whose rows were all
already classified — which is exactly what the fixture looks like after one drive has run.

**Read against the skill's stated enumeration, both numbers there were already wrong**, which
is the step the skill says has been missed twice and caught twice. Measured from the actual
command output:

| | skill said | actually is |
| --- | --- | --- |
| Table 1 (bulk-run slot) | thirteen columns | **fifteen** — `Decided credit notes` and `Decided voids` were never added to the list |
| Table 2 (guardians slot) | twelve columns | **fourteen** — twelve, plus the two added here |

The skill's enumeration and its "the column list grows" paragraph have both been updated,
and the miss count in it raised from twice to three times.

### Both tables, verbatim from a fresh seed

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable; the decisions surface (U13/U14) reads back what a checker has already settled:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Active schedules | Cohort at slot | Unplaceable | Decided credit notes | Decided voids |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 1                 | 5                 | 0                   | 2                     | 8             | 1                | 2              | 9           | 2                    | 1             |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 0                     | 1             | 1                | 2              | 1           | 0                    | 0             |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.

Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the guardians screen links a new guardian to students by admission number; the Scholarships tab classifies an UNCONFIGURED scholarship:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Students | Guardians | Scholarships | Scholarships (unconfigured) |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
| A (school#1) | 2                 | 2     | 2            | 2             | 1                 | 5                 | 0                   | 2                     | 8             | 12       | 0         | 2            | 2                           |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 0                     | 1             | 3        | 0         | 2            | 2                           |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+--------------+-----------------------------+
  School A (school#1) admission numbers: ADM61729, ADM47183, ADM14565, ADM19353, ADM95252, ADM62871, ADM60855, ADM53390, ADM66061, ADM46214, ADM23845, ADM16441
  School B (school#2) admission numbers: ADM19578, ADM36175, ADM11244
```

`Scholarships (unconfigured)` reads **2 / 2** in both schools: there is something to
classify, and the drive is worth starting. The zeros present are the two the skill exempts
(`Payments (migrated)`, `Guardians`) plus School B's payment columns, which are zero by
construction and irrelevant to this screen.

---

## 2. The route, and how the tab is reached

`/setup` — `Route::inertia('setup', 'admin/setup')` (`routes/web.php:528`), inside
`Route::middleware(['auth', 'tenant', 'permission:admin_area.access'])` (`routes/web.php:515`).

**The tab is NOT URL-addressable.** `setup.tsx:425` holds it in `useState<TabId>('overview')`
with no query-string or hash binding, so there is no `/setup?tab=scholarships`; the tab is
reached by clicking the `Scholarships` button in the tab strip, which is what the harness
did. Navigated directly to `/setup` — `/dashboard` is fine for the admin seats (it 403s only
for the finance seats, the pre-existing friction the skill documents, and § 5 shows it firing
for `maker@drive.test`).

**Two different permissions gate this screen, and only one of them is the tab's.** The page
is `admin_area.access`; every write the tab makes is `academic_setup.manage`
(`routes/api.php:52`, group closing at `:221`). Both were measured per seat rather than
assumed:

```
maker@drive.test    user#2 school#1 roles=accounts_officer admin_area.access=N academic_setup.manage=N
admin@drive.test    user#7 school#1 roles=admin           admin_area.access=Y academic_setup.manage=Y
admin-b@drive.test  user#8 school#2 roles=admin           admin_area.access=Y academic_setup.manage=Y
```

---

## 3. Seat 1 — `admin@drive.test` (user#7, school#1)

Signed in, landed on `/dashboard` (200), navigated to `/setup`, clicked the tab button whose
text is `Scholarships`.

### Observation 1 — an unconfigured row LOOKS unconfigured

Read out of the DOM. **Values, not labels**, and the whole option list per row:

```
head: ["Name", "Who pays", "Actions"]

row 1  name: "BSS"
       select.value: ""            (empty string — the unselected state)
       options: ["|Not configured — choose one [disabled]",
                 "discount|Discount — the school reduces the bill",
                 "sponsored|Sponsored — someone outside pays"]
       detail: "Nobody has said which scheme this is. Students on it cannot be billed and
                cannot be given a discount until you choose."

row 2  name: "C2C"
       select.value: ""
       options: (identical to row 1)
       detail: (identical to row 1)
```

**Establishes:** the NULL renders as a named state with its consequence spelled out, not as a
blank cell and not as a silent default. The placeholder option is `[disabled]`, so NULL is
not a destination the operator can select back into. Screenshot:
`admin-01-both-unconfigured.png`.

**The base-radio problem does NOT recur here.** The discount-base drive found radios carrying
`value="on"` with no `data-value`
(`docs/handoff/tickets/the-base-radios-have-no-machine-readable-value.md`). This control is a
`<select>` whose options carry the literal wire values `discount` and `sponsored`, read
straight off `option.value` above — machine-readable without a workaround, and confirmed
against the wire in observation 3.

### Observation 2 — the labels say what the schemes DO

Exact strings, pasted:

- `Discount — the school reduces the bill`
  · detail when selected: `The family still gets a bill, for less. These students are invoiced by the termly run.`
- `Sponsored — someone outside pays`
  · detail when selected: `An outside organisation pays, off platform. The family is not billed at all, and these students are left out of the termly run.`

**Establishes:** a reader who has never seen the enum can tell from the screen that one means
a smaller bill to the family and the other means no bill to the family. Neither the string
`discount` nor `sponsored` is rendered anywhere on its own.

### Observation 3 — classify one, read the wire, reload

The list before anything was touched:

```
GET /api/scholarships -> 200
{"data":[{"id":1,"uuid":"a29afe23-aa5e-4eae-a3b2-221db87a8e6b","name":"BSS","kind":null},
         {"id":2,"uuid":"a29afe23-ad50-451f-a233-d239c76cd447","name":"C2C","kind":null}]}
```

Row 1 set to `discount`, row 2 to `sponsored`. Off the wire:

```
PUT /api/scholarships/a29afe23-aa5e-4eae-a3b2-221db87a8e6b  {"name":"BSS","kind":"discount"}
  -> 200 {"data":{"id":1,…,"name":"BSS","kind":"discount"}}
PUT /api/scholarships/a29afe23-ad50-451f-a233-d239c76cd447  {"name":"C2C","kind":"sponsored"}
  -> 200 {"data":{"id":2,…,"name":"C2C","kind":"sponsored"}}
```

**Then a full page reload**, because a value rendered from local state is indistinguishable
from a persisted one until the page is refreshed:

```
row 1  select.value: "discount"   detail: "The family still gets a bill, for less. …"
row 2  select.value: "sponsored"  detail: "An outside organisation pays, off platform. …"

GET /api/scholarships -> 200
{"data":[{"id":1,…,"name":"BSS","kind":"discount"},{"id":2,…,"name":"C2C","kind":"sponsored"}]}
```

**Establishes:** the control is BOUND — the value the operator picked is what crosses the
wire, is what the server stored, and is what the screen re-reads from a cold fetch.
Screenshots `admin-02-row1-discount-toast.png`, `admin-03-after-reload-both-classified.png`.

### Observation 4 — both values exercised

`discount` on row 1 and `sponsored` on row 2, in the same session, each read back
independently after the reload above. **Establishes:** a control that wrote one constant
could not produce this pair. The PUT bodies differ in exactly the `kind` field.

### Observation 5 — a name-only edit does not unclassify

Row 1 (already `discount`) renamed through the inline pencil control to
`BSS (renamed by drive)`:

```
PUT /api/scholarships/a29afe23-aa5e-4eae-a3b2-221db87a8e6b  {"name":"BSS (renamed by drive)"}
  -> 200 {"data":{"id":1,…,"name":"BSS (renamed by drive)","kind":"discount"}}
```

After reload:

```
row 1  name: "BSS (renamed by drive)"  select.value: "discount"
row 2  name: "C2C"                     select.value: "sponsored"
```

**Establishes:** test arm (v) rendered. The inline edit sends `{name}` with **no `kind` key
at all** — visible in the body above — and the row comes back still `discount`. An
unconditional `only('name','kind')` write on the server, or a form that echoed a stale
`kind`, would show here.

### Observation 6 — create without a kind: what the SCREEN does

Modal opened, nothing typed:

```
select.value: ""
options: ["|Choose one… [disabled]",
          "discount|Discount — the school reduces the bill",
          "sponsored|Sponsored — someone outside pays"]
helper:  "Required. A scholarship with no answer here cannot be billed and cannot carry a discount."
Save disabled: true
```

Name typed (`Drive Create No Kind`), no kind, **Save clicked for real**:

```
Save disabled: true       (the click did nothing)
rows still: 2             (nothing was created)
no request was made
```

Screenshot `admin-05-create-no-kind-save-disabled.png` — Save is visibly greyed.

**Establishes, and this is the observation only a drive can make:** the operator is stopped,
and told why in prose beside the control, **before** a request is sent. But it also
establishes the limit — see finding **F1** in § 6: the 422 the tests assert is never reached
through this form, so the question "does the message arrive as a usable field error" has no
answer on this path, because the path does not exist.

The server refusal itself, confirmed from the page's own origin so the session and Referer
are real:

```
POST /api/scholarships {"name":"Drive Create No Kind"}
  -> 422 {"message":"There are validation errors","errors":{"kind":["The kind field is required."]}}
POST /api/scholarships {"name":"X","kind":"nonsense"}
  -> 422 {"message":"There are validation errors","errors":{"kind":["The selected kind is invalid."]}}
```

**Establishes:** the control is on the server, not only in the disabled button. The 500 that
`3086811` fixed is gone — these are 422s with a populated `errors.kind`.

### Observation 7 — a refusal the operator CAN reach

Create `C2C` (a duplicate) with `kind: discount`, through the form:

```
POST /api/scholarships {"name":"C2C","kind":"discount"}  -> 409 {"error":"Scholarship with this name already exists"}
screen: toast reading  Scholarship with this name already exists
```

**Establishes:** the tab's `apiMessage()` helper does surface a server-authored message
rather than a generic string — so the machinery for showing a 422's `message` exists and
works; it is only the disabled button that keeps a 422 from ever reaching it. Screenshot
`admin-06-duplicate-name-toast.png`.

---

## 4. Seat 2 — `admin-b@drive.test` (user#8, school#2) · isolation

Own browser context, own cookie jar. Both seats side by side, **ids visible**:

```
Seat 1 — admin@drive.test (school#1)
  GET /api/scholarships (before any edit)
    id=1  uuid=a29afe23-aa5e-4eae-a3b2-221db87a8e6b  name="BSS"  kind=null
    id=2  uuid=a29afe23-ad50-451f-a233-d239c76cd447  name="C2C"  kind=null

Seat 2 — admin-b@drive.test (school#2)
  GET /api/scholarships
    id=3  uuid=a29afe23-adf7-46ad-8909-f0d6c91586fb  name="BSS"  kind=null
    id=4  uuid=a29afe23-aea0-40da-bd4f-216a1e8d4b40  name="C2C"  kind=null

  DOM: row "BSS"  select.value ""   row "C2C"  select.value ""
```

Ids `1,2` against `3,4`; four distinct uuids; **`BSS` and `C2C` match character for
character across the two schools**, which is why the check is done on the ids. School B's
rows are still both NULL after School A classified both of its own — School A's edits are
absent from School B's list. Screenshot `isolation-01-school-b-list.png`.

Reaching directly for School A's row by uuid, as School B:

```
PUT /api/scholarships/a29afe23-aa5e-4eae-a3b2-221db87a8e6b  {"name":"hijack","kind":"sponsored"}
  -> 404 {"message":"Resource not found"}
```

**Establishes:** the `SchoolScope` on the route binding refuses a cross-school uuid at
resolution, and answers 404 rather than 403 — it does not confirm the row exists.

---

## 5. Seat 3 — `maker@drive.test` (user#2, school#1) · the refusal

`accounts_officer`. Holds **neither** `admin_area.access` **nor** `academic_setup.manage`, so
both gates fire and they fire in different places:

```
GET /dashboard -> 403     (the pre-existing finance-seat friction the skill documents)
GET /setup     -> 403     title "Forbidden"
                 page text: "403 | User does not have the right permissions."
```

Screenshot `refusal-01-maker-setup.png`: a **bare 403 page** — no application shell, no
sidebar, no route back. See finding **F2**.

The API gate, exercised from a page this seat CAN open (`/finance`, 200), so the session
cookie and same-origin Referer are real:

```
GET  /api/scholarships -> 403  "User does not have the right permissions."
                               (Spatie\Permission\Exceptions\UnauthorizedException)
POST /api/scholarships -> 403  "User does not have the right permissions."
```

**Establishes:** every observation in § 3 is made through a gate that actually refuses. Two
independent gates, both live — the page's `admin_area.access` and the API's
`academic_setup.manage`. Screenshot `refusal-02-maker-finance.png`.

**Seats deliberately not driven:** `checker@drive.test`, `void-checker@drive.test` and
`super@drive.test`. This screen has no approval step by design — the reasoning is written at
`ScholarshipController::update()` — so there is no checker side for the first two to prove,
and `super@drive.test` would only re-prove a bypass that is about authorization and has no
maker-checker pair here to be excluded from.

---

## 6. Findings — observed, NOT fixed

**F1 — the 422 this commit created cannot reach the operator through the form.**
`Save` is `disabled` whenever `kind === ''`, so the only path to `POST /api/scholarships`
with a missing `kind` is a client that is not this screen. The server's 422 carries a usable
`errors.kind` (`"The kind field is required."`) and the tab's `apiMessage()` helper is
demonstrably capable of rendering a server message (§ 3, observation 7) — but nothing wires
the two together, because the request is never sent. Two consequences worth separating: the
operator IS stopped and IS told why (the helper text), so this is not a hole in the control;
and the tab has **no field-level error rendering at all** — every failure it can show is a
toast. If a future rule 422s on something the disabled button cannot pre-empt (a name
collision moving to a unique index, a `max:255`), that error will arrive as a toast with no
field attached. **Not fixed. Not obviously wrong.** It is a design question about this
screen, and it is Segun's.

**F2 — `/setup` refuses with a bare 403 page, not a graceful refusal.**
`refusal-01-maker-setup.png` is an unstyled `403 | User does not have the right permissions.`
with no shell and no way back. **Pre-existing and not introduced by `3086811`** — it is the
`admin_area.access` middleware on the whole `/setup` group, the same family as the documented
`/dashboard` 403 friction. Recorded because the brief asks for it and because a bare 403 and
a graceful refusal are different products.

**F3 — the tab logs raw axios errors to the browser console.**
`console.log: JSHandle@error` appears once in the seat-1 log, from the `catch (error) {
console.log(error) }` blocks the file already had before this commit. Harmless in the drive;
it is a whole axios error object, which on other screens is how a payload ends up in a
console someone is screen-sharing. Pre-existing pattern, not this commit's.

---

## 7. What was NOT driven

**The end-to-end unblock — the point of the commit — is NOT rendered anywhere.**
`AwardStudentDiscount` has no HTTP caller (`docs/handoff/tickets/award-student-discount-has-no-caller-and-therefore-no-gate.md`),
so "setting `kind = discount` makes an award possible" cannot be shown in a browser. It is
proven by test arm (vii) in `tests/Feature/Academics/ScholarshipKindWriterTest.php` — the
same award refused before the PUT and accepted after — and **by test only**. No screen was
seen doing it, and none exists to see.

**The bulk-run consequence of `sponsored` is likewise unrendered here.** That a `sponsored`
scholarship excludes its holders from `ProcessBulkInvoiceRun` is the reason this screen
matters; it is covered by `ScholarshipKindAndRunExclusionTest` and by the bulk-run screen's
own drive, not by this one. The two schemes were classified on scholarships with **no
students on them**, deliberately (§ 1).

**`destroy()` was not exercised**, on a row with holders or without. `students.scholarship_id`
is an FK onto `scholarships.id`; what the screen does when a delete is refused by the
database is unobserved, and the fixture was deliberately not half-staged for it.

**The audit entry was not read through the Activity Log screen.** The commit adds
`LogsActivity` to `Scholarship` with `useLogName('academics')`, and the test asserts the row,
both sides of the change and the causer. Whether `academics` — a bucket that has never had a
row in it until now
(`docs/handoff/tickets/model-log-name-is-declared-as-a-static-property-spatie-never-reads.md`)
— appears in the Activity Log screen's log-name filter is unobserved.

**Re-classification back to NULL was not attempted through the UI**, because the screen does
not offer it: the placeholder option is `[disabled]`. The server refusal of a null `kind` is
covered by `Rule::enum` and is not rendered.

---

## 8. Console

Every 4xx below was caused deliberately by this drive; there is nothing else.

```
[admin]   HTTP 422 POST /api/scholarships                 (observation 6, twice)
[admin]   HTTP 409 POST /api/scholarships                 (observation 7)
[admin]   console.log: JSHandle@error                     (finding F3)
[admin-b] console.info: Slow network … Fallback font …    (bunny.net webfont, x3)
[admin-b] HTTP 404 PUT /api/scholarships/<school#1 uuid>  (§ 4, cross-school probe)
[maker]   HTTP 403 GET /dashboard                         (pre-existing friction)
[maker]   HTTP 403 GET /setup                             (§ 5)
[maker]   HTTP 403 GET /api/scholarships                  (§ 5)
[maker]   HTTP 403 POST /api/scholarships                 (§ 5)
```

No `pageerror`, no uncaught exception, no `Cannot read properties of undefined` on any seat.

---

## 9. Screenshots

| File | What it shows |
| --- | --- |
| `admin-01-both-unconfigured.png` | Both rows reading `Not configured — choose one` with the consequence sentence |
| `admin-02-row1-discount-toast.png` | Row 1 just set to `discount` |
| `admin-03-after-reload-both-classified.png` | After a full reload — `discount` and `sponsored` read back from the server |
| `admin-04-name-only-edit-kind-survives.png` | Renamed row still classified `discount` |
| `admin-05-create-no-kind-save-disabled.png` | Create modal, name typed, no kind, Save greyed out |
| `admin-06-duplicate-name-toast.png` | The 409 surfacing as the server's own sentence |
| `isolation-01-school-b-list.png` | School B's two rows, still unconfigured |
| `refusal-01-maker-setup.png` | The bare 403 page (finding F2) |
| `refusal-02-maker-finance.png` | `maker@drive.test` on a page it can open, from which the API 403s were made |

Harness: `puppeteer-core` against system Chrome, installed **outside the repository** in the
session scratchpad, not committed.
