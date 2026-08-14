# TICKET — integer primary keys are still on the wire in eleven places

**Status:** open, not implemented. Raised by `feat/u8-wire-ids-uuid`, which converted **two** of them
(`lines.*.fee_item_id`, `lines.*.discount_policy_id` on `POST /v1/finance/invoices`). The rest were
surveyed, not changed — each is a separate wire-format change with its own client to move.

**Root:** the ruling U8 executes is "every id this platform puts on the wire is a uuid". That was
already false when the branch started, and it is still false after it. This ticket is the inventory.

## Method

Re-derived for this ticket; every figure below comes from these commands, run against the branch tip.

```bash
# Outbound — Resources
grep -rn "'id' => \$this->id\|'id' => \$[a-z]*->id" app/Http/Resources/ app/Finance/Http/Resources/
grep -rn "_id' =>" app/Finance/Http/Resources/          # non-`id` keys carrying a foreign key

# Inbound — validation rules that accept an integer id
grep -rn "'[a-z_]*_id' => \[" app/ --include='*.php' | grep "'integer'"
grep -rn "Rule::exists(" app/ --include='*.php' | grep "'id'"
grep -rn "exists:[a-z_]*,id" app/ --include='*.php'

# Client — what the frontend types as a number
grep -rn "_id[?]*: number" resources/js/ --include='*.ts' --include='*.tsx' | grep -v resources/js/actions/
```

`resources/js/actions/` is excluded throughout: it is wayfinder-generated route-definition output, not
hand-written client code.

**Limits, stated rather than implied.** The Resource sweep is exhaustive (46 Resource files, all read).
The inbound sweep covers rule arrays in `app/`. It does **not** cover ids read straight off the request
without a rule, or ids embedded in free-form controller payloads outside a Resource — a controller
building a response array by hand is caught only where it names a `*_id` key. So this list is a floor.

## Outbound — Resources emitting an integer as `id`

Of 46 Resource files, 36 emit `'id' => …->uuid`. Five emit the integer:

| File | Line |
|---|---|
| `app/Http/Resources/UserResource.php` | 18 |
| `app/Http/Resources/ScholarshipResource.php` | 18 |
| `app/Http/Resources/SportHouseResource.php` | 18 |
| `app/Http/Resources/ClassLevelArmOptionsResource.php` | 18 |
| `app/Http/Resources/CurriculumOptionResource.php` | 18 |

None are Finance. `UserResource` is the one to think about separately — `User` is a platform model, not
School-owned, and `SchoolScope` deliberately never applies to it.

## Outbound — Finance Resources emitting an integer foreign key beside a uuid `id`

These are the ones inside the module the branch was working in, and they were not found by looking for
`'id' =>`:

| File | Line | Key | Value |
|---|---|---|---|
| `app/Finance/Http/Resources/CreditNoteResource.php` | 38 | `invoice_id` | `$this->invoice_id` — integer PK |
| `app/Finance/Http/Resources/VoidRequestResource.php` | 36 | `invoice_id` | `$this->invoice_id` — integer PK |
| `app/Finance/Http/Resources/PaymentResource.php` | 36 | `invoice_id` | `$a->invoice_id` — integer PK, inside `allocations` |
| `app/Finance/Http/Resources/OpeningBalanceBatchResource.php` | 47 | `invoice_id` | literal `null` — listed for completeness, discloses nothing |

Each of these sits in a payload whose own `id` is a uuid (`CreditNoteResource:35`,
`VoidRequestResource:35`, `PaymentResource:28`), so one response carries both conventions.

The client consumes them as numbers, which is where the round trip would begin if anything posted them
back: `resources/js/types/finance.ts:57` (`CreditNote.invoice_id: number`), `:82`
(`VoidRequest.invoice_id: number`), `:214` (`allocations[].invoice_id: number`).

## Inbound — rules that accept an integer primary key

| File | Line | Field | Rule |
|---|---|---|---|
| `app/Finance/Http/Requests/FeeScheduleRequest.php` | 57 | `term_id` | `integer` + `Rule::exists('terms','id')` School-scoped |
| `app/Finance/Http/Requests/FeeScheduleRequest.php` | 58 | `class_level_id` | same, on `class_levels` |
| `app/Finance/Http/Controllers/FeeScheduleController.php` | 56 | `term_id` (index filter) | `integer` + scoped `Rule::exists` |
| `app/Finance/Http/Controllers/FeeScheduleController.php` | 174 | `term_id` (prefill) | `integer`, **no existence rule** |
| `app/Finance/Http/Controllers/FeeScheduleController.php` | 175 | `class_level_id` (prefill) | `integer`, **no existence rule** |
| `app/Finance/Http/Requests/StoreOpeningBalanceImportRequest.php` | 54 | `term_id` | `Rule::exists('terms','id')` School-scoped |
| `app/Http/Requests/StudentRequest.php` | 74 | `curriculum_id` | `Rule::exists('curricula','id')` School-scoped |
| `app/Http/Requests/StudentRequest.php` | 91 | `sport_house_id` | `integer` + **unscoped** `exists:sport_houses,id` |
| `app/Http/Requests/StudentRequest.php` | 92 | `scholarship_id` | `integer` + **unscoped** `exists:scholarships,id` |
| `app/Http/Requests/ImportStudentRequest.php` | 34 | `curriculum_id` | `Rule::exists('curricula','id')` School-scoped |
| `app/Http/Requests/StudentSubject/AddOptionalSubjectRequest.php` | 25 | (subject) | `Rule::exists('curriculum_subjects','id')` scoped by `curriculum_id` |
| `app/Http/Requests/StudentSubject/BulkAddOptionalSubjectsRequest.php` | 26 | same | same |
| `app/Http/Controllers/StudentSubjectController.php` | 108 | (subject) | `exists:curriculum_subjects,id` |
| `app/Http/Controllers/GuardianController.php` | 619, 646, 668, 686 | `guardian_ids.*` | `integer` + **unscoped** `exists:guardians,id` |

`app/Http/Requests/TeacherRequest.php:24` is listed only to be excluded: it reads
`['uuid', 'exists:schools,id']` — a uuid rule against a column named `id`, which is a different thing
and needs reading before anyone "fixes" it.

## The complete integer round trip

`term_id` and `class_level_id` make the full circuit through the fee-schedules screen, with no uuid
anywhere in the loop:

1. **Out:** `app/Finance/Http/Resources/FeeScheduleResource.php:70-71` emits `term_id` /
   `class_level_id` as integers, in a payload whose own `id` is a uuid (`:69`).
2. **Typed:** `resources/js/pages/admin/finance/fee-schedules.tsx:70-71` — `term_id: number;
   class_level_id: number;`.
3. **Held:** `:310-311` — `setTermId(String(schedule.term_id))`.
4. **Back:** `:410-411` and `:418-419` — `term_id: Number(termId), class_level_id: Number(classLevelId)`.
5. **In:** `FeeScheduleRequest:57-58` validates them as integers.

## One inconsistency this branch introduced, named

`FeeScheduleController::prefill` now emits `lines[].fee_item_id` as a **uuid** (U8 commit 2) while its
own query parameters `term_id` and `class_level_id` (`:174-175`) remain integer primary keys. One
endpoint, both conventions, in the same request/response pair. That is a consequence of converting the
wire field a screen posts onward without converting the wire fields it is addressed by, and it is the
narrowest example of why this ticket exists.

Note also that those two prefill rules carry `integer` and nothing else — no `Rule::exists`, scoped or
otherwise, unlike the same two fields on `FeeScheduleRequest:57-58` and on the index filter at `:56`.
Whether that matters is a separate question from the id FORMAT and is not analysed here.

## Doing it

Each row is its own change: a uuid rule, a resolution to the integer at the boundary, the Resource that
emits it, and the client that holds it, moved together. The pattern is
`GenerateInvoiceRequest::idForUuid()` — resolve through the scoped model, keep the integer server-side,
never add it back to a Resource. `term_id` / `class_level_id` are the largest single unit because they
are the only complete round trip with a live client.
