# fix/refusals-name-the-bill-and-the-person

Base `staging` @ `555c971e` (confirmed clean before branching). Branch
`fix/refusals-name-the-bill-and-the-person`. One commit.

---

## Headline

**Done, with two deviations, both of which widen the change. Amended once after a cold review
returned seven findings — all seven correct, six acted on, one converted to a ticket.** The
`## The cold review` section below is the record; every number and every proof in this report was
re-derived against the amended tree rather than carried. Every operator-facing
`BusinessRuleException` under `app/Finance/` now names the bill by
`Invoice::displayNumber()` and the person by their name; a token-based arch gate keeps the
next one from being written the old way. The enumeration found **thirteen** construct sites,
not the brief's ten — five of them in `FeeScheduleLineMapper`, which the brief did not name
and which the gate would have refused on day one.

**FULL-REVIEW TIER — subagent review attached, recommend a cold session before merge.** The
brief's two reasons stand: it adds a gate, and it puts a colleague's name into an API
response body for the first time.

---

## Deviations from the brief

**1. Scope widened from ten sites to thirteen — `FeeScheduleLineMapper` is in.**

The brief measured "ten across two actions". Re-derived, the tokeniser found **13 construct
sites** interpolating an identifier: 4 in `ApproveInvoice`, 4 in `ReturnInvoice`, **5 in
`app/Finance/Services/FeeScheduleLineMapper.php`**. The brief's "ten" reconciles exactly if
you count *interpolations* rather than *sites* in the two actions (8 `->uuid` + 2 `user#`
= 10); the five in the mapper are simply a third file it did not reach.

They are in scope for a reason stronger than "the brief said more are in scope": **the Step 5
gate as specified would refuse all five.** Leaving them would have meant shipping the gate
with five exceptions on its first day, which is the "a partial fix to a gate is worse than
the gap" hazard — it converts a known blind spot into an unknown one.

All five reach a client, traced rather than assumed:
`BulkInvoiceRunController::preview()` returns the message as the `refusal` field
(`BulkInvoiceRunController::preview() (app/Finance/Http/Controllers/BulkInvoiceRunController.php:184)`), and
`ProcessBulkInvoiceRun::process()` stores it via `failRun()`
(`ProcessBulkInvoiceRun::process() (app/Finance/Jobs/ProcessBulkInvoiceRun.php:280)`).

**2. Two of the five are made ANONYMOUS rather than renamed — a disclosure the brief's own
argument implies but does not state.**

`FeeSchedule` has a human `label` (school-authored, `string` NOT NULL —
`database/migrations/2026_07_26_130000_create_finance_fee_schedules.php:40`), so the obvious
move is `label` for all five. That is right for three of them and **wrong for two**:

- `linesFor()`'s first guard fires when the schedule belongs to another School. Its label is
  **that other School's authored text**, and the reader is outside that School.
- The second guard fires when the ambient context disagrees with the declared School. Guard 1
  has already passed, so the schedule is the *declared* School's and the reader is in the
  *ambient* one — a different School again.

Rendering the label in either would turn an isolation refusal into a disclosure — a worse
defect than the one this commit fixes. Both now name the schedule by nothing, which is
exactly what `SchoolContext::assertOwns` already does ("That invoice belongs to another
School."). `school#<id>` is retained in both: it is the **caller's own** School, and it is
what keeps the sentence diagnosable now that the subject is anonymous.

**The general rule this rests on, stated so it can be checked:** *a refusal may name an
object in the reader's vocabulary only when the object belongs to the reader's School.* Where
it does not, name nothing. This is narrower than "always use the human label" and is the
reason two of the five diverge from the other three.

**3. Two grammar defects found by Step 6 and fixed — see "Findings from reading them rendered".**

---

## Contradictions of the premise

**None of the brief's premises were false.** Three were checked and held:

| Brief said | Verified |
| --- | --- |
| `Invoice::displayNumber()` exists and reads `SchoolFinanceSettings::invoiceNumberPrefixFor()` | `Invoice::displayNumber() (app/Finance/Models/Invoice.php:187)`. Yes. |
| `User` appends `full_name` via `getFullNameAttribute` | `app/Models/User.php:44` (`$appends`) and `User::getFullNameAttribute() (app/Models/User.php:490)`. Yes. Used via the accessor, not by concatenating columns. |
| `refuseIfAlreadyReturned` / `refuseIfOutWithFinance` correctly have no null branch | Yes — see constraint (d) below. Preserved, with the reason now in a comment. |

One brief instruction turned out to be **unanswerable as posed**, and the answer is more
useful than the question: constraint (a) asks for "the foreign key's on-delete behaviour on
`returned_by_user_id` and `reviewed_by_user_id`". **There is no foreign key on either
column.** See constraint (a).

---

## Step 1 — the enumeration, with denominators

Measured with a `PhpToken` scan of the argument expression of every
`new BusinessRuleException(...)` under `app/Finance/`.

```
FILES EXAMINED:        197   (every .php under app/Finance/, before this change)
TOKENS EXAMINED:       101893
CONSTRUCT SITES:       118   (every `new BusinessRuleException(` in that tree)
SITES INTERPOLATING AN IDENTIFIER: 13

app/Finance/Actions/ApproveInvoice.php:156          [->uuid]
app/Finance/Actions/ApproveInvoice.php:186          [->uuid]
app/Finance/Actions/ApproveInvoice.php:234          [->uuid, literal 'user#']
app/Finance/Actions/ApproveInvoice.php:258          [->uuid, literal 'user#', ->returned_by_user_id]
app/Finance/Actions/ReturnInvoice.php:175           [->uuid]
app/Finance/Actions/ReturnInvoice.php:203           [->uuid]
app/Finance/Actions/ReturnInvoice.php:249           [->uuid, literal 'user#']
app/Finance/Actions/ReturnInvoice.php:273           [->uuid, literal 'user#', ->returned_by_user_id]
app/Finance/Services/FeeScheduleLineMapper.php:100  [->uuid]
app/Finance/Services/FeeScheduleLineMapper.php:120  [->uuid]
app/Finance/Services/FeeScheduleLineMapper.php:132  [->uuid]
app/Finance/Services/FeeScheduleLineMapper.php:152  [->uuid]
app/Finance/Services/FeeScheduleLineMapper.php:166  [->uuid]
```

The `->uuid` / `user#` classification is the brief's rule; the `_id`-suffix column was an
additional, wider heuristic of my own and found nothing the narrower rule missed.

**Does each reach a client? Traced, not assumed.**

| Site | Path to a human |
| --- | --- |
| `ApproveInvoice` × 4 | `InvoiceReviewController::approve()` catches per item and puts the message in `results[].message` of a **207** (`InvoiceReviewController::approve() (app/Finance/Http/Controllers/InvoiceReviewController.php:252)`). |
| `ReturnInvoice` × 4 | `InvoiceReviewController::return()` returns it as a **422** `message` — the catch is at `InvoiceReviewController::return() (app/Finance/Http/Controllers/InvoiceReviewController.php:295)`. |
| `FeeScheduleLineMapper` × 5 | `BulkInvoiceRunController::preview()` → `refusal` field (line 184, message captured at 186); `ProcessBulkInvoiceRun::process()` → `failRun()` on the run row (line 280, message at 282). |

`InvoiceReviewController` is **not** the only path — the mapper's two are separate surfaces,
and finding them is what widened the change.

**After this commit, under the gate's rule: 0 of 118 sites.** Numbers re-derived from the
committed tree in the Proof section below.

---

## Step 2 — `displayNumber()`'s settings read: MEMOISED, measured

`Invoice::displayNumber()` (`app/Finance/Models/Invoice.php:187`) calls
`SchoolFinanceSettings::invoiceNumberPrefixFor((int) $this->school_id)`, which is
**memoised per request, keyed by `school_id`** (`SchoolFinanceSettings::invoiceNumberPrefixFor() (app/Finance/Models/SchoolFinanceSettings.php:43)`,
`$prefixMemo`). It is therefore **one query per School per request, not one per call.**

Measured, not read: with `DB::listen` over a batch of 1, 6 and 12 refusals through the real
endpoint, `finance_school_settings` was queried exactly **1** time in every case. Raw output
in the Proof section.

**The uuid is DROPPED, not kept as a parenthetical.** The brief asked for the argument and I
agree with it after reading the controller: the client already has the uuid. For the batch it
is what the request named and it comes back in each item's own `uuid` key
(`InvoiceReviewController.php:246, 250, 255`); for the single return it is the route segment
the caller supplied. A hex string in the prose adds nothing the caller cannot already
correlate, and it is the half of the sentence an operator cannot use.

---

## Step 3 — the four constraints

### (a) A refusal path must not throw — and the fallback is LIVE code, not dead

**`reviewed_by_user_id` and `returned_by_user_id` have NO foreign key at all.** Both are
plain nullable `unsignedBigInteger` with no `constrained()`
(`database/migrations/2026_08_31_100000_finance_invoices_internal_audit_review.php:123`,
`database/migrations/2026_09_04_100000_finance_invoices_return_to_finance.php:178`), and both migrations say so
outright ("IS A LOOKUP, NOT AN FK", `:50-53` and `:125-128`).

Verified as an absence rather than asserted from the docblocks:

```
$ grep -rn 'reviewed_by_user_id\|returned_by_user_id' database/migrations/*.php \
    | grep -i 'foreign\|constrained\|references'
database/migrations/2026_09_04_100000_finance_invoices_return_to_finance.php:127:
  * Plain nullable `unsignedBigInteger`, no `constrained()`, exactly as `reviewed_by_user_id`
```

The one hit is prose. **No migration in the tree adds a foreign key on either column.**

So the on-delete behaviour is *none* — nothing cascades and nothing restricts — and a user
row can be removed from underneath a live id. **The degraded branch is live code.**
`ReturnedInvoiceQueueController::returnerNames()` already made this same reading for the
queue payload (`ReturnedInvoiceQueueController::returnerNames() (app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:225)`).

`App\Finance\Services\ActorName::forSchool()` returns `null` and never throws;
`byClauseFor()` renders `by someone whose user account can no longer be found`. **It never
falls back to `user#<id>`** — that spelling does not appear in the class at all except in the
docblock explaining why.

Pinned by `ApproveInvoiceTest` arm **g2**, which asserts the exact sentence, and separately
that `user#` and the absent id are both missing, and that it is **not** the grandfathered
sentence — those are different states and an "it threw" assertion would not tell them apart.

### (b) The lookup is scoped — and what enforces the scope

`SchoolScope` **does not apply to `User` at all**: `SchoolScope::apply()`
(`SchoolScope::apply() (app/Models/Scopes/SchoolScope.php:24)`) returns early on a `User` instance and defers
per-school access to `SetSchoolContext`. A bare `User::find($id)` therefore resolves a user in
any School, exactly as the brief says.

**What enforces the scope: `User::hasStandingInSchool(int $schoolId)`**, new in
`User::hasStandingInSchool() (app/Models/User.php:404)`. It is `isSuperAdmin() || legacyAccessibleSchoolIds()->contains()
|| schoolIdsFromRoles()->contains()`.

**It is deliberately NOT `canAccessSchool()`**, and this is the one design call in the commit
that could go either way. `canAccessSchool()` reads `accessibleSchoolIds()`, which returns
**one** of the two access sources depending on `rbac.single_source_access` — default **off**,
so the legacy union only (`school_user` pivot ∪ guardians ∪ `users.school_id`). A user whose
standing in a School comes from a **role in that School's team and nothing else** resolves to
`[]` there — the S7 finding, already recorded on `accessibleSchoolIds()`'s own docblock
(`User::accessibleSchoolIds() (app/Models/User.php:279)`). That false negative would render *"someone whose user account
can no longer be found"* about a real, present colleague. Reading **both** sources cannot
produce that, and still refuses a user with no connection to the School.

Why a user with no standing should be unreachable anyway: both writers run
`SchoolContext::assertOwns($invoice, …)` and then `$actor->can('finance.invoice.approve')` /
`'…reject'` under that School's permissions team, so the id on the row belongs to someone with
standing. The scope is the second expression of that fact, placed where the **disclosure**
happens rather than where the write does.

Pinned by `ApproveInvoiceTest` arm **g3**: a user with standing in School B is written into
School A's `reviewed_by_user_id`, and the arm asserts the refusal contains neither their first
name nor their last name (asserted separately — a first name alone is already a leak).

### (c) The batch N+1 — MEASURED, and accepted

`InvoiceReviewController::approve()` catches per item inside a loop, so a naive resolver costs
one user read and one settings read per refused item. Measured through the real endpoint with
`DB::listen`, at N = 1, 6, 12, every item refusing:

```
BEFORE (staging's ApproveInvoice, restored from HEAD for the measurement):
PROBE n=1   status=207 refused=1  total_queries=9    users=0  settings=0
PROBE n=6   status=207 refused=6  total_queries=18   users=0  settings=0
PROBE n=12  status=207 refused=12 total_queries=30   users=0  settings=0

AFTER:
PROBE n=1   status=207 refused=1  total_queries=14   users=1  settings=1
PROBE n=6   status=207 refused=6  total_queries=23   users=1  settings=1
PROBE n=12  status=207 refused=12 total_queries=35   users=1  settings=1
```

**The cost is +5 queries, CONSTANT in N** (14−9 = 23−18 = 35−30 = 5), not +2N. The five,
captured by logging the SQL of a one-item batch:

1. `select invoice_number_prefix from finance_school_settings …` — `displayNumber()`
2. `select * from users where id = ? limit 1` — `ActorName`
3. `select roles.* … model_has_roles …` — `isSuperAdmin()`
4. `select schools.id … inner join school_user …` — `legacyAccessibleSchoolIds()`
5. `select school_id from guardians where user_id = ? …` — `legacyAccessibleSchoolIds()`

`schoolIdsFromRoles()` never ran: the legacy union already contained the School, so the `||`
short-circuited.

**Decision: accepted, because it is already constant.** `ActorName` memoises on
`"<schoolId>:<userId>"` and `invoiceNumberPrefixFor()` memoises on `school_id`, so a batch of
100 refusals naming one reviewer costs the same five. The worst case is a batch whose refusals
name *k* distinct reviewers, which is 5*k*-ish — bounded by the number of auditors in a
school, not by the batch size.

**The memo is keyed by School on purpose.** `ReturnedInvoiceQueueController` argues against a
static helper for its own page — "a static would survive into the next request under a
long-running worker and serve one school's names to another" (`ReturnedInvoiceQueueController::index() (app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:172)`). That hazard is real
and is closed the way `SchoolFinanceSettings::$prefixMemo` closes it: the key carries the
School, so a memo entry can only ever be returned for the School it was resolved under. What
survives a request is a name that may since have been edited; `ActorName::flushMemo()` exists
for that, as `flushPrefixMemo()` does.

Pinned by `InvoiceReviewEndpointsTest` arm **q2** — six refusals, `users <= 2` (the ceiling is
2, not 1, because the count includes the authenticated actor's own read) and `settings === 1`,
with the batch size a **literal 6** rather than a variable, so the assertion cannot restate
whatever the implementation does.

### (d) The two sites with no null branch — verified, preserved, and now explained

The pairing trigger from `2026_09_04_100000` is installed on **both** `BEFORE INSERT` and
`BEFORE UPDATE` (lines 203 and 204) and its body is:

```sql
IF NEW.returned_at IS NOT NULL
   AND (NEW.return_reason IS NULL OR NEW.returned_by_user_id IS NULL) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
     'finance_invoices: returned_at requires both return_reason and returned_by_user_id.';
END IF;
```

The brief's reading holds: `returned_at` non-null implies `returned_by_user_id` non-null, so
the state `refuseIfAlreadyReturned` / `refuseIfOutWithFinance` guard on cannot exist with a
null id. **No branch was added.** Both methods now carry a comment saying so and naming the
trigger, because the absence is the kind a later reader "fixes".

The two branches that DO exist — `reviewed_by_user_id === null` — are the grandfathering from
`2026_08_31_100000` and stay untouched. They answer a **different** question: a null id means
nobody reviewed it; an unresolvable id means somebody did and we cannot say who. Both actions'
docblocks now say that in one sentence.

### The activity log is UNCHANGED

`git diff` on both actions touches no `withProperties` array. `'invoice_uuid' => $invoice->uuid`
and `'student_id' => $invoice->student_id` are exactly as they were, in both
`ApproveInvoice::handle()` and `ReturnInvoice::handle()`. The gate is built so it cannot red on
them — see Step 5.

---

## Step 4 — the pinned arms

**13 arms across 6 files**, found by grepping the whole suite (not just the two files the brief
named) for each sentence fragment. **One of the 13 was missed by that grep and caught by the
suite**, which is worth recording: `tests/Feature/Finance/FeeScheduleLineMapperTest.php` line 369 pins
`"Fee schedule [{$schedule->uuid}] is {$status->value}"` **without** the trailing clause, so
none of my fragment patterns matched it. It reds, I fixed it, and the count above is the
corrected one.

| File | Arms | What changed |
| --- | --- | --- |
| `tests/Feature/Finance/ApproveInvoiceTest.php` | 2 | both `toContain` → exact `toBe` |
| `tests/Feature/Finance/ReturnInvoiceTest.php` | 2 | both `toContain` → exact `toBe` |
| `tests/Feature/Finance/InvoiceReviewEndpointsTest.php` | 2 | `toContain` kept (a 422 body), name added, `user#` and uuid asserted absent |
| `tests/Feature/Finance/FeeScheduleLineMapperTest.php` | 6 | message text updated; uuid/label absence added |
| `tests/Feature/Finance/BulkInvoiceRunScreenTest.php` | 1 | `assertJsonPath` on the exact refusal, updated |

**Proof none was loosened.** Every change is one of:

- `toContain(...)` → **`toBe(...)`** — strictly stronger. Four arms (`ApproveInvoiceTest` c and
  g, `ReturnInvoiceTest` c and g).
- text updated in place at the **same** assertion strength, plus **added** negative assertions.
  Nine arms.
- **No arm went the other way.** Measured over the raw diff, **with the counting rule stated** —
  the first version of this report gave "removed 10 / added 45" without one, and the cold review
  counted 12 and could not reconcile.

```
$ rtk proxy git --no-optional-locks diff 555c971e -- tests/    # raw: the shell's git wrapper
                                                               # reformats the hunk prefixes
RULE D — removed lines that are part of an expectation chain
         (start with `-`, then `expect(` or `->`):            removed 12
RULE A — removed lines carrying an assertion CALL
         (toBe|toContain|assertJsonPath|assertStatus|assertOk
          |toHaveCount|toBeNull|toBeGreaterThan):              removed 10   added 92
THE LOAD-BEARING ONE — removed lines matching `toBe(`:                    0
```

**Rule D reproduces the review's 12; rule A produces my 10.** The gap is exactly two
`->toThrow(BusinessRuleException::class, "Fee schedule [...] …")` continuation lines, which carry an
assertion but whose call is `toThrow` — absent from rule A's alternation. Both counts are right
under their own rule, and neither is the claim that matters.

**The claim that matters is the third line, and it is rule-independent: zero removed lines contain
`toBe(`.** All 12 removed lines are `toContain`, `toThrow` or the single `assertJsonPath` that was
replaced by an equivalent `assertJsonPath`. The two clause-level `toContain`s that disappeared —
`'cannot be released until Finance resubmits it'` and `'void it and issue a credit note instead'` —
did not vanish: both clauses are now inside the exact `toBe(...)` that replaced their arm, which
pins them **and** everything around them.

**Every updated arm asserts the NAME is present. Five assert the id form is ABSENT**
(`not->toContain('user#')`), and seven also assert the uuid is absent. Arms g2/g3 additionally
assert the absent id and the foreign colleague's two name parts are missing.

**Factory-generated users throughout** (`User::factory()`, `first_name`/`last_name` from
`fake()` — `database/factories/UserFactory.php:31`). No real names in any test, fixture or
seeder.

**New arms added** (not replacements):

- `ApproveInvoiceTest` **g2** — a reviewer id with no user row.
- `ApproveInvoiceTest` **g3** — a reviewer id belonging to another School.
- `InvoiceReviewEndpointsTest` **q2** — the batch resolves each distinct name once.
- `BulkInvoiceRunScreenTest` — the uuid is not in the `refusal` string.

---

## Step 5 — the gate

`tests/Arch/FinanceRefusalsNameNoInternalIdentifiersTest.php` — **713 lines, 12 arms**, group `arch`.
Built on `ReleasedToPayersHasOneDefinitionTest`'s shape as instructed.

**The rule:** no `new BusinessRuleException(...)` under `app/Finance/` builds its message from a
`uuid` or from the literal `user#`.

**How the activity-log carve-out is achieved:** the scanner does two passes. Pass 1 records the
token index ranges that are the **argument expression** of a `new BusinessRuleException(`; pass 2
buckets every token and only judges the ones inside those ranges. `'invoice_uuid' => $invoice->uuid`
in a `withProperties` array is outside every range and lands in `excludedOutside`. Arm 2 is
the source of both, in one method, four lines apart, and asserts the violation list is empty
while `excludedOutside` is 2.

**The marker's left boundary is `(?<![A-Za-z0-9])`, not `\b`, and that is measured rather than
stylistic.** `\buuid\b` does **not** match `invoice_uuid`, because `_` is a word character — so a
refusal built from `$row->invoice_uuid` would have passed a `\b` matcher clean. The
underscore-prefixed spelling is the one this codebase actually uses.

**Three numbers, from the committed tree:**

```
FILES EXAMINED:        198
TOKENS EXAMINED:       102365
CONSTRUCT SITES:       118
EXCLUDED (comment):    141      reason: prose cannot construct a message
EXCLUDED (outside arg):162      reason: the activity-log carve-out — this is the design
UNRECOGNISED:          0        asserted zero
UNRESOLVED new:        0        asserted zero — `new $class(...)` would be a site never visited
UNBALANCED:            0        asserted zero — a range that did not end on its own `)`
VIOLATIONS:            0
```

Re-derived by executing the committed scanner directly, against the exact tree this commit
contains, **after** the last edit under `app/Finance/` rather than before it. The first version of
this report gave `TOKENS EXAMINED: 102310` under this same heading; that figure was carried from an
earlier run and the cold review caught it. `102365` is the current one — every other figure was and
remains exact.

`UNRECOGNISED` is proved wired by an arm that narrows the recognised kinds and watches the same
source that was a violation land in `unrecognised` instead. `UNRESOLVED new` is a second zero the
sibling does not have: it closes "a construct site whose class the scanner could not read", which
would otherwise be a site silently absent from the denominator. It has a positive arm
(`new $class(…)`), a second positive arm (`new ($expr)(…)`, the spelling an earlier draft dropped
silently), **and a known-negative arm** — see the third bite-proof.

### A broken-closed defect in this gate, found and fixed before commit

The first version of `unresolvedNew` bucketed `T_STATIC` and `T_CLASS` alongside `T_VARIABLE`.
`new static(…)` and `new class {…}` are **knowably not** a `BusinessRuleException` — each resolves
to the enclosing class or to an anonymous one declared on the spot — so the gate would have gone
**red on correct code** the day anyone wrote either under `app/Finance/`.

That is the broken-closed shape CLAUDE.md singles out as worse than a red: a gate that refuses
everything is indistinguishable from a strict one until somebody bypasses it, then disables it, and
then you have neither the gate nor the knowledge that it is gone. It was latent rather than live —
`app/Finance/` carries five `new self(…)` (which lex as `T_STRING` and fall through the name check
correctly) and zero of the other two — which is exactly why it would have surfaced months later, on
somebody else's branch, looking like the gate being broken.

**Demonstrated, not asserted** — see the third bite-proof. And the fix ships with its own
known-negative arm, so a future widening of the bucket reds here instead of in production.

**What the docblock says it deliberately does NOT examine**, stated in the file rather than
left to be found: a message assembled into a variable first
(`$m = 'Invoice '.$i->uuid; throw new BusinessRuleException($m);`), `getAttribute('uuid')`,
`$invoice['uuid']`, any other exception class, and any directory but `app/Finance/`. No such
construction exists under `app/Finance/` today — all 118 sites pass their message inline.

**The denominator is asserted before the zero**, and twice: `count($files) > 150` proves files
were read, and `sites > 100` proves the construct the rule is *about* was found in them. A
rename of the exception class would otherwise leave the arm green over zero sites.

---

## Step 6 — every message, rendered

Driven through the real actions and the real mapper against a School with prefix `BSS`
configured. `A2`/`R2` are composed from the source line and labelled as such: they need a
concurrent commit between the guard and the compare-and-swap, which a single-threaded probe
cannot produce.

**THE TWO PERSON NAMES ARE PLACEHOLDERS — `A. B.` and `C. D.` — SUBSTITUTED BY HAND.** The drive
rendered faker output from `portal_testing`, and an earlier version of this section printed it
verbatim on the grounds that faker output is not a real person. That reasoning is wrong in a way
worth writing down, because the whole value of "`user#<id>`, structure and totals" is that a
reader can tell a fabricated name from a copied one **without having to trust a sentence saying
which it is**. A durable report rendering three of them becomes the precedent the next report
cites. The placeholders occupy the same position in the sentence, so nothing about the grammar
being demonstrated is lost.

```
A1  Invoice BSS-000001 is void and cannot be released to its payer.
R1  Invoice BSS-000001 is void; there is nothing for Finance to correct.

A2  Invoice BSS-000002 could not be released; nothing was changed.          (composed)
R2  Invoice BSS-000002 could not be returned; nothing was changed.          (composed)

A3a Invoice BSS-000003 was released before Internal Audit review existed (grandfathered by
    2026_08_31_100000). It cannot be released again.
A3b Invoice BSS-000002 was already released by A. B.. It cannot be released again.
A3c Invoice BSS-000004 was already released by someone whose user account can no longer be
    found. It cannot be released again.

R3a Invoice BSS-000003 was already released to its payer before Internal Audit review existed
    (grandfathered by 2026_08_31_100000). It cannot be returned; void it and issue a credit
    note instead.
R3b Invoice BSS-000002 was already released to its payer by A. B.. It cannot be
    returned; void it and issue a credit note instead.
R3c Invoice BSS-000004 was already released to its payer by someone whose user account can no
    longer be found. It cannot be returned; void it and issue a credit note instead.

A4a Invoice BSS-000005 was returned to Finance on 2026-09-05 by C. D.. It is
    awaiting correction and cannot be released until Finance resubmits it.
A4b Invoice BSS-000006 was returned to Finance on 2026-09-05 by someone whose user account can
    no longer be found. It is awaiting correction and cannot be released until Finance
    resubmits it.

R4a Invoice BSS-000005 was already returned to Finance on 2026-09-05 by C. D.. It
    is awaiting correction.
R4b Invoice BSS-000006 was already returned to Finance on 2026-09-05 by someone whose user
    account can no longer be found. It is awaiting correction.

M1  That fee schedule belongs to another School; it cannot be billed for school#2.
M2  That fee schedule cannot be billed for school#1 from another School's context.
M3  Fee schedule "JSS 1 · First Term 2026/2027" is draft; only an active schedule may be
    billed from.
M4  Fee schedule "JSS 1 · First Term 2026/2027" has no mandatory items, so it cannot produce a
    term bill.
M5  Fee schedule "JSS 1 · First Term 2026/2027" mixes currencies (NGN, USD); its mandatory
    items must agree.
```

### Findings from reading them rendered

**Two were grammatically wrong on the first pass, and both were invisible in the source.** This
is the whole point of the step, so the broken versions are recorded:

1. **The fallback clause swallowed the following clause.**
   `…was already released by someone whose user account can no longer be found and cannot be
   released again.` — "and cannot be released again" reads as a second thing about the
   **account**. Fixed by splitting into two sentences, which reads correctly in all three
   branches (named, grandfathered, unresolvable). Same defect in `ReturnInvoice`'s
   released-to-payer sentence.

2. **The date attached to the wrong noun.**
   `…was returned to Finance by someone whose user account can no longer be found on
   2026-09-05 and is awaiting correction…` — the date reads as when the account could not be
   found. Fixed by putting the date **before** the by-clause:
   `was returned to Finance on 2026-09-05 by …`. This also reads better in the named branch.

**Neither is visible in the source** — the named branch reads perfectly in both cases, and the
fallback is a variable. Only rendering every branch and reading the output finds them.

**On accusation vs. statement of state:** all twelve invoice sentences describe state, not
blame. *"was already released by A. B."* is a fact about the bill, and the remedy
follows in the same message everywhere one exists. Nothing reads as "you did this wrong".

**Two remain developer-facing and are declared so, not defended:** M1 and M2 carry
`school#<id>` and name the schedule by nothing. They are defensive guards for a bug — the
mapper's own comments and `FeeScheduleLineMapperTest`'s arms establish that neither is
reachable through the real call paths — and `school#<id>` is the caller's own School. See
"Findings raised, not fixed".

---

## What changed

| File | ± | What |
| --- | --- | --- |
All counts below are `git diff --stat 555c971e..HEAD` against the committed tree, re-derived after
the last edit. The first version of this table gave the arch file as "new, 469" with "7 arms" while
Step 5 of the same report said 9 — a 13% undercount of the review surface of the largest new file,
contradicted three pages earlier. The cold review caught it.

| `app/Finance/Services/ActorName.php` | **new, 150** | The scoped, memoised, never-throwing actor-name resolver and its `by …` clause. |
| `tests/Arch/FinanceRefusalsNameNoInternalIdentifiersTest.php` | **new, 713** | The gate. **12 arms.** |
| `docs/handoff/tickets/the-schedule-refusal-is-anonymous-while-the-response-names-it.md` | **new, 89** | Review finding 6, recorded not fixed. |
| `app/Finance/Actions/ApproveInvoice.php` | +37 −6 | Four messages; two docblock paragraphs on why the null branches are/aren't there. |
| `app/Finance/Actions/ReturnInvoice.php` | +28 −6 | Same, mirrored. |
| `app/Finance/Services/FeeScheduleLineMapper.php` | +18 −3 | Five messages; the anonymity ruling for the two isolation guards. |
| `app/Models/User.php` | +29 −0 | `hasStandingInSchool()`. Purely additive — the diff contains no other statement. |
| `app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php` | +7 −2 | Corrects a prose claim that this commit was "still queued". |
| `resources/js/lib/internal-audit-queue.{ts,test.ts}` | +11 −5 | A pass-through fixture and two comments that quoted the old sentence. |
| `app/Http/Requests/SyncUserRolesRequest.php`, `tests/Feature/ParentPortalFinanceScreenTest.php` | +2 −2 | Two `User.php:NNN` citations my 29-line insertion shifted. Re-derived, not guessed. |
| 6 test files under `tests/Feature/Finance/` | +257 −40 | 13 updated arms, 4 new ones. |

---

## Proof

Raw, in order. Every command was run; nothing here is transcribed from an earlier run.

**Confirm HEAD**

```
$ git --no-optional-locks rev-parse --abbrev-ref HEAD
staging
$ git --no-optional-locks log --oneline -2
555c971e Merge pull request #415 from notOluwayimika/fix/return-route-in-both-route-oracles
28186e24 fix(rbac): put the IA return route in both route oracles, and correct four prose claims about them
$ git --no-optional-locks status --porcelain
(empty)
```

**The gate, on the committed tree** — see the three-numbers block in Step 5, and:

**ALL OF THE BELOW WERE RE-RUN AFTER THE AMEND**, on the tree this commit contains — a proof is
taken on the tree that will be committed, and the amend changed the gate, three test files, a
service and a controller comment.

```
$ DB_DATABASE=portal_testing bin/db-exclusive ./vendor/bin/pest tests/Arch/FinanceRefusalsNameNoInternalIdentifiersTest.php
EXIT=0
{"tool":"pest","result":"passed","tests":12,"passed":12,"assertions":45,"duration_ms":72}
```

**The eight touched suites** — the six from before, plus the gate and
`InvoiceNumberPrefixTest` (which owns the memo-flush pattern FIX 5 copies)

```
EXIT=0
{"tool":"pest","result":"passed","tests":119,"passed":119,"assertions":658,"duration_ms":56457}
```

**The six touched suites plus the gate**

```
$ DB_DATABASE=portal_testing bin/db-exclusive ./vendor/bin/pest \
    tests/Feature/Finance/ApproveInvoiceTest.php \
    tests/Feature/Finance/ReturnInvoiceTest.php \
    tests/Feature/Finance/InvoiceReviewEndpointsTest.php \
    tests/Feature/Finance/FeeScheduleLineMapperTest.php \
    tests/Feature/Finance/BulkInvoiceRunScreenTest.php \
    tests/Feature/Finance/ReturnedInvoiceQueueEndpointTest.php \
    tests/Arch/FinanceRefusalsNameNoInternalIdentifiersTest.php
EXIT=0
{"tool":"pest","result":"passed","tests":105,"passed":105,"assertions":612,"duration_ms":53620}
```

**Gates**

```
$ ./vendor/bin/pint <13 changed .php files, as an ARRAY, guarded on non-empty>
PINT_EXIT=0   {"tool":"pint","result":"passed"}

$ php bin/ci-authz-lint.php
EXIT=0  authz-lint: OK — no new commented-out authorization checks (0 known).

$ php bin/ci-boundary-lint.php
EXIT=0  boundary-lint: OK — no new boundary violations (8 known temporary exceptions);
        937 files scanned across app/ and tests/.

$ php bin/ci-citation-lint.php
EXIT=0  citation-lint: OK — no new citation violations (164 baselined key(s), 181 citation(s)).

$ php bin/ci-activity-catalogue-lint.php
EXIT=0  52 emitted key(s) across 872 file(s), 29 declared pattern(s), 23 baselined model(s).

$ php bin/ci-money-lint.php                 EXIT=0
$ php bin/ci-identifier-generation-lint.php EXIT=0
$ php bin/ci-dev-namespace-lint.php         EXIT=0
$ php bin/ci-message-text-lint.php          EXIT=0  118 SIGNAL message(s) measured, none over 128
$ php bin/ci-sql-clock-lint.php             EXIT=0
$ php bin/ci-runtime-zero-lint.php          EXIT=0
$ php bin/ci-dependency-integrity-lint.php  EXIT=0
$ php bin/ci-grants-convergence-lint.php staging HEAD
EXIT=0  RbacSeeder.php is unchanged in this diff.

$ DB_DATABASE=portal_testing bin/db-exclusive ./vendor/bin/pest --group=arch
EXIT=0  {"tool":"pest","result":"passed","tests":153,"passed":153,"assertions":759,"risky":1}
        (148 on staging; +5 are this gate's arms — two known-negatives and three from the amend)

$ php bin/ci-dependency-integrity-lint.php
EXIT=0  composer.lock matches composer.json, all 169 locked packages installed

$ composer analyse
EXIT=0  {"tool":"phpstan","result":"passed","errors":0}

$ npx vitest run resources/js/lib/internal-audit-queue.test.ts
EXIT=0  Test Files 1 passed (1) · Tests 24 passed (24)
```

`bin/ci-citation-lint.php` was re-run **after** this report was written, because the report itself
carries `path:LINE` citations and the lint reads them. Every line number in it was re-derived from
the working tree at that point, not carried from an earlier draft.

**`bin/db-exclusive` refused a concurrent run, measured.** Re-running `tests/Feature/Finance`
while an earlier invocation was still alive produced:

```
portal_testing is BUSY — 1 pest process(es) already running.
    61228 php /Users/mac/Documents/Projects/portal/vendor/bin/pest tests/Feature/Finance
Refusing to start: concurrent suites deadlock on `roles` and produce hundreds of
reds that look like a regression. Remember a `git push` IS a suite run.
```

That is the guard's **busy** arm firing correctly; its **free** arm fired on every other
invocation in this session (all of them went through `bin/db-exclusive` and all of them ran).
Worth recording as a self-inflicted lesson in the same family: the refused invocation's `>`
redirect **truncated the still-running first invocation's log file** before the guard refused
it, so that run's output was lost and the suite had to be re-run. A guard that refuses the
command does not refuse the shell's redirection, which happens first.

Every one of these was run with the exit code taken **directly**, never through a pipe.
`bin/ci-citation-lint.php` **failed first** (4 violations, two of them mine and two of them
pre-existing citations my insertion shifted) and the output above is after the fix.
`bin/ci-grants-convergence-lint.php` with no arguments reports `NOT LINTED — could not resolve
base '<empty>'` and exits **1**; it needs the base ref, which is why it is invoked with one.

---

## The watched red

**Bite-proof 1 — a real violation planted in a SECOND Finance file.**

Planted in `RecordPayment::handle() (app/Finance/Actions/RecordPayment.php:84)`, replacing
`'Cannot record a payment against a void invoice.'` with
`'Invoice '.$invoice->uuid.' is void; cannot record a payment.'`:

```
EXIT=1
{"result":"failed","tests":7,"passed":6,"failed":1,"failures":[{
  "test":"…it_constructs_no_finance_refusal_whose_message_names_a_uuid_or_a_user_",
  "line":313,
  "message":"Failed asserting that two arrays are identical.\n--- Expected\n+++ Actual\n
   -Array &0 []\n+Array &0 [\n+    0 => Array &1 [\n
   +        'file' => 'app/Finance/Actions/RecordPayment.php',\n
   +        'line' => 84,\n+        'text' => 'uuid',\n+    ],\n+]"}]}
```

It named the **right file, the right line, and the right token**. Restored:

```
EXIT=0  {"result":"passed","tests":7,"passed":7,"assertions":25}
$ git --no-optional-locks diff --stat -- app/Finance/Actions/RecordPayment.php
(empty — restored byte-exact)
```

**Bite-proof 2 — the SAME text in a comment, in the same real file, must NOT red.**

Inserted at `RecordPayment::handle() (app/Finance/Actions/RecordPayment.php:84)`:
`// Once read: new BusinessRuleException('Invoice '.$invoice->uuid.' by user#7 is void.')`

```
EXIT=0  {"result":"passed","tests":7,"passed":7,"assertions":25}
$ git --no-optional-locks diff --stat -- app/Finance/Actions/RecordPayment.php
(empty — restored byte-exact)
```

That arm is what earns the tokeniser over a grep: this commit's own explanations quote the
strings it removed, and a comment-blind matcher would red on them and be "fixed" by deleting
the explanation.

**ALL THREE BITE-PROOFS WERE RE-RUN AFTER THE AMEND**, against the rewritten walker, because the
instrument changed and a proof taken on the old one proves nothing about the new one. EXAMINED is
reported for each, and the token count moves with the plant — which is itself the check that the
plant landed:

```
                                     FILES TOKENS  SITES COMMENT OUTSIDE UNREC UNRES UNBAL VIOL
baseline, no plant                     198 102365    118     141     162     0     0     0    0
A  uuid interpolation planted          198 102371    118     141     162     0     0     0    1   RED
B  same text in a comment              198 102369    118     142     162     0     0     0    0   green
C  new static / new class              198 102400    118     141     162     0     0     0    0   green
```

Note where each plant lands: A moves `VIOL` to 1, B moves `COMMENT` 141 → 142 and `VIOL` stays 0,
C moves neither and leaves `UNRES` at 0. Three different buckets, three different plants — which is
how you tell a gate that classifies from one that merely counts.

`git status --porcelain` on `RecordPayment.php` was empty after each restore.

**Bite-proof C — the KNOWN-NEGATIVE arm of the gate, which is the one that matters.**

`new static()` and `new class { public int $n = 1; }` planted in
`RecordPayment::handle() (app/Finance/Actions/RecordPayment.php:80)` — both perfectly correct
PHP that no rule here forbids.

With the shipped classification: **green.**

```
AFTER_FIX_EXIT=0
{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":31,"duration_ms":72}
```

With `T_STATIC`/`T_CLASS` put back into the `unresolvedNew` bucket — the first version of this
file — the **same plant** reds three arms:

```
PRE_FIX_EXIT=1
{"result":"failed","tests":9,"passed":6,"failed":3,"failures":[{
  "test":"…it_constructs_no_finance_refusal_whose_message_names_a_uuid_or_a_user_",
  "line":323,
  "message":"…-Array &0 []\n+Array &0 [\n
   +    0 => ['file' => 'app/Finance/Actions/RecordPayment.php', 'line' => 80],\n
   +    1 => ['file' => 'app/Finance/Actions/RecordPayment.php', 'line' => 81]]"}, …
```

Both the classification and `RecordPayment.php` restored; `git status --porcelain` on that file
is empty.

**A fourth red, unplanned and more useful than any of them.**
`tests/Feature/Finance/FeeScheduleLineMapperTest.php` line 369 pinned a message my Step-4 grep
did not match, and reded on the first suite run naming all four datasets. Recorded because it is
direct evidence the arm-hunt by grep was incomplete — the suite, not my search, is what closed
it.

---

## Database observations

No schema change, no migration, no seeder change. `RbacSeeder.php` untouched (confirmed by
`bin/ci-grants-convergence-lint.php`). Nothing was written to `portaa10_portal`.

All measurement ran against `portal_testing` under `RefreshDatabase`, one consumer at a time
(`bin/db-exclusive` for every invocation; `ps -eo command | grep -c '[p]hp.*vendor/bin/pest'`
returned **0** before the first run).

Query counts as measured, structure only: **+5 constant** per request that renders at least one
refusal naming a person, of which 1 is `finance_school_settings`, 1 is `users`, and 3 are the
access-source reads behind `hasStandingInSchool()`.

---

## Not done

- **No screen was driven.** No Inertia page, controller signature or payload shape changed —
  only the text of a `message` string a client already relayed verbatim. The two client
  surfaces are covered by tests (`InvoiceReviewEndpointsTest` q/q2/r, `BulkInvoiceRunScreenTest`)
  and the frontend's own vitest arm asserts pass-through rather than content. **If the project
  lead wants the sentence seen in a browser, that is a `finance-drive` run I did not do.**
- **`A2` and `R2` were composed, not driven.** The lost-race sentences need a concurrent commit
  between the guard and the compare-and-swap. They contain no name and no id beyond
  `displayNumber()`, so the gate covers them and the render is a formatting check only.
- **The full suite was not run — `bin/quality` is the project lead's to run.** What WAS run to
  completion: the six affected files plus the gate (105/105), the whole `arch` group (148/148),
  and the whole of `tests/Feature/Finance`. Not run: everything outside those —
  `tests/Feature/Rbac`, `tests/Feature/Isolation`, the rest of `tests/Feature`, and the `tsc`
  ratchet. `app/Models/User.php` gained a method and nothing else, so the blast radius outside
  Finance is a new symbol with two callers, but that is an argument rather than a measurement and
  I am not claiming it as one.

- **The cold review corroborates none of these figures**, because it ran no tests — see the review
  section. Every suite number in this report has one witness.
- **`ActorName::flushMemo()` has no caller and no arm.** It exists for a long-running worker
  and for a test that renames a user mid-request, exactly as `flushPrefixMemo()` does, and
  neither exists today. This is a primitive slightly ahead of its consumer; I judged the
  symmetry with the existing memo worth more than the omission, but it is a deviation from
  "don't front-load a primitive" and the reviewer should weigh it.

---

## The cold review, and what this amend did about it

A `finance-reviewer` subagent was spawned in its own worktree with nothing but the report path and
the branch name. It returned **seven findings and a verdict of "ship with fixes"**. All seven were
correct. This amend acts on six and converts one to a ticket.

**Two things about the review itself, recorded because they are process evidence rather than
courtesy.**

**It noticed it had been given the wrong tree.** The worktree it was launched in sat at `2488d351`,
~90 commits behind `staging`, so its files were not this branch. In its own words:

> "a reviewer reading it would have been reading a different codebase and could not have known."

It exported the committed branch with `git archive` into scratch and read that instead — which also
made another session's untracked files invisible **by construction** rather than by discipline. The
isolation the handbook asks for was achieved by the reviewer noticing the tooling had failed to
provide it. That is a gap in the spawn path, not a credit to the process: the next reviewer may not
check.

**It ran the gate's scanner DIRECTLY rather than trusting the Pest green.** That is the only reason
finding 1 exists — the gate was green, had been bite-proven in both directions, and was silently
blind to two token shapes. Verifying the instrument rather than its output is this repository's
stated standard for gates, and this is the first time it has been done to a gate here without being
asked.

**And what it did NOT do: it ran no tests at all.** No Pest, no Pint, no PHPStan, no tsc, no
vitest — its export has no `vendor/` and it did not enter the branch's checkout. So **the
1207/1207, 105/105, 150/150 and 9/9 figures in this report remain this report's own claim and are
not corroborated by the review.** A clean review here does not mean a passing suite; it means a
reader who re-derived the gate's assertions by hand found them sound. Those are different claims and
only one of them has two witnesses.

| # | Finding | Grade | Action in this amend |
| --- | --- | --- | --- |
| 1 | The gate's range-walker tracked bracket depth by token TEXT, so `#[Attr]` (`T_ATTRIBUTE`, text `#[`) and `"${k}"` (`T_DOLLAR_OPEN_CURLY_BRACES`, text `${`) took closers they never counted openers for — ending the range early and dropping the argument's tail into the activity-log bucket. A refusal naming `user#` passed clean, `unrecognised` still zero. | fix | **Fixed and bite-proven.** See below. |
| 2 | Three report numbers did not reproduce against the tree they cited. | ticket → **fix** | **Upgraded by the project lead and corrected.** `TOKENS EXAMINED` re-derived; the "469 lines / 7 arms" row was 713/12; the assertion tally now states its counting rule and reconciles both counts. |
| 3 | This report's own finding #4 was graded `fix` and shipped unfixed. | ticket | **Regraded to `ticket` on a measurement, and moved to its own ticket file** — `docs/handoff/tickets/the-returned-bills-queue-resolves-names-without-a-standing-check.md`. Leaving the regrade in a per-branch report was the same defect one layer up. See finding 4 below. |
| 4 | `ActorName`'s Constitution-13 comment declined a `users.school_id` filter and then deferred to a predicate that reads that column. | ticket | **Comment rewritten.** Behaviour unchanged. |
| 5 | `ActorName::flushMemo()` had no caller; the suite's safety was an accident of no test using `DatabaseMigrations`. | ticket | **Given three callers.** Verified `DatabaseMigrations` = 0 across `tests/` (control: `RefreshDatabase` = 264 files), so latent, not live. |
| 6 | The refusal is anonymised while `BulkInvoiceRunController` renders the same schedule's `uuid` and `label` two keys away. | ticket | **Ticket written**, not fixed — `docs/handoff/tickets/the-schedule-refusal-is-anonymous-while-the-response-names-it.md`. |

**Two of the seven left the report entirely**, which is the point of this row and the one above it:
findings 3 and 6 are now files under `docs/handoff/tickets/`. Nothing in this report is the sole
record of an open finding.
| 7 | Person-shaped names in a durable report and a committed TS fixture. | ticket | **Replaced with `A. B.` / `C. D.`** in both. Swept the branch; see below. |

### Finding 1 — the fix, and the fix I nearly shipped instead

**Measured on the project's own PHP (8.3.32), both shapes:**

```
dollar-curly : T_DOLLAR_OPEN_CURLY_BRACES=[${] T_STRING_VARNAME=[k] CHAR=[}]
curly-open   : T_CURLY_OPEN=[{] CHAR=[}]
attribute    : T_ATTRIBUTE=[#[] CHAR=[]] CHAR=[(] CHAR=[)] CHAR=[{] CHAR=[}]
```

`T_CURLY_OPEN`'s text **is** `{`, which is why `"{$k}"` was caught all along and `"${k}"` was not.

**THE FIRST FIX WAS WRONG IN THE WAY THIS WHOLE BRANCH IS ABOUT.** I added the three token ids to
the increment branch and, alongside it, a guard asserting the range ends on the `)` matching its
`(` — writing it up as "the half that survives a PHP upgrade", since `${` is removed in 8.4 while
`#[` is permanent. **Then I measured it, and it never fires.** Traced on this machine:

```
CHAR [(]  depth 0->1        CHAR [(]   depth 1->2       T_ATTRIBUTE [#[] depth 2->2
T_STRING [Foo] 2->2         CHAR []]   depth 2->1       T_FN [fn]        1->1
CHAR [(] 1->2               CHAR [)]   2->1             T_DOUBLE_ARROW   1->1
T_CONSTANT_ENCAPSED_STRING 1->1        CHAR [)]   depth 1->0   <== RANGE ENDS HERE
```

The range ends on a **genuine `)`** — a nested one, early — so the exit-shape check is satisfied
throughout. Shipping that sentence would have been a description asserting a property its artifact
does not have, in the commit correcting exactly that, one layer down.

**What shipped instead needs no list of token names.** The depth count is over bracket
**characters in the token text**, excluding the string-like and comment kinds whose brackets are
content (`'a(b'`, a heredoc body, `// (`). Any future token embedding a bracket is handled the day
it exists. The exclusion is over *lexical categories*, which is a far more stable set than "tokens
that open a bracket". The exit-shape guard is kept as a cheap secondary — it does catch the
unterminated case and a negative depth — and its docblock now states plainly that it did **not**
catch the `#[Attr]` defect.

**Behaviour-preserving on the real tree**, measured: the rewritten scanner returns byte-identical
figures over `app/Finance/` — 198 / 118 / 141 / 162 and four zeros.

**Bite-proven both directions.** Reverting the counting to single-character tokens — which *is* the
original defect — reds exactly the two new arms:

```
PRE_FIX_EXIT=1   tests=12 passed=10 failed=2
  RED: it_catches_a_refusal_whose_argument_carries_an_ATTRIBUTE…   line 441
       Failed asserting that actual size 0 matches expected size 1.
  RED: it_catches_a_refusal_interpolated_as____…____whose_opener_is_not_the_bare_brace…  line 466
       Failed asserting that actual size 0 matches expected size 1.

RESTORED_EXIT=0  {"result":"passed","tests":12,"passed":12,"assertions":45}
```

`size 0` is the false green itself: the violation was not merely missed, it was **absent**.

The third new arm is the inverse — both shapes written in a **comment** must not red — because this
file now quotes both in its own docblock, and a fix that widened the matcher into prose would make
the cheapest repair deleting the explanation.

The `${` arm builds its source **by concatenation rather than as a literal**, because `"${k}"` is a
parse error on PHP 8.4: only the tokeniser ever sees it, so the file keeps lexing after the upgrade
that removes the syntax, and on that version the arm still passes because `${k}` and `{$k}` become
the same token stream.

**The docblock's stated blind spots were corrected too.** It listed its exclusions by name and
neither shape was among them, and `refusalCodeKinds()` carried `// "${uuid}"` — true about the
classifier, false about the coverage. An undeclared blind spot is worse than a declared one; this
file is now the worked example of that in both directions.

### Finding 7 — the sweep and its denominator

Instrument: every `^+` line of `git diff 555c971e..HEAD`, matched for `\b[A-Z][a-z]{2,}\s+[A-Z][a-z]{2,}\b`.
Denominator: **18 changed files**.

```
BEFORE                          AFTER
  4  Internal Audit               4  Internal Audit     (domain term)
  3  First Term                   3  First Term         (domain term)
  3  Elinore Kautzer              1  Test Files         (vitest output)
  2  Francis Medhurst
  1  Test Files
  1  Ada Bello
```

**Six occurrences of three person-shaped names → zero.** Five were in this report's Step 6, one was
the fixture at `resources/js/lib/internal-audit-queue.test.ts`. Nothing else in the branch matches.

## Findings raised, not fixed

1. **`InvoiceReviewController::return()`'s 200 body still returns `returned_by_user_id`**
   (`InvoiceReviewController::return() (app/Finance/Http/Controllers/InvoiceReviewController.php:308)`). Not a refusal message, so
   outside this brief's rule and outside the gate — but it is the same `user#<id>` vocabulary
   crossing the same wire on the same endpoint, one status code over. The queue endpoint next
   door already resolves the name instead. **ticket.**

2. **`M1`/`M2` still carry `school#<id>`** (`FeeScheduleLineMapper::linesFor() (app/Finance/Services/FeeScheduleLineMapper.php:108)` and
   line 132). An operator cannot act on a School id either. Both are unreachable defensive guards
   and widening the gate to `school#` would trip legitimate uses, so it is a separate decision
   with its own blast radius. **ticket.**

3. **`SetSettlementAccount` console output uses `user#%d` and `school#%d`**
   (`SetSettlementAccount::handle() (app/Finance/Console/SetSettlementAccount.php:98)`, and line 135). A console command's operator is
   whoever runs artisan, so this is arguably correct as-is; recorded so the next reader does not
   assume the sweep missed it. **no action suggested.**

4. **`ReturnedInvoiceQueueController::returnerNames()` resolves names UNSCOPED**
   (`ReturnedInvoiceQueueController::returnerNames() (app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:225)`) and concatenates
   `first_name.' '.$last_name` rather than using the `full_name` accessor. It is the same
   disclosure surface this commit scoped, on the screen that argued for naming people in the first
   place, and the two now disagree about both scoping and the accessor.

   **REGRADED `fix` → `ticket`, ON A MEASUREMENT, and the measurement is the reason.** This was
   graded `fix` in the first version of this report and then shipped open — which the cold review
   correctly called out: `fix` means ship-blocking, so a `fix` left open is either mis-graded or a
   known blocker being shipped, and the report said neither. The regrade is not a retreat from the
   grade; it is the grade being set by evidence that did not exist when it was first assigned.

   Both measurements the change was gated on were taken. The first is clean: swapping the resolver
   for `ActorName` and running `ReturnedInvoiceQueueEndpointTest` gives **14 passed, 52 assertions**
   — no existing arm changes what it asserts. The second is not:

   ```
   one page of 10 returned bills, identity queries counted from the query log
                                     total queries      users/pivot/guardian/roles reads
   SHIPPED  (one whereIn)   k=1            14                        5
                            k=3            13                        5
                            k=10           13                        5
   ActorName (per distinct) k=1            18                        8
                            k=3            25                       16
                            k=10           53                       44
   ```

   **The shipped shape is constant in k; `ActorName` is ~4 queries per distinct returner.** At this
   endpoint's own page cap of 100 (`MAX_PER_PAGE`) that is ~400 extra queries on one page. The
   review estimated "two lines"; the measurement says the two-line version is a linear-in-k
   regression on a paginated screen, so the brief's own stop condition applies and it was not
   forced.

   The cost is not incidental to `ActorName` — `hasStandingInSchool()` is per-user by construction
   (three access sources, `||`-short-circuited), so any batch entry point must batch the *standing*
   check too, and that is a change to `app/Models/User.php`'s access resolution rather than to
   Finance. That is the shape of the real fix and it is larger than this commit.

   **THE DURABLE RECORD IS THE TICKET, NOT THIS ENTRY:**
   `docs/handoff/tickets/the-returned-bills-queue-resolves-names-without-a-standing-check.md`.

   An earlier version of this entry left the regrade here and said no ticket existed yet. That was
   wrong for the same reason the regrade itself was raised: **a report is read once, by the person
   who asked for it; a ticket is what the next person finds.** A finding whose grade moved on a
   measurement, and whose fix has a named shape and a named trap, has its home in
   `docs/handoff/tickets/`. The ticket carries the re-derived query counts, the `4k + 4` fit, the
   page cap, the batch entry point as the closing shape, and the memo-agreement trap. This entry is
   the pointer.

   **Two measurements, one probe defect worth recording.** The first version of the query probe
   registered a `DB::listen` closure per loop iteration capturing the counter **by reference** —
   one variable, one function scope — so iteration 2 had two live listeners incrementing it and
   iteration 3 had three. It reported 9 / 34 / 135. An instrument that miscounts itself, inside the
   probe written to settle a cost question. Re-measured with `DB::enableQueryLog()`.

5. **Every `*_by_name` resource in Finance resolves unscoped too** — nine of them, all
   `whenLoaded(...)->name` on a `BelongsTo` (`VoidRequestResource` 49 and 54,
   `CreditNoteResource` 51 and 60, `OpeningBalanceBatchResource` 55, `FeeScheduleChangeResource` 68,
   `DiscountPolicyChangeResource` 77, `BulkInvoiceRunResource` 104, `ManualInvoiceRunResource` 43).
   The relation's own foreign key makes a cross-School id unreachable in practice, which is why
   this is one line and not four. Recorded so the scope decision in `ActorName` is understood as
   **stricter than the house convention**, not as an implementation of it. **ticket.**

6. **The `arch` group reports 1 risky test** (unchanged by this commit — present before it).
   Not investigated. **ticket.**
