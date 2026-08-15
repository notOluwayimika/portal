# U8 commit 4 — the invoice modal can cite a discount policy

**Branch:** `feat/u8-invoice-modal-discount-policy`
**Branched from:** `origin/staging` @ **`9fa55a73462a75b8119db92ee7a5c2239cfba55c`**
(`9fa55a7 Merge pull request #249 from notOluwayimika/fix/u8-reduction-guard-field-errors`;
`git fetch origin` first, `origin/staging` had moved one merge past the previous branch head)
**Commit:** `7894086`
**Shape:** 2 frontend files, 1 test file, 1 ticket, 6 screenshots. One commit. No migration, no
RBAC change, no route change, no server-side behaviour change.

---

## Deviations, first

**1. Both Pest arms the brief asked for already existed, verbatim, from U8 commit 3.** I did not
write them, because writing them again would have been a second copy of a live test:

- `tests/Feature/Finance/ReductionPreCheckTest.php:422` (pre-commit numbering) —
  `student route — an ACTIVE, no-approval policy still generates the invoice` — posts to
  `POST /v1/finance/students/{uuid}/invoices` and asserts
  `expect((int) $reduction->discount_policy_id)->toBe($policy->id)`. That is the brief's first
  requested arm exactly.
- `:351` — `student route — arm 3: a reduction citing a requires_approval policy is a field error
  on that line`. That is the brief's second requested arm exactly.

Both are exercised as watched reds below rather than duplicated. What I added instead is the
coverage those two do **not** give, described in §3.

**2. The policy select is a native `<select>`, not the Radix `Select` the Kind column uses.**
Radix's `SelectItem` cannot take `value=""`, and the unselected state is a designed path here (it
posts `""`, which the server reads as no provenance). The shape follows
`resources/js/pages/admin/finance/fee-schedules.tsx:903-925`, the in-repo precedent for a
"choose one, or leave empty" select in the Finance UI. The modal therefore now contains two select
idioms. Recorded rather than resolved.

**3. I corrected a comment in a test I did not otherwise need to touch.**
`ReductionPreCheckTest.php:321-323` said "new-invoice-modal.tsx:135-138 sends description,
amount_minor and kind, and no discount_policy_id at all". This commit makes that false. The comment
is rewritten to say what is now true and why the arm below it still exists; the line-number citations
are deliberately not replaced with new ones, since the old ones went stale inside one commit.

**4. `SELECT_CLASS` is now defined in three files.** `fee-schedules.tsx:133-134` and
`discount-policies.tsx` each carry an identical copy; this adds a third rather than extracting a
shared one, because extracting it would edit two files this commit has no other business in.

---

## 0. The ticket

`docs/handoff/tickets/no-javascript-test-runner.md`.

Confirmed myself, not taken from the brief:

```
$ node -e "const p=require('./package.json');console.log(Object.keys({...p.devDependencies,...p.dependencies}).filter(d=>/(^|[^a-z])(vitest|jest|mocha|karma|ava|tape|cypress|playwright|puppeteer|happy-dom|jsdom)([^a-z]|$)|testing-library/i.test(d)))"
[]

$ ls node_modules/.bin | grep -Ei 'vitest|jest|mocha|cypress|playwright|karma|ava|tape'
(no output)

$ ls | grep -Ei 'vitest|jest|cypress|playwright|karma'
(no output)

$ find resources/js -type f \( -name '*.test.*' -o -name '*.spec.*' \)
(no output)

$ node -e "console.log(Object.keys(require('./package.json').scripts))"
[ 'build', 'build:ssr', 'dev', 'format', 'format:check', 'lint', 'lint:check', 'types:check' ]
```

**63,177** lines of hand-written TS/TSX at `7894086`, the commit that ships the ticket — **corrected**
from the 62,912 first published here, which is the figure at `9fa55a7`, the branch point. A clean
checkout of either sha has no `resources/js/actions/` or `resources/js/routes/` at all (both are
wayfinder-generated and gitignored), so hand-written and total coincide there; the 99,220 first
quoted alongside was measured on a working tree where wayfinder had run, and is a property of that
tree rather than of any commit. Zero tests, no runner to run one, no `test` script for one to hang
off.

The ticket records what `bin/quality` has for frontend code (steps 3, 4, 5 and 9 — the other eleven
never read `resources/js`) and what each can and cannot catch, and names `errorLinesFrom`
(`new-invoice-modal.tsx:55-98` pre-commit) as the existing example: at least eight enumerated
behaviours in its own docblock, none of them asserted anywhere. No runner installed in this commit.

**A correction I made to my own first draft, since it is the kind of thing this ticket is about:**
the probe I first wrote reported `['@radix-ui/react-avatar']` — the pattern `ava` matched inside
`avatar`. The version above has boundaries. The same class of error as the substring-matching rule in
`finance-context`.

---

## 1. Premises, checked against the repo before building

| Brief's claim | Verdict | Evidence |
| --- | --- | --- |
| The modal offers `waiver`/`discount` but sends only description, amount_minor, kind | **True** | pre-commit `new-invoice-modal.tsx:202-206` (payload) and `:305-310` (the two reduction options in the kind select) |
| So every reduction the running UI can submit is refused | **True** | `ReductionPreCheckTest.php:320-335`'s own comment says the same, and its arm asserts the refusal |
| The wire takes a uuid | **True** | `GenerateInvoiceRequest.php` `lines.*.discount_policy_id` → `['sometimes','nullable','bail','string','uuid', <closure>]`; `lineSpecs()` resolves uuid → id |
| `DiscountPolicyResource` serialises `id` as the uuid, plus name/basis/value_minor/percent/requires_approval/status | **True** | `app/Finance/Http/Resources/DiscountPolicyResource.php:15-24` — `'id' => $this->uuid` |
| An active policy that requires per-application approval is still refused by the guard | **True** | `2026_07_26_140002_add_discount_policy_to_finance_lines.php:85-88` — a **separate** `IF v_requires = 1` arm, after the status arm at `:80-83`. Status alone is necessary and not sufficient |
| `discount-policies.tsx:169-180` is the fetch precedent | **True** | the `load` useCallback is `:162-178` and the `useEffect` that drives it is `:180-186` — **corrected** from `:162-177`, which closed before the callback did and excluded the effect entirely — `axios.get('/api/v1/finance/discount-policies')` inside a `useCallback`, `try/catch/finally`, driven from a `useEffect`. Its own comment says a caller wanting only the choosable ones asks `?status=active` |
| `DraftLine` at `types/finance.ts:254-258` | **True** | exact |
| `bin/ci-money-lint.php:42-43` puts `resources/js/components/finance/` in the strict zone | **True** | `:41-44`, `isFinanceUi()` |

The trigger has **five** arms, and the fifth is the one the kind transition can trip:
`IF BINARY NEW.kind = BINARY 'charge' AND NEW.discount_policy_id IS NOT NULL` → *"A charge line may
not reference a discount policy."* (`:96-99`).

Route access: `GET /v1/finance/discount-policies` carries no per-route middleware
(`routes/endpoints/finance.php:134`); the group at `routes/api.php:237` carries
`auth:sanctum, tenant, permission:finance.access`. Anyone who can open this modal already holds it.

---

## 2. What was built

**`resources/js/types/finance.ts`** — `DraftLine` gains `discountPolicyId: string` (required, not
nullable; `''` is the unselected state). Adds `SelectablePolicy`, a narrow projection of
`DiscountPolicyResource` — deliberately named differently from the wide `DiscountPolicy` type in
`discount-policies.tsx` so it does not read as a second, drifting copy of it.

**`resources/js/components/finance/new-invoice-modal.tsx`** — three exported pure functions plus the
fetch, the state and the render:

- `selectablePolicies()` — `status === 'active' && requires_approval !== true`. The docblock states
  in full that **both filters are convenience, not enforcement**, names the two things that actually
  refuse (the server pre-check, then the trigger), and says why a reader taking this for a guard is
  the failure mode. `status` is redundant with `?status=active` and kept so the function is total
  over whatever it is handed; `requires_approval` is redundant with nothing.
- `patchForKind(kind)` — `{ kind, discountPolicyId: '' }` for `charge`, `{ kind }` otherwise. The
  clear, not a hide.
- `wireLine(line, amountMinor)` — includes `discount_policy_id` only when `kind !== 'charge'`, and
  sends the value **as is** on a reduction, `''` included.
- `loadPolicies()` — `axios.get('/api/v1/finance/discount-policies', { params: { status: 'active' } })`,
  called from the same `isOpen` effect as `loadEnrollment`. Three distinguishable states: loading,
  loaded-and-empty, failed. A failed fetch is **not** rendered as an empty catalog, because "your
  school has no policies" is a different and possibly false statement.
- The select renders only when `line.kind !== 'charge'`. When there is nothing to choose, no select
  is rendered at all — the words are shown instead.

**No money arithmetic added.** Nothing computes a discount amount in the browser; the existing
`nairaToMinor` / `sumMinor` / `formatNaira` path is untouched. Money lint green (§5).

**No client-side blocking added.** An unselected policy still posts and is still refused by the
server, by design.

---

## 3. The Pest arm that was missing

Added to `ReductionPreCheckTest.php`:

**`student route — arm 1: the EMPTY-STRING policy the modal posts when nothing is picked`.** The
equivalent arm existed on the enrollment-id route (`:129`) and **not** on the student route, which is
the only one the UI posts to. The two shapes travel differently: an absent key and `""` are the same
outcome only because `ConvertEmptyStringsToNull` — a **global middleware** — rewrites the second, and
no arm on this route pinned that dependency. It also asserts the message is the arm-1 one and
**not** `is invalid`, since both refusals are 422s on the same field and only the text separates
"no provenance" from "malformed id".

**`student route — the ACCEPTED payload has the modal's exact shape, key for key`.** Posts exactly
what `wireLine()` emits — `discount_policy_id` present on the reduction line, **absent** on the
charge line — and asserts `$charge->discount_policy_id` is null and the reduction's is `$policy->id`.
The charge line's absent key is the observable end of the kind transition. This arm pins the payload
**shape** from the server's side; it cannot see whether the modal still produces it.

---

## 4. The kind-transition walkthrough

The brief's sequence: **charge → discount → pick a policy → back to charge → submit.**

### By hand, against the shipped functions

```
let line = { description: 'Tuition', amount: '1000', kind: 'charge', discountPolicyId: '' };
step(patchForKind('discount'))            → { …, kind: 'discount', discountPolicyId: '' }
step({ discountPolicyId: 'u-active-ok' }) → { …, kind: 'discount', discountPolicyId: 'u-active-ok' }
step(patchForKind('charge'))              → { …, kind: 'charge',   discountPolicyId: '' }   ← CLEARED
wireLine(line, 100000)                    → { description: 'Tuition', amount_minor: 100000, kind: 'charge' }
```

Both halves refuse the ride-along independently: `patchForKind` clears the state, and `wireLine`
omits the key on a charge regardless of what the state holds. Either alone would prevent the bug;
both are present because `DraftLine` is a plain object and a future edit path that sets
`discountPolicyId` without going through the kind select would otherwise put it on the wire.

### In the browser, the same sequence

From the drive (§6), `maker@drive.test`, School A:

```
  after Kind=Discount, row 0 policy VALUE  : ""
  after picking, row 0 policy VALUE        : "a282757e-f36d-4fce-b6ed-70afa0d694a0"
  after flipping back to Charge, select is : null   (null = not rendered)
  flipped to Discount AGAIN, policy VALUE  : ""     ← the id was CLEARED, not hidden
```

And the payload that was actually posted, on a run where line 0 had been through the flip:

```
{"lines":[{"description":"Tuition","amount_minor":100000,"kind":"charge"},
          {"description":"Sibling discount","amount_minor":-10000,"kind":"discount",
           "discount_policy_id":"a282757e-f36d-4fce-b6ed-70afa0d694a0"}]}
POST status: 201
```

Line 0 carries no `discount_policy_id`. If the id had ridden along, arm 5 would have answered 422.

---

## 5. Client-side measurement — and exactly what it is worth

**It is a one-off measurement. Nothing will catch a regression in it.** There is no JS test runner
(§0); this harness lives in a private temp directory, is not committed, and will not run again. It
tells you the functions were correct on 2026-08-15 and nothing about tomorrow.

**These are the real shipped functions, not re-implementations.** `selectablePolicies`,
`patchForKind` and `wireLine` are exported from the modal, bundled out of the real file by
`vite build --ssr` against the repository's own `@/` alias, and imported into the harness. A
re-typed copy would have measured the copy.

```
MATCH   selectablePolicies keeps only active + non-approval
           got:      ["u-active-ok"]
           expected: ["u-active-ok"]
MATCH   selectablePolicies on an empty catalog
           got:      []
           expected: []
MATCH   selectablePolicies when every policy requires approval
           got:      []
           expected: []
MATCH   selectablePolicies does not mutate its input
           got:      5
           expected: 5
MATCH   patchForKind("charge") clears the policy
           got:      {"kind":"charge","discountPolicyId":""}
           expected: {"kind":"charge","discountPolicyId":""}
MATCH   patchForKind("discount") does not touch the policy
           got:      {"kind":"discount"}
           expected: {"kind":"discount"}
MATCH   patchForKind("waiver") does not touch the policy
           got:      {"kind":"waiver"}
           expected: {"kind":"waiver"}
MATCH   wireLine on a charge line omits discount_policy_id entirely
           got:      {"description":"Tuition","amount_minor":100000,"kind":"charge"}
           expected: {"description":"Tuition","amount_minor":100000,"kind":"charge"}
MATCH   wireLine on a charge line omits a STALE policy too
           got:      {"description":"Tuition","amount_minor":100000,"kind":"charge"}
           expected: {"description":"Tuition","amount_minor":100000,"kind":"charge"}
MATCH   wireLine on a discount line sends the policy
           got:      {"description":"Tuition","amount_minor":-10000,"kind":"discount","discount_policy_id":"u-active-ok"}
           expected: {"description":"Tuition","amount_minor":-10000,"kind":"discount","discount_policy_id":"u-active-ok"}
MATCH   wireLine on a waiver line with NOTHING picked sends the empty string
           got:      {"description":"Tuition","amount_minor":-5000,"kind":"waiver","discount_policy_id":""}
           expected: {"description":"Tuition","amount_minor":-5000,"kind":"waiver","discount_policy_id":""}
MATCH     transition: after picking, the state HOLDS the policy
           got:      "u-active-ok"
           expected: "u-active-ok"
MATCH     transition: after flipping back, the state has CLEARED it
           got:      ""
           expected: ""
MATCH     transition: the submitted payload carries no policy
           got:      {"description":"Tuition","amount_minor":100000,"kind":"charge"}
           expected: {"description":"Tuition","amount_minor":100000,"kind":"charge"}

0 DIFFER, 14 MATCH
```

**Watched red on the harness itself**, because 14 MATCH from a harness nobody has seen fail proves
nothing. Planted: `patchForKind` stops clearing —
`return kind === 'charge' ? { kind, discountPolicyId: '' } : { kind };` → `return { kind };`.
Rebuilt, re-ran:

```
DIFFER  patchForKind("charge") clears the policy
           got:      {"kind":"charge"}
           expected: {"kind":"charge","discountPolicyId":""}
DIFFER    transition: after flipping back, the state has CLEARED it
           got:      "u-active-ok"
           expected: ""

2 DIFFER, 12 MATCH
```

That is the brief's bug, produced on demand and named by the harness. Restored from a copy held
outside the repository; `0 DIFFER, 14 MATCH` on the restored tree.

---

## 6. Watched reds — the Pest arms, raw

### Green, before any plant

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/ReductionPreCheckTest.php
{"tool":"pest","result":"passed","tests":22,"passed":22,"assertions":118,"duration_ms":20257}
```

### RED 1 — the student-route pre-check call site removed

Planted: `InvoiceController.php:114` (`generateForStudent`'s `$request->assertDiscountPoliciesUsable();`)
replaced with a comment. The **other** call site at `:39` (`generate`) left intact, so the red is
attributable to one route.

```
{"tool":"pest","result":"failed","tests":22,"passed":17,"assertions":102,"duration_ms":20807,"failed":5,
 "failures":[
  {"test":"…it_student_route_—_arm_1__a_reduction_with_NO_policy_is_a_field_error_on_that_line",
   "message":"Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'\n\nResponse does not have JSON validation errors.\nFailed asserting that an array has the key 'lines.1.discount_policy_id'."},
  {"test":"…it_student_route_—_arm_1__the_EMPTY_STRING_policy_the_modal_posts_when_nothing_is_picked",
   "message":"Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'\n\nResponse does not have JSON validation errors.\nFailed asserting that an array has the key 'lines.1.discount_policy_id'."},
  {"test":"…it_student_route_—_arm_2__a_reduction_citing_a_RETIRED_policy_is_a_field_error_on_that_line",
   "message":"Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'\n\nResponse does not have JSON validation errors.\nFailed asserting that an array has the key 'lines.1.discount_policy_id'."},
  {"test":"…it_student_route_—_arm_3__a_reduction_citing_a_requires__approval_policy_is_a_field_error_on_that_line",
   "message":"Failed to find a validation error in the response for key: 'lines.1.discount_policy_id'\n\nResponse does not have JSON validation errors.\nFailed asserting that an array has the key 'lines.1.discount_policy_id'."},
  {"test":"…it_student_route_—_arm_5__a_charge_line_carrying_a_discount_policy_is_a_field_error_on_that_line",
   "message":"Failed to find a validation error in the response for key: 'lines.0.discount_policy_id'\n\nResponse does not have JSON validation errors.\nFailed asserting that an array has the key 'lines.0.discount_policy_id'."}]}
```

Five red, **all** student-route; every enrollment-route arm stayed green. That includes the brief's
second requested arm (`requires_approval`, `arm 3`) and my new empty-string arm.

### RED 2 — `lineSpecs()` stops resolving the wire uuid

Planted: `GenerateInvoiceRequest.php:388` — the `discountPolicyId:` resolution replaced with
`discountPolicyId: null`. 11 red, 11 green. The two that matter here:

```
{"test":"…it_student_route_—_the_ACCEPTED_payload_has_the_modal’s_exact_shape__key_for_key",
 "message":"Expected response status code [201] but received 422.\nFailed asserting that 422 is identical to 201.\n\nThe following errors occurred during the last request:\n\n{\n    \"message\": \"There are validation errors\",\n    \"errors\": {\n        \"lines.1.discount_policy_id\": [\n            \"Select the discount policy that authorises this reduction. A reduction with no policy has to go through a credit note instead.\"\n        ]\n    }\n}"}

{"test":"…it_student_route_—_an_ACTIVE__no_approval_policy_still_generates_the_invoice",
 "message":"Expected response status code [201] but received 422.\nFailed asserting that 422 is identical to 201.\n\nThe following errors occurred during the last request:\n\n{\n    \"message\": \"There are validation errors\",\n    \"errors\": {\n        \"lines.1.discount_policy_id\": [\n            \"Select the discount policy that authorises this reduction. A reduction with no policy has to go through a credit note instead.\"\n        ]\n    }\n}"}
```

The second of those is the brief's **first** requested arm, reached and shown failing.

### Restored

```
$ git status --porcelain
 M resources/js/components/finance/new-invoice-modal.tsx
 M resources/js/types/finance.ts
 M tests/Feature/Finance/ReductionPreCheckTest.php
?? docs/handoff/tickets/no-javascript-test-runner.md

$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/ReductionPreCheckTest.php
{"tool":"pest","result":"passed","tests":22,"passed":22,"assertions":118,"duration_ms":20489}
```

Neither controller nor request file appears as modified — both plants restored from copies held
outside the repository.

---

## 7. The drive

Procedure per `.claude/skills/finance-drive/SKILL.md`. `APP_ENV=drive`, database `portal_drive`,
`php artisan serve --port=8001`, `pnpm run build` before the browser. Browser: system Chrome via
`puppeteer-core`, installed **outside** the repository (`node_modules` untouched).

### Redaction policy for this drive

Every log line below redacts the student as `<student>`. **The committed PNGs do not** — they show
the fixture's own generated names, which is not a disclosure (they are `Student::factory()` output on
a throwaway database, not real people) but is an inconsistency the report did not previously
declare. Declared now, with the subject of each shot named so the text and the images agree:

| Shot | Seat | Student |
| --- | --- | --- |
| `maker-01-policy-select-on-reduction.png` | `maker@drive.test` | `student#6` (school#1) |
| `maker-02-two-lines-policy-picked.png` | `maker@drive.test` | `student#6` (school#1) |
| `maker-03-after-submit.png` | `maker@drive.test` | `student#6` (school#1) |
| `maker-04-empty-catalog.png` | `maker@drive.test` | **`student#2` (school#1)** — a different student from the one the surrounding text describes, and the shot also carries the F7 "already has an active invoice" banner, which neither the text nor the filename mentioned |
| `isolation-01-school-b-policy-select.png` | `school-b@drive.test` | `student#7` (school#2) |
| `school-b-01-unselected-policy-refused.png` | `school-b@drive.test` | `student#7` (school#2) |

`maker-04` was taken by a separate script run after the policy was retired, and that script reused
`student#2` rather than `student#6`. The observation it supports — no `<select>` rendered, the words
shown instead — does not depend on which student, but the report implied continuity that did not
exist.

### The fixture count table, verbatim from the command

**On the padding.** A review read the block below as reflowed — borders at the original widths with
every data row's inner padding stripped. It is not reflowed; that is what the command prints. Re-run
with stdout redirected to a **file**, and again through a pseudo-TTY via `script(1)`, and again with
`COLUMNS=200`: `od -c` on the School A row is byte-identical in all three
(`|` `space` `A` `(school#1)` `space` `|` `space` `1` `space` `|` …), differing only by the `\r`
`script` adds. Both tables the command prints behave the same way. Why Symfony's table sizes its
borders from the widest cell and then emits cells unpadded, I did not determine. The block below is
the raw file capture.

```
Drive fixture seeded. Sign in at APP_URL with any user below (password: drive-password):
+--------------------------------------------+-------------------------+
| Role in the drive | Email |
+--------------------------------------------+-------------------------+
| Maker (accounts_officer) | maker@drive.test |
| Full checker (executive_director) | checker@drive.test |
| Void-only checker (no credit-note.approve) | void-checker@drive.test |
| Super admin | super@drive.test |
| School B bursar (isolation) | school-b@drive.test |
+--------------------------------------------+-------------------------+

Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy:
+--------------+-------------------+-------+--------------+---------------+-------------------+
| School | Academic sessions | Terms | Class levels | Bank accounts | Discount policies |
+--------------+-------------------+-------+--------------+---------------+-------------------+
| A (school#1) | 1 | 1 | 2 | 1 | 1 |
| B (school#2) | 1 | 1 | 2 | 1 | 1 |
+--------------+-------------------+-------+--------------+---------------+-------------------+
Statements: open /finance and click a student; the queue is /finance/approvals.
```

Discount policies: **1 per school, non-zero**, so the select has something behind it. Derived before
opening a browser: both are `status=active`, `requires_approval=0`, and their uuids are disjoint.

**Which student.** School A's `student#6` was chosen because its only invoice is `void` — every other
School A enrollment carries an `issued` invoice and would have hit the F7 refusal before the payload
could reach a 201. School B: `student#7`, the only active enrollment there.

### Seat 1 — `maker@drive.test` (accounts_officer, school#1)

```
  page title            : Statement — <student> - Laravel
  policy select on a CHARGE line (row 0): null

  after Kind=Discount, row 0 policy options: [
  '|Choose a policy…',
  'a282757e-f36d-4fce-b6ed-70afa0d694a0|Sibling discount'
]
  after Kind=Discount, row 0 policy VALUE  : ""

  ── transition: charge → discount → pick → charge → submit ──
  after picking, row 0 policy VALUE       : "a282757e-f36d-4fce-b6ed-70afa0d694a0"
  after flipping back to Charge, select is: null (null = not rendered)
  flipped to Discount AGAIN, policy VALUE  : "" ← "" means the id was CLEARED, not hidden

  POST body sent  : {"lines":[{"description":"Tuition","amount_minor":100000,"kind":"charge"},{"description":"Sibling discount","amount_minor":-10000,"kind":"discount","discount_policy_id":"a282757e-f36d-4fce-b6ed-70afa0d694a0"}]}
  POST status     : 201
  console          : [
  '[error] Failed to load resource: the server responded with a status of 403 (Forbidden)'
]
```

### Seat 2 — `school-b@drive.test` (accounts_officer, school#2) — isolation

```
  page title            : Statement — <student> - Laravel
  row 0 policy options  : [
  '|Choose a policy…',
  'a282757e-f774-4b83-98b2-53d1d708d543|Sibling discount'
]
  console               : [
  '[error] Failed to load resource: the server responded with a status of 403 (Forbidden)'
]
```

**Side by side, in the skill's `(n):` count-and-value form.** The counts are the lengths of the two
arrays pasted above — the same measurement, reformatted; the drive was not re-run to obtain them, and
the script that produced those arrays did not print a count of its own:

```
Seat 1 — `maker@drive.test` (accounts_officer, school#1)
  MODAL kind options    (3): ["charge|Charge","waiver|Waiver","discount|Discount"]
  MODAL policy options  (2): ["|Choose a policy…","a282757e-f36d-4fce-b6ed-70afa0d694a0|Sibling discount"]

Seat 2 — `school-b@drive.test` (isolation, school#2)
  MODAL policy options  (2): ["|Choose a policy…","a282757e-f774-4b83-98b2-53d1d708d543|Sibling discount"]
```

The kind options are read from the JSX rather than from the DOM — Radix renders its items into a
portal only while open, and the script did not enumerate them; they are listed for completeness and
are not a drive measurement. The placeholder's value is the empty string: `|Choose a policy…` is a
`|` with nothing to its left, the ellipsis belonging to the label.

**WHAT THIS ESTABLISHES, CORRECTED.** The first version of this report called it isolation. It is
not, and the count table two sections up says why: **Discount policies — A: 1, B: 1.** With exactly
one row per school, "seat 1 sees uuid X, seat 2 sees uuid Y, X ≠ Y" is bit-for-bit identical to what
a scope that SWAPPED the two schools would render — each seat still sees exactly one option, the
values are still disjoint, the labels are still identical. The absence half does not rescue it
either: under a swap, School A's row genuinely is absent from School B's list.

What these two logs support is **disjointness**, and nothing about **ownership**. The uuid↔school
mapping was derived from the database before the browser was opened and then not pasted, so the one
artifact that would have closed the gap is the one this report left out.

**What settles it is a Pest arm that predates this branch and that no drive report has cited:**
`tests/Feature/Finance/DiscountPoliciesScreenTest.php:130-145` — *"shows School B its OWN policies,
and none of School A's"*. It seeds distinctly named policies in two schools, gives School A **two**
and School B **one**, and asserts the returned ids are exactly `[$mine->uuid]`. Under a swap School
B's caller receives School A's two ids and the expectation fails on both count and value. The
asymmetry is what makes it able to see a swap; the drive fixture has none.

The gap in the procedure that produced this over-claim is written up in
`docs/handoff/tickets/drive-isolation-check-cannot-see-a-swap.md`.

### The empty catalog, as rendered

Produced by **retiring** School A's only policy through the real Actions — `SubmitDiscountPolicyChange`
(maker) then `ApproveDiscountPolicyChange` (checker), the same pair `DriveFinanceStates` uses to
create it — on the throwaway drive database. Confirmed first:
`a282757e-f36d-4fce-b6ed-70afa0d694a0 status=retired req=0`.

```
  [ GET 200 ] http://localhost:8001/api/v1/finance/discount-policies?status=active
  policy <select> present: false
  what is rendered there : "Discount policy This school has no active discount policy that can back a reduction. Have one authorised on the Discount policies screen, or raise this as a credit note instead — submitting without one will be refused."
  console: [
  '[error] Failed to load resource: the server responded with a status of 403 (Forbidden)'
]
```

**No select element exists** (`present: false`), not an empty one. The endpoint answered 200 with an
empty array, which is why the "loaded and empty" branch rendered rather than the failure branch.

### The unselected-policy path, end to end

`school-b@drive.test`, a discount line with the select left untouched:

```
  row 1 policy VALUE, left untouched: ""
  POST body  : {"lines":[{"description":"Tuition","amount_minor":100000,"kind":"charge"},{"description":"Sibling discount","amount_minor":-10000,"kind":"discount","discount_policy_id":""}]}
  POST status: 422
  rendered on the form: ["Line 2 — Select the discount policy that authorises this reduction. A reduction with no policy has to go through a credit note instead."]
  console: [
  '[error] Failed to load resource: the server responded with a status of 403 (Forbidden)',
  '[error] Failed to load resource: the server responded with a status of 422 (Unprocessable Content)'
]
```

The client did not block it, the server named the line, and `errorLinesFrom` rendered it with its
row prefix. That is the whole designed path in one run.

### What each observation establishes

1. A charge line renders **no** policy select at all — the fifth guard arm's surface is not reachable
   through the form.
2. A reduction line's select contains the placeholder plus exactly the School's one usable policy,
   by uuid.
3. **CORRECTED.** This said the option value "matches the row's `uuid` column, derived before the
   drive". The mapping was derived and **was never pasted**, so the claim rested on an artifact the
   report does not contain. What the logs support unaided is narrower: the option value is a uuid and
   not an integer — 36 characters in the `8-4-4-4-12` shape, where `DiscountPolicyResource:15`
   serialises `'id' => $this->uuid` and the integer primary key has no path to the wire. That the
   uuid is **this school's** is not established by the drive; see the isolation correction above.
4. Picking sets the state; flipping to charge unmounts the select **and** clears the state; flipping
   back shows `""`.
5. The posted body carries `discount_policy_id` on the reduction line and omits the key entirely on
   the charge line — the transition bug is absent in the running UI, not only in the pure function.
6. `201` on a policy-bearing reduction; `422` with a line-keyed field error on an unselected one.
7. **CORRECTED.** This said "two schools, two disjoint uuids, one identical label" and was filed
   under isolation. Disjointness is what it shows; ownership is not, on an N=1-per-school fixture.
8. A School with no usable policy is told so in a sentence, with no select rendered.
9. **THE ARITHMETIC, which this report previously carried nowhere despite a total being on screen.**
   `maker-02-two-lines-policy-picked.png` shows Description `Tuition` / Kind `Charge` / Amount `1000`
   on line 1, `Sibling discount` / `Discount` / `100` on line 2, and **Total ₦900.00**.
   `1000 − 100 = 900`, rendered `₦900.00`. In minor units that is `100000 + (−10000) = 90000`, which
   is the arithmetic the page actually performs: the sign is applied per kind and the sum goes
   through `sumMinor`, the sanctioned integer helper — nothing in the browser multiplies, divides or
   rounds, and `bin/ci-money-lint.php` is the gate that says so. `maker-03-after-submit.png` shows
   `₦900.00` twice, on the statement after the modal closed: **Account balance ₦900.00** and
   **Total billed ₦900.00**, with **Available credit ₦0.00**, the toast "Invoice created.", and the
   invoices tab reading `1` / "Showing 1 of 1". So the client's pre-submit preview (`₦900.00` in
   maker-02) and the server-derived invoice total (`₦900.00` in maker-03) agree on the same figure by
   two independent routes — the browser's `sumMinor` over the draft lines, and `GenerateInvoice`'s F6
   total derived server-side from the stored lines.

**The 403 in every console is `GET /dashboard`** — isolated by re-running with a
status-code-only listener, which printed `[ GET 403 ] http://localhost:8001/dashboard` and nothing
else across login, statement and modal. It is the pre-existing finance-seat dashboard bounce
(`docs/handoff/reports/feat-discount-policies-page.md:456-460`), fires before any finance page loads,
and is unrelated to this commit. The new `discount-policies?status=active` fetch answered **200** on
every seat. The `[error] … 422` in the last log is the deliberate refusal being reported by the
browser, not a fault.

### Drive friction worth recording

**`SESSION_DOMAIN=localhost` in `.env.drive.example:39`, so `127.0.0.1:8001` cannot hold a session.**
Login POSTs 302, `/dashboard` then 302s back to `/login` as a *guest* — which looks exactly like a
failed password, and there is no error rendered to say otherwise. `http://localhost:8001` works.
`SANCTUM_STATEFUL_DOMAINS` lists both hosts, so the Sanctum note in the skill does not cover this
one. Not filed as a ticket; recorded here.

### What was NOT driven

- **`checker@drive.test`, `void-checker@drive.test`, `super@drive.test`** — this screen has no
  checker surface and no approval step; the change is confined to the maker's invoice form.
- **A `requires_approval` policy being excluded from the select.** The fixture seeds only
  `requires_approval = false` policies (`DriveFinanceStates.php:114` records that as deliberate), so
  the browser never had one to exclude. That filter is measured only by §5's harness and by the Pest
  arm at `ReductionPreCheckTest.php` `student route — arm 3`; **no drive observed it**.
- **A superseded policy being excluded.** Same reason — reaching `superseded` needs an amendment
  approved over an active policy, which the fixture does not do.
- **The failed-fetch branch** (`policiesFailed`). Producing it needs the endpoint to error, which I
  did not force. Its text has never been rendered in a browser.
- **A waiver line.** Only `discount` was driven; `waiver` takes the identical `kind !== 'charge'`
  branch, which is an argument from reading the code, not an observation.
- **Anything opening-balance**, per the standing note — the fixture seeds no opening-balance state.

---

## 8. `git diff --stat`, raw

```
$ git diff --stat origin/staging...HEAD
 .../isolation-01-school-b-policy-select.png        | Bin 0 -> 64188 bytes
 .../maker-01-policy-select-on-reduction.png        | Bin 0 -> 60184 bytes
 .../maker-02-two-lines-policy-picked.png           | Bin 0 -> 63196 bytes
 .../maker-03-after-submit.png                      | Bin 0 -> 80713 bytes
 .../maker-04-empty-catalog.png                     | Bin 0 -> 68809 bytes
 .../school-b-01-unselected-policy-refused.png      | Bin 0 -> 75471 bytes
 docs/handoff/tickets/no-javascript-test-runner.md  | 140 ++++++++
 .../js/components/finance/new-invoice-modal.tsx    | 389 +++++++++++++++++----
 resources/js/types/finance.ts                      |  26 ++
 tests/Feature/Finance/ReductionPreCheckTest.php    |  69 +++-
 10 files changed, 546 insertions(+), 78 deletions(-)
```

389 changed lines in the modal against a much smaller behavioural change: the file is
comment-dense by house style, and the JSX re-indent from wrapping each line row in a container
(`<div className="flex items-end gap-2">` → `<div className="space-y-1"><div className="flex …">`)
moves every nested line. `git diff -w --stat` on that file is `255 insertions(+), 16 deletions(-)`.

---

## 9. `bin/quality`, raw — both runs

`bin/quality` is **15** steps. Re-derived, not carried: `grep -c '^\s*step "' bin/quality` → `15`,
and the literal at `bin/quality:59` is `[%d/15]`.

### Run 1 — files staged but NOT committed. PASS, and step 3 read nothing

```
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint: no changed PHP files
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
```

Everything else green, `✓ quality: PASS`. **This PASS is worth naming, not just reporting.**
`bin/lint-changed.sh:51` diffs `"$BASE"...HEAD`, so staged-but-uncommitted work is invisible to it —
the known ticket `docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md`, which
`CLAUDE.md` names. A gate that prints ✓ three times while linting zero files is the exact shape of a
green that means "I did not look". I committed and re-ran rather than accept it.

### Run 2 — after `7894086`. PASS 15/15, step 3 reading the files

```
quality gate — base 9fa55a7

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 1 changed PHP file(s)
       Prettier (check) on 2 changed file(s)
       ESLint on 2 changed file(s)
[4/15] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/15] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/15] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/15] boundary lint (§17.2)
   ✓ boundary-lint
[8/15] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/15] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/15] sql-clock lint (no MySQL clock functions in raw SQL — two frames, one table)
   ✓ sql-clock-lint
[13/15] architecture tests (§17.1)
   ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

No red runs. Both invocations passed; the first is reported because its green was uninformative for
step 3, not because it failed.

### Which steps read this commit's files

- **Step 3** — 2 frontend files through Prettier and ESLint, 1 PHP file through Pint. Run by hand
  first: `ESLint: 0 errors, 0 warnings`, `Prettier: All files formatted correctly`. One warning was
  produced and fixed during development — `Unused eslint-disable directive` on a second
  `react-hooks/set-state-in-effect` comment I had copied for symmetry; the rule fires once per
  effect. Removed, with the measurement recorded in the code.
- **Step 4** — `42 == baseline 42`, and **zero** of the 42 come from either changed file:
  `grep -E 'new-invoice-modal|types/finance' /tmp/quality-tsc.txt` → no output.
- **Step 5** — `pnpm run build` exit 0, `✓ built in 3.74s`. Only the pre-existing
  chunk-size-over-500 kB advisory.
- **Step 9** — `money-lint: OK — no money-rule violations (0 known exception(s))`. Nothing added
  computes money; the file stays inside the strict zone with no new entry.
- **Step 15** — the full suite through the ratchet. `ReductionPreCheckTest` alone:
  `22 passed, 118 assertions`.

The other ten steps do not read `resources/js` at all.

---

## 10. What I could not verify

1. **Anything about the client code, ongoing.** No JS test runner exists (§0). §5 is a one-off
   measurement against a throwaway harness that will not run again, and §7 is one person watching a
   browser once. A change to `patchForKind`, `wireLine` or `selectablePolicies` tomorrow will pass
   all 15 gate steps. This is the single largest gap in the commit and the reason the ticket exists.
2. **The `requires_approval` filter, in a browser.** The drive fixture has no such policy (§7). It
   is measured by the pure-function harness and by the pre-existing Pest arm; it has never been
   observed excluding an option from a rendered select.
3. **The `policiesFailed` branch, at all.** Its sentence has never been rendered.
4. **`waiver` as distinct from `discount`.** Only `discount` was driven.
5. **Whether the 389-line diff on the modal is reviewable as a diff.** The JSX re-indent means
   `git diff` shows moved lines as changed ones; `-w` gives 255/16. A reviewer wanting the real
   change should read the file, not the diff.
6. **Concurrency between the two fetches.** `loadEnrollment` and `loadPolicies` are fired from the
   same effect without ordering. Nothing in the modal depends on their order — the policy select
   renders only inside the `enrollment &&` branch, so it cannot appear before the enrollment
   resolves — but I did not force the interleaving to observe it.
7. **The PHP-version matrix, clean-room OS, remote enforcement and determinism** — the four standing
   residuals of a local-only floor, per `CLAUDE.md`.

---

# Round 2 — U8 commit 5, the review findings

**Base for this round:** `a4524be`. One commit on top. No source behaviour changed: comments, a test
comment, two ticket edits and one new ticket. The one code change is an assertion **reorder** inside
an arm added in round 1.

## Deviation — the brief's premise about the arm's assertions is false, and I measured it

The brief offered a choice: "add an assertion that can independently fail — **the reduction's
resolved integer id already is one**, so say so — or say in the comment that the null-check is
documentation."

The reduction's integer id is **not** independently failable either. Planted
`'discount_policy_id' => null` at `GenerateInvoice`'s line write, expecting a 201 carrying a null
provenance:

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":11373,"failed":1,
 "failures":[{"test":"…it_student_route_—_the_ACCEPTED_payload_has_the_modal’s_exact_shape__key_for_key",
 "message":"Expected response status code [201] but received 422.\nFailed asserting that 422 is identical to 201."}]}
```

The guard's **first** arm refused the insert; the expectation was never reached. Round 1's `lineSpecs()`
plant behaves the same way — the pre-check answers 422 first. Every wrong provenance this route can
produce is already refused by something, so no plant leaves a 201 standing with the wrong id.

**So I took neither branch of the offered choice.** The comment now says both database assertions are
documentation, shows the measurement for each, and names what actually carries the arm:
`assertCreated()` on this exact key shape — `discount_policy_id` present on one line and the key
absent on the other, which is what `wireLine()` emits and what no other arm posts. Restored after the
plant; `git diff --stat app/Finance/Actions/GenerateInvoice.php` empty.

The assertions are also reordered reduction-first, so the more informative one reports first if the
guards above them ever move. That reorder is the round's only executable change.

## 1. The stale citations — sweep, raw

### Before

Run per file this branch touched, not only the modal:

```
--- citations into new-invoice-modal.tsx ---
tests/Feature/Finance/ReductionPreCheckTest.php:79: * IT IS THE ONLY INVOICE-GENERATION ROUTE THE RUNNING UI USES. `new-invoice-modal.tsx:133` posts
tests/Feature/Finance/ReductionEnforcementTest.php:243:    // running UI exercises: new-invoice-modal.tsx:135-138 sends description/amount_minor/kind and no
docs/handoff/tickets/no-javascript-test-runner.md:42:`errorLinesFrom` in `resources/js/components/finance/new-invoice-modal.tsx:55-98` is real branching
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:37:`ReductionPreCheckTest.php:321-323` said "new-invoice-modal.tsx:135-138 sends description,
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:76:(`new-invoice-modal.tsx:55-98` pre-commit) as the existing example: at least eight enumerated
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:90:| The modal offers `waiver`/`discount` but sends only description, amount_minor, kind | **True** | pre-commit `new-invoice-modal.tsx:202-206` (payload) and `:305-310` (the two reduction options in the kind select) |
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:703:resources/js/components/finance/new-invoice-modal.tsx:133
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:711:resources/js/components/finance/new-invoice-modal.tsx:7:    generateForStudent,
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:712:resources/js/components/finance/new-invoice-modal.tsx:8:} from '@/actions/App/Finance/Http/Controllers/InvoiceController';
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:713:resources/js/components/finance/new-invoice-modal.tsx:133:            await axios.post(generateForStudent.url(student.uuid), {
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:723:kind select (`new-invoice-modal.tsx:229-232`) but sends only `description`, `amount_minor` and `kind`
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:915:  key is therefore unverified. Worth naming, because `new-invoice-modal.tsx:145-149` reads
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:970:WHAT new-invoice-modal.tsx:145-148 SETS AS formError: 'A reduction line must reference an active discount policy; discretionary reductions go through a credit note.'
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:978:WHAT new-invoice-modal.tsx:145-148 SETS AS formError: 'There are validation errors'
--- citations into types/finance.ts ---
docs/handoff/tickets/integer-primary-keys-still-on-the-wire.md:67:back: `resources/js/types/finance.ts:57` (`CreditNote.invoice_id: number`), `:82`
docs/handoff/reports/feat-finance-ob-approval-gate.md:461:   (`resources/js/types/finance.ts:100`). There is no third fetch and no third discriminator.
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:96:| `DraftLine` at `types/finance.ts:254-258` | **True** | exact |
--- citations into ReductionPreCheckTest.php ---
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:18:- `tests/Feature/Finance/ReductionPreCheckTest.php:422` (pre-commit numbering) —
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:37:`ReductionPreCheckTest.php:321-323` said "new-invoice-modal.tsx:135-138 sends description,
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:91:| So every reduction the running UI can submit is refused | **True** | `ReductionPreCheckTest.php:320-335`'s own comment says the same, and its arm asserts the refusal |
--- citations into no-javascript-test-runner.md ---
(none)
--- citations into feat-u8-invoice-modal-discount-policy.md ---
(none)
```

The sweep found **more than the three named**. Classified:

| Hit | Action |
| --- | --- |
| `ReductionPreCheckTest.php:79` → `new-invoice-modal.tsx:133` | corrected |
| `ReductionPreCheckTest.php:87` → `InvoiceController.php:83` | corrected — **not in the brief, and not found by the modal sweep**; it sits in the same docblock and is wrong |
| `ReductionPreCheckTest.php:82` → `statement.tsx` (no line) | line added |
| `ReductionPreCheckTest.php:83` → `routes/endpoints/finance.php:222-225` | corrected to `:222-227` — the comment block ends at `:227` |
| `ReductionEnforcementTest.php:243` → `new-invoice-modal.tsx:135-138` | corrected; the false claim is quoted and refuted rather than deleted |
| `no-javascript-test-runner.md:42` → `:55-98` | corrected |
| `no-javascript-test-runner.md` → `bin/lint-changed.sh:63-77`, `bin/quality:186-193` ×2, `bin/ci-money-lint.php:41-44` | corrected — my own ticket, re-derived while I was in it |
| `fix-u8-reduction-guard-field-errors.md` (7 hits) | **left alone.** A landed report is the record of what was claimed when it was claimed; rewriting it falsifies history. Three of those hits are pasted `grep -n` output, not citations at all |
| `feat-finance-ob-approval-gate.md:461`, `integer-primary-keys-still-on-the-wire.md:67` | **verified, no change needed** — they cite `types/finance.ts:57`, `:82`, `:100`, all above my insertion point at `:254`, and all three still land on what they claim |
| this report's own `:18`, `:37`, `:76`, `:90`, `:96` | left — each is explicitly marked "pre-commit" or is a verified pre-commit premise |

### Each correction, with the `sed` of the line landed on

**A. `new-invoice-modal.tsx:133` → `:349`**

```
$ grep -n 'generateForStudent.url\|axios.post' resources/js/components/finance/new-invoice-modal.tsx
349:            await axios.post(generateForStudent.url(student.uuid), {
```

**B. `InvoiceController.php:83` → `:114`**

```
$ grep -n 'assertDiscountPoliciesUsable();' app/Finance/Http/Controllers/InvoiceController.php
39:        $request->assertDiscountPoliciesUsable();
114:        $request->assertDiscountPoliciesUsable();
```

`:39` is `generate()`, `:114` is `generateForStudent()` — the docblock is about the student route, so
`:114`.

**C. `statement.tsx` → `:13`**

```
$ grep -n "forStudent" resources/js/pages/admin/finance/statement.tsx
13:import { forStudent } from '@/actions/App/Finance/Http/Controllers/InvoiceController';
90:                forStudent.url(student.uuid),
```

**D. `routes/endpoints/finance.php:222-225` → `:222-227`**

```
$ sed -n '220,228p' routes/endpoints/finance.php
    ->middleware('permission:finance.opening-balance.submit');

/*
 * Bill a STUDENT (the bursar UI's path). Enrollment resolution is server-side via the
 * ACL port, so the frontend never handles an enrollment id. The read powers the "New
 * invoice" modal's episode-confirm + F7 preview; the write generates and delegates to the
 * same GenerateInvoice (unchanged). The old enrollment-id POST above stays for the harness.
 */
Route::get('/v1/finance/students/{student:uuid}/billable-enrollment', [InvoiceController::class, 'billableEnrollment']);
```

Both quoted phrases are inside `:222-227`; `:225` cut the block one line before "stays for the harness".

**E. `new-invoice-modal.tsx:135-138` "sends … no discount_policy_id whatsoever" → false**

```
$ sed -n '113p;123,125p' resources/js/components/finance/new-invoice-modal.tsx
export function wireLine(
    if (line.kind !== 'charge') {
        wire.discount_policy_id = line.discountPolicyId;
    }
```

`wireLine()` spans `:113-128`. The comment in `ReductionEnforcementTest` now quotes the old sentence,
states both halves are false, and says what actually happens: a UI reduction with nothing picked
reaches arm 1 as `""` via `ConvertEmptyStringsToNull`, not as an absent key.

**F. `errorLinesFrom` `:55-98` → `:153-196`**

```
$ grep -n 'function errorLinesFrom' resources/js/components/finance/new-invoice-modal.tsx
153:function errorLinesFrom(data: unknown): string[] {
$ sed -n '196p' resources/js/components/finance/new-invoice-modal.tsx
}
$ grep -n 'Turn a 422 body into the lines to show above the form' resources/js/components/finance/new-invoice-modal.tsx
131: * Turn a 422 body into the lines to show above the form.
```

**G–J. The ticket's other four**

```
$ grep -n '^\s*step "\|\[%d/' bin/quality
59:    printf '%s[%d/15]%s %s\n' …
176:step "lint changed files (Pint / Prettier / ESLint, check mode)"
180:step "types (tsc ratchet vs tsc-baseline)"
194:step "frontend build (vite — catches what the tsc ratchet structurally cannot)"
215:step "money lint (UI: money via formatNaira, no JS money math)"
```

`:59`, `:176`, `:180`, `:194`, `:215` were all already exact. The four that were not:

```
$ sed -n '184p' bin/quality
# 5. Frontend build. The tsc ratchet CANNOT stand in for this: a syntax error that
```
`bin/quality:186-193` → `:184-193` (the comment block starts at `:184`, twice in the ticket).

```
$ grep -n 'function isFinanceUi' -A 4 bin/ci-money-lint.php
40:function isFinanceUi(string $rel): bool
41-{
42-    return str_starts_with($rel, 'resources/js/pages/admin/finance/')
43-        || str_starts_with($rel, 'resources/js/components/finance/');
44-}
```
`bin/ci-money-lint.php:41-44` → `:40-44`, predicates at `:42-43`.

```
$ grep -n 'prettier_files\|eslint_files' bin/lint-changed.sh
62:if [ "${#prettier_files[@]}" -gt 0 ]; then
...
69:if [ "${#eslint_files[@]}" -gt 0 ]; then
...
$ grep -n 'BASE"\.\.\.HEAD' bin/lint-changed.sh
51:done < <(git diff -z --name-only --diff-filter=ACMR "$BASE"...HEAD)
```
`bin/lint-changed.sh:63-77` → `:62-67` and `:69-74`, with the changed-file list at `:51`.
(Round 1's report body cited that same construct as `:52`; it is `:51`.)

### After

```
--- citations into new-invoice-modal.tsx ---
tests/Feature/Finance/ReductionPreCheckTest.php:79: * IT IS THE ONLY INVOICE-GENERATION ROUTE THE RUNNING UI USES. `new-invoice-modal.tsx:349` posts
tests/Feature/Finance/ReductionEnforcementTest.php:246:    // "new-invoice-modal.tsx:135-138 sends description/amount_minor/kind and no discount_policy_id
tests/Feature/Finance/ReductionEnforcementTest.php:248:    // false as of U8 commit 4: `wireLine()` (new-invoice-modal.tsx:113-128) puts `discount_policy_id`
docs/handoff/tickets/stale-path-line-citations.md:35:| `ReductionEnforcementTest`, the `proof 12b (DB)` arm's opening comment | `new-invoice-modal.tsx:135-138` "sends … no discount_policy_id whatsoever" | …
docs/handoff/tickets/stale-path-line-citations.md:36:| `docs/handoff/tickets/no-javascript-test-runner.md`, … | `new-invoice-modal.tsx:55-98` | `errorLinesFrom` | …
docs/handoff/tickets/stale-path-line-citations.md:37:| `ReductionPreCheckTest`, the `rpcPostForStudent()` docblock | `new-invoice-modal.tsx:133` | the `axios.post` is far below that | …
docs/handoff/tickets/no-javascript-test-runner.md:42:`errorLinesFrom` in `resources/js/components/finance/new-invoice-modal.tsx:153-196` (docblock from
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:37: … (pre-commit, unchanged)
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md: … (7 hits, landed report, unchanged)
--- citations into types/finance.ts ---
docs/handoff/reports/feat-finance-ob-approval-gate.md:461: … (verified, unchanged)
docs/handoff/reports/feat-u8-invoice-modal-discount-policy.md:96: … (pre-commit, unchanged)
docs/handoff/tickets/integer-primary-keys-still-on-the-wire.md:67: … (verified, unchanged)
--- citations into ReductionEnforcementTest.php ---
docs/handoff/reports/fix-u8-reduction-guard-field-errors.md:661,664 … (landed report, unchanged)
--- citations into no-javascript-test-runner.md ---
(none)
--- citations into stale-path-line-citations.md ---
(none)
```

Every remaining `new-invoice-modal.tsx:135-138` and `:55-98` is a **quotation of the wrong text**,
inside a sentence saying it is wrong. Those are the record and must stay.

The three `types/finance.ts` citations verified rather than assumed:

```
$ sed -n '57p;82p;100p' resources/js/types/finance.ts
    invoice_id: number;
    invoice_id: number;
    type: 'fee_schedule_change';
```

All three land on what the citing text claims; my insertion was at `:254` and shifts nothing above it.

## 2. The over-claiming arm

Comment rewritten. It now states what the arm proves (the server accepts `wireLine()`'s exact key
shape and resolves the uuid to the right stored provenance), states that a PHP array literal cannot
detect a JavaScript regression and that the earlier claim was wrong, and — per the deviation above —
records that **both** database assertions are documentation with the measurement for each. The
assertions are reordered reduction-first.

```
$ DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/ReductionPreCheckTest.php tests/Feature/Finance/ReductionEnforcementTest.php
{"tool":"pest","result":"passed","tests":34,"passed":34,"assertions":159,"duration_ms":25652}
```

## 3. The ticket for the recurring class

`docs/handoff/tickets/stale-path-line-citations.md`. A gate proposal, not a lament. No lint built.

Its load-bearing measurement, a census over `a4524be`:

```
citations found (path:LINE)      : 1074
  path not resolvable            : 59
  resolvable                     : 1015
    line within file length      : 1012
    line PAST end of file        : 3

  ✗ tests/Feature/Rbac/RbacDiffGrantsTest.php:173  cites Models/Role.php:186  (file is 36 lines)
  ✗ docs/handoff/reports/feat-opening-balance-import-staging.md:630  cites ImportOpeningBalances.php:506  (file is 447 lines)
  ✗ docs/handoff/reports/rbac-diff-grants.md:429  cites Models/Role.php:186  (file is 36 lines)
```

All three past-EOF hits confirmed by hand (`app/Models/Role.php` is 36 lines,
`app/Finance/Console/ImportOpeningBalances.php` is 447).

That census is the argument the ticket is built on: **1012 of 1015 resolvable citations already pass
the cheap check, and all seven defects across the three branches would have passed it too**, because
each pointed at a line that exists. `chore-finance-drive-skill.md:419-423` had already recorded the
same thing from the other side — "0 out of range … it could not have caught any of group A, because
a citation shifted by five lines still lands inside the file."

Three tiers sketched, weakest first: file-exists-and-in-range (state-based, `ci-money-lint` shape);
citation-into-a-file-this-commit-modified-must-be-in-the-diff (`$BASE`-aware, the
`ci-grants-convergence-lint` shape, and the only tier that would have paid); and citations naming a
symbol checked against the line. Each carries what it cannot catch — tier 2's honest limit is that it
proves you looked, never that you were right, which is exactly why two of this branch's four were
born wrong. A fourth option is named because it may be cheapest and best: ban line numbers in new
comments and cite symbols only. Building order, baselining and `bin/quality` placement are all left
open.

`bin/ci-sql-clock-lint.php` was read first, as instructed; four things it did that this lint would
need are carried into the ticket, including its coverage test at
`tests/Arch/SqlClockLintCoverageTest.php`.

## 4. `git diff --stat`, raw

**Excluding this report, because a report cannot state its own size.** The first version of this
section quoted `294 …| 508 insertions` for all five files; filling in §5 grew the report and made
that figure wrong inside the same commit — the carried-number failure, live, in the round whose
subject is stale facts. The four other files are stable under further edits here, so they are what
is quoted:

```
$ git diff --stat a4524be -- docs/handoff/tickets/ tests/
 docs/handoff/tickets/no-javascript-test-runner.md  |  13 +-
 docs/handoff/tickets/stale-path-line-citations.md  | 152 +++++++++++++++++++++
 tests/Feature/Finance/ReductionEnforcementTest.php |  16 ++-
 tests/Feature/Finance/ReductionPreCheckTest.php    |  58 +++++---
 4 files changed, 214 insertions(+), 25 deletions(-)
```

The fifth file is this report, appended to. Re-derive its delta with `git diff --stat a4524be` if
you need it; any figure written here would be one amend out of date.

No `resources/js` file is in either. The modal and `types/finance.ts` are untouched this round —
every correction was to something pointing AT them.

## 5. `bin/quality`, raw

**15** steps, re-derived this round: `grep -c '^\s*step "' bin/quality` → `15`, literal at
`bin/quality:59` → `[%d/15]`. Run once, on the committed tree, `BASE=origin/staging` so the diff
spans both commits of the branch. **No red runs this round.**

```
quality gate — base 9fa55a7

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 2 changed PHP file(s)
       Prettier (check) on 2 changed file(s)
       ESLint on 2 changed file(s)
[4/15] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/15] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/15] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/15] boundary lint (§17.2)
   ✓ boundary-lint
[8/15] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/15] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/15] sql-clock lint (no MySQL clock functions in raw SQL — two frames, one table)
   ✓ sql-clock-lint
[13/15] architecture tests (§17.1)
   ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**Which steps read THIS round's files.** Only two, and that is the honest answer:

- **Step 3** — `Pint (check) on 2 changed PHP file(s)` is exactly this round's two test files
  (`ReductionPreCheckTest.php`, `ReductionEnforcementTest.php`); round 1 changed no PHP. The
  `2 changed frontend file(s)` through Prettier and ESLint are round 1's, pulled in because `$BASE`
  is the branch point rather than `a4524be`.
- **Step 15** — the suite, through the ratchet. The two files directly:
  `34 tests, 34 passed, 159 assertions`.
- **Steps 4, 5 and 9** ran green and read **round 1's** frontend files; no `resources/js` file
  changed this round, so they had nothing new of this round's to see.
- **Nothing in the gate read the two ticket files or this report.** `bin/quality` does not lint
  Markdown at all — `bin/lint-changed.sh` passes `.md` to neither Prettier nor ESLint (its Prettier
  case matches `resources/*` only). Three of this round's five changed files are therefore
  ungated entirely, which is the same shape of gap the new ticket is about.

## 6. What I could not verify

1. **That the sweep is complete.** `grep -rn "<file>:[0-9]"` finds a citation only when the basename
   appears literally. A citation written as "the modal, line 349" or "InvoiceController line 114" is
   invisible to it, and I did not search for prose forms. The new ticket's census (1074 tokens) is a
   lower bound for the same reason.
2. **The 59 unresolvable paths in the census.** Not triaged. Some are certainly pasted `grep -n`
   output rather than citations; how many are real is unknown and is work the ticket leaves open.
3. **That the corrected numbers stay correct.** Nothing checks them — that is the ticket's whole
   subject, and this round did not build the lint.
4. **Anything about the frontend, still.** No JS test runner; unchanged from round 1, and no drive
   was re-run because no `resources/js` file changed.
5. **Whether tier 2's false-positive rate is tolerable.** Asserted as "real and unbounded" in the
   ticket from the observation that `new-invoice-modal.tsx` is cited from four places. Not measured
   across the tree.

---

# Round 3 — U8 commit 6, the second cold review

**Base:** `2b3cdbb`. One commit. Every correction below is applied **in place** in the sections
above and marked **CORRECTED** there; this section carries the evidence, not a second copy of the
wrong sentences.

## Deviation — finding 4a is declined, with the measurement

The review read the fixture count table in §7 as "reflowed, not verbatim: the borders keep the
original widths but every data row has had its inner padding stripped", and asked for it re-pasted
as the command printed it. **It was already as the command printed it.** Three captures, all
byte-identical on the School A row:

```
$ APP_ENV=drive php artisan finance:seed-drive-fixture > seed.raw.txt          # stdout to a FILE
$ grep 'A (school#1)' seed.raw.txt | od -c
0000000    |       A       (   s   c   h   o   o   l   #   1   )       |
0000020        1       |       1       |       2       |       1       |
0000040        1       |  \n

$ script -q seed.tty.txt env APP_ENV=drive php artisan finance:seed-drive-fixture   # pseudo-TTY
0000040        1       |  \r  \n                                        # identical but for the \r

$ COLUMNS=200 APP_ENV=drive php artisan finance:seed-drive-fixture             # wide terminal
0000040        1       |  \n                                            # identical
```

The command sizes its borders from the widest cell and then emits the cells unpadded. Both tables it
prints behave this way, and `COLUMNS` does not change it. **Why** Symfony's table does that here I did
not determine, and it is a property of the seeder's output rather than of this branch. The note is now
in §7 so the next reader does not re-open it as a capture problem.

One consequence to record: obtaining these captures **re-ran `finance:seed-drive-fixture`, which is
`migrate:fresh`**. The drive fixture in `portal_drive` is a new instance; the policy and student uuids
quoted throughout §7 belong to the earlier instance and are not re-derivable from the current database.

## 1. The catalog contract

### The inverted filter ships green — reproduced

Planted at `new-invoice-modal.tsx:78`, `policy.requires_approval !== true` → `=== true`. Every
school's catalog goes empty and every bursar is told, in words, "This school has no active discount
policy that can back a reduction."

```
quality gate — base 9fa55a7

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)          ✓
[2/15] wayfinder:generate --with-form                                            ✓
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)                  ✓
       Pint (check) on 2 changed PHP file(s)
       Prettier (check) on 2 changed file(s)
       ESLint on 2 changed file(s)
[4/15] types (tsc ratchet vs tsc-baseline)                                       ✓
[5/15] frontend build (vite …)                                                   ✓
[6/15] authorization guard                                                       ✓
[7/15] boundary lint (§17.2)                                                     ✓
[8/15] grants-convergence lint                                                   ✓
[9/15] money lint                                                                ✓
[10/15] runtime-zero lint                                                        ✓
[11/15] identifier-generation bypass guard                                       ✓
[12/15] sql-clock lint                                                           ✓
[13/15] architecture tests (§17.1)                                               ✓
[14/15] static analysis (Larastan level 5 vs baseline)                           ✓
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)                    ✓

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**15 of 15, no warning, no console entry, no non-2xx status.** Restored; `git status --porcelain`
clean afterwards. (The step lines are abridged to one column here for width; the unabridged form of
the same 15 steps is §9's final run.)

### No test pinned either key — confirmed

`DiscountPoliciesScreenTest`'s three response-body expectations pluck `name` (twice) and `id` (once).
Repo-wide, every other occurrence of `requires_approval` in `tests/` is a **model create**, not an
assertion on a payload; there is no `assertJsonStructure` over a discount-policies response anywhere.
So `axios.get<SelectablePolicy[]>` — erased at runtime — was the only thing standing between a
Resource rename and the same false sentence, with nobody touching JavaScript.

### The new arm, and its three watched reds

`tests/Feature/Finance/DiscountPoliciesScreenTest.php` —
*"carries `status` and `requires_approval`, in the shapes the invoice modal reads"*. Asserts key
presence, `requires_approval` is a **boolean** (not the integer `1`, which `!== true` would pass
through), `status` is one of the three enum values the client's union declares, and that a genuine
`active` actually arrives rather than every row happening to be inactive. Its comment says plainly
that it pins the **server's half only** and that the client's half stays unguarded until there is a
runner.

Green: `{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":39,"duration_ms":14500}`

```
############ RED A — 'status' removed from DiscountPolicyResource ############
{"tool":"pest","result":"failed","tests":9,"passed":8,"assertions":29,"failed":1,"failures":[
 {"test":"…it_carries__status__and__requires__approval___in_the_shapes_the_invoice_modal_reads",
  "file":"…/DiscountPoliciesScreenTest.php","line":177,
  "message":"Failed asserting that an array has the key 'status'"}]}

############ RED B — 'requires_approval' removed from DiscountPolicyResource ############
{"tool":"pest","result":"failed","tests":9,"passed":8,"assertions":30,"failed":1,"failures":[
 {"test":"…it_carries__status__and__requires__approval___in_the_shapes_the_invoice_modal_reads",
  "file":"…/DiscountPoliciesScreenTest.php","line":177,
  "message":"Failed asserting that an array has the key 'requires_approval'"}]}

############ RED C — the model's boolean cast dropped ('boolean' → 'integer') ############
{"tool":"pest","result":"failed","tests":9,"passed":8,"assertions":31,"failed":1,"failures":[
 {"test":"…it_carries__status__and__requires__approval___in_the_shapes_the_invoice_modal_reads",
  "file":"…/DiscountPoliciesScreenTest.php","line":180,
  "message":"Failed asserting that 0 is of type bool."}]}
```

One failure each time, always this arm, never another in the file. RED C is the one no reviewer asked
for and the one worth having: a Resource that stops casting makes every approval-requiring policy
**selectable**, which the reduction guard's third arm then refuses at the database with a message the
operator did not expect. Restored after each.

## 2. The census

Corrected in `docs/handoff/tickets/stale-path-line-citations.md`, in place.

**The label was false.** The published figures were not "a census over `a4524be`" — they were taken
on a working copy already carrying round 2's uncommitted files. Re-taken on `git worktree` checkouts
of named shas, with the regex, the scanned set, the exclusions and the resolution rule now written
into the ticket so the number is re-derivable:

```
$ php census.php <clean checkout of a4524be>
citations matched                 : 1010
  line mentions 'vendor' (skipped): 28
  unresolvable                    : 44
  resolved                        : 938
    line within file              : 936
    line PAST end of file         : 2

$ php census.php <clean checkout of 2b3cdbb>
citations matched                 : 1117
  line mentions 'vendor' (skipped): 28
  unresolvable                    : 45
  resolved                        : 1044
    line within file              : 1035
    line PAST end of file         : 9
```

My figures differ from the review's (1042 / 103 / 939 / 936 / 3) because the regexes and the
resolvers differ — which is the point the review was making, and the reason the rule is now stated
rather than implied.

### Past-EOF triage, per hit

| Hit | Verdict |
| --- | --- |
| `feat-opening-balance-import-staging.md:630` → `ImportOpeningBalances.php:506` | **Real.** `app/Finance/Console/ImportOpeningBalances.php` is 447 lines. |
| `RbacDiffGrantsTest.php:173` → `Models/Role.php:186-188` | **Manufactured by my method.** The citing line reads "findByParam (**vendor** Models/Role.php:186-188)". `vendor/spatie/laravel-permission/src/Models/Role.php` is **221** lines and `:186-188` is exactly the `findByParam` team-scoping block: `$query->where(fn ($q) => $q->whereNull($teamsKey)->orWhere($teamsKey, …))`. The census excludes `vendor/`, so the basename fell through to `app/Models/Role.php` (36 lines). |
| `rbac-diff-grants.md:429` → `Models/Role.php:186-188` | **Manufactured the same way, and the review did not name this one.** Same vendor file, same real target. The word "Vendor" opens the **previous** line, so a line-scoped vendor guard still misses it. |

So **1 real, 2 manufactured**, and the ticket's "all three confirmed by hand" confirmed the length of
a file two of the citations do not point at. Corrected there.

### Self-reference, verified

At `2b3cdbb`: **9** past-EOF hits, **7 of them from this branch's own two files** — four in the
ticket, three in this report, every one a quotation of the census output. Two pre-existed, and one of
those two is itself manufactured. The branch proposing the check is the largest single contributor of
violations under it. (The review said 10; I measure 9, the difference being my vendor-line guard,
which removes `RbacDiffGrantsTest.php:173`.)

Both mechanisms are now in the ticket's Tier 1 cost list: **an excluded directory forces basename
mis-resolution and manufactures findings**, and **quoted output is indistinguishable from a
citation**.

## 3. The isolation claim

§7 and observation 3 and 7 corrected in place. What the two seats' logs support is **disjointness**;
**ownership** is not established, because with one policy per school "A sees X, B sees Y, X ≠ Y" is
bit-for-bit what a swapped scope would render, and the absence half is satisfied by a swap too. The
uuid↔school mapping was derived and never pasted.

Verified before citing: `tests/Feature/Finance/DiscountPoliciesScreenTest.php:130-145` seeds
`School A bursary` + `School A retired` in school A and `School B sibling` in school B, and asserts
`collect($response->json())->pluck('id')->all()` `->toBe([$mine->uuid])`. The asymmetry — two rows
against one — is what lets it fail on both count and value under a swap. It predates this branch.

`docs/handoff/tickets/drive-isolation-check-cannot-see-a-swap.md` records the gap in the procedure.
`.claude/skills/finance-drive/SKILL.md` is **not** edited.

## 4. The drive report's three misses

- **Count table** — declined with measurement, see the deviation above; the note is in §7.
- **Counts on the select lines** — added in the skill's `(n):` form, stated as a reformat of the same
  arrays rather than a re-drive.
- **Arithmetic** — added as observation 9: `1000 − 100 = 900` rendered `₦900.00`; in minor units
  `100000 + (−10000) = 90000`; `₦900.00` again on the statement as **Account balance** and **Total
  billed** after the modal closed, so the browser's `sumMinor` preview and the server's F6 total agree
  by two independent routes. (My first draft of this said maker-03 showed the figure "once in the
  modal and once on the statement row"; the modal is closed in that shot. Corrected before commit by
  reading the image.)

## 5. Five corrections of record

| | Was | Now |
| --- | --- | --- |
| a | `bin/lint-changed.sh:52` | `:51` — round 2 stated the correction and did not apply it |
| b | 62,912 lines hand-written TS/TSX | **63,177** at `7894086`, the sha that ships the ticket; 62,912 is the figure at `9fa55a7`. The 99,220 "total" belonged to a working tree where wayfinder had run, not to any commit — a clean checkout has no `resources/js/actions/` at all. Corrected in both the report and the ticket, with a re-derivation command |
| c | modal `:106` / `:212`, both parsing as naming `selectablePolicies` **as** the guard | rewritten to name `assertDiscountPoliciesUsable()` and the trigger explicitly and point at the docblock only for where they are described |
| d | `discount-policies.tsx:162-177` | the `load` useCallback is `:162-178`, the `useEffect` `:180-186` |
| e | student redacted in text, named in the PNGs, `maker-04` a different student | redaction policy declared in §7 with a per-shot table; `maker-04` named as `student#2` and its unmentioned F7 banner recorded |

## 6. The three exports with no importers

Verified: the module's only importer is `statement.tsx:17`, which takes `NewInvoiceModal` alone.
`selectablePolicies`, `patchForKind` and `wireLine` are reachable from nothing in the bundle.

**Kept exported, with the reason now in the file's docblock.** They are the only pure,
mechanically-testable seam in a module whose logic is otherwise entangled with React state, and
un-exporting them would make the file untestable by construction on the day a runner lands. The cost
is stated rather than hidden: nothing imports them, so neither `tsc` nor `eslint` will report one
going dead, and an unused export is real surface. Keeping the seam open costs an unused export today;
closing it costs the first test tomorrow.

## 7. `git diff --stat`, raw

Excluding this report, for the reason round 2 records — a report cannot state its own size, and
quoting a figure that the next paragraph invalidates is the failure this branch keeps correcting:

```
$ git diff --stat 2b3cdbb -- . ':!docs/handoff/reports/'
 .../drive-isolation-check-cannot-see-a-swap.md     |  79 +++++++++++++++
 docs/handoff/tickets/no-javascript-test-runner.md  |  13 ++-
 docs/handoff/tickets/stale-path-line-citations.md  | 110 ++++++++++++++++-----
 .../js/components/finance/new-invoice-modal.tsx    |  17 +++-
 .../Feature/Finance/DiscountPoliciesScreenTest.php |  63 ++++++++++++
 5 files changed, 252 insertions(+), 30 deletions(-)
```

The sixth file is this report, edited in place and appended to. `git diff --stat 2b3cdbb` gives the
full figure; any number written here would be one amend out of date.

The only executable changes are the new Pest arm and two comment blocks in the modal. No behaviour
changed.

## 8. `bin/quality`, raw

**15** steps, re-derived: `grep -c '^\s*step "' bin/quality` → `15`; the literal at `bin/quality:59`
is `[%d/15]`.

**Two runs this round, and the first is the planted one.** Run 1 is §1's inverted-filter
reproduction — a deliberate regression, PASS 15/15, which is the finding. Run 2 is the committed
tree.

```
quality gate — base 9fa55a7

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 3 changed PHP file(s)
       Prettier (check) on 2 changed file(s)
       ESLint on 2 changed file(s)
[4/15] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/15] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/15] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/15] boundary lint (§17.2)
   ✓ boundary-lint
[8/15] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/15] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/15] sql-clock lint (no MySQL clock functions in raw SQL — two frames, one table)
   ✓ sql-clock-lint
[13/15] architecture tests (§17.1)
   ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

**Which steps read this round's files.**

- **Step 3** — `3 changed PHP file(s)`: this round's `DiscountPoliciesScreenTest.php` plus round 2's
  two test files (`$BASE` is the branch point, so the diff spans all three commits). The
  `2 changed frontend file(s)` are the modal and `types/finance.ts`; the modal's two comment blocks
  are this round's.
- **Steps 4, 5** — read the modal. Comment-only changes there, but the file is re-typechecked and
  re-bundled.
- **Step 9** — money lint over `resources/js`, including the modal.
- **Step 15** — the suite. `DiscountPoliciesScreenTest` directly: `9 tests, 9 passed, 39 assertions`.
- **The three ticket/report Markdown files are read by nothing.** `bin/lint-changed.sh` passes `.md`
  to neither Prettier nor ESLint. Unchanged from round 2, and still the same shape of gap as the
  citation ticket describes.

## 9. What I could not verify

1. **Why the seeder's tables print unpadded cells.** Reproduced three ways and confirmed it is the
   command, not the capture; the cause inside Symfony's table renderer was not chased.
2. **The client's half of the catalog contract, still.** The new arm pins the server. Inverting
   `selectablePolicies`' own predicate still ships green through all 15 steps — measured this round —
   and nothing will change that until there is a JavaScript runner.
3. **The 44/45 unresolvable citations** in the census. Still untriaged; how many are real is unknown.
4. **That the census regex is the right one.** Two independent resolvers gave different totals; mine
   is now written down and reproducible, which makes it checkable, not correct. The past-EOF figure
   is the only part I triaged hit by hit.
5. **Ownership from the drive.** Not established and now not claimed; the Pest arm is what carries it,
   and the drive fixture would need asymmetry before a browse could.
6. **The drive itself was not re-run.** The observations in §7 stand as recorded from the original
   run; re-seeding for the count-table capture destroyed that fixture instance, so those uuids can no
   longer be reconciled against the database.
7. **`maker-04`'s F7 banner.** Recorded now, but I did not go back and check whether it changes what
   the shot demonstrates; the empty-catalog branch does not depend on invoice state, and I did not
   drive a student without an active invoice to confirm that independently.
8. **The PHP-version matrix, clean-room OS, remote enforcement and determinism** — the four standing
   residuals of a local-only floor.
