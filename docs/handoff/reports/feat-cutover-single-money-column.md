# Report — the cutover file is one money column (§9)

**Branch:** `feat/cutover-single-money-column` · **Base:** `staging` @ `f9abe6a` ·
**Shape:** 9 files, one commit, **no migration** · **Gate:** `bin/quality` PASS 14/14 raw (see *The two reds* below).

Written for someone who did not do the work.

---

## The STOP condition, verified before anything was written

The brief said to stop if the posting path needed editing. **It does not.**
`app/Finance/Actions/PostOpeningBalanceBatch.php` was read in full and not touched:

Citations RE-DERIVED at `1311dfd` after a cold review found every one of them 3–5 lines off. The
claim was true and the line numbers did not support it, which is the same defect as an unverified
assertion:

- **Zero** — `if ($balance->isZero())` at **`:192`**, `continue` at `:196`. Posts nothing. Note the
  condition is on the ROW's own balance, which is the fact behind finding 4.2 below.
- **Step 1** — `if (! $balance->isNegative())` at **`:199`**, the `$this->ledger->post(` that follows
  at **`:203`**. One ledger charge per positive ROW, narration `fee_type_label . NARRATION_SUFFIX`,
  dated `cutover_date`.
- **Step 2** — accumulation at **`:225`** (`$creditByStudent[$studentId] = isset(...)`), the netted
  `Payment::create` at **`:241`**, its ledger credit at **`:291`** (`LedgerEntryType::Payment`). One
  migrated payment per student — credits netted against CREDITS, never against charges.

A single synthetic fee type feeds that unchanged, which the drive then confirmed against real rows.

## Deviations and decisions the brief left to me

**1. L2 needed a left-hand side, or it would have been permanently red.**
`statedTotalSum` summed the *stated* student totals. With `student_total_balance` optional and absent,
that is Σ over zero students, so every conforming file would report the operator's entire control
total as a mismatch — L2 turned off by making it always fail. It now **derives** a student's position
from their own balances when the file states no total, counts how many were derived, and **names that
split in the mismatch message**. The honesty cost is written into the code: for a derived student L2
no longer compares two independent figures — it catches a mistyped control total, a truncated file
and a partial extract, and does *not* catch an inverted sign, which is precisely why the
interpretation summary exists.

**2. L1 rejected every row of a file with no totals.** `l1Verdict` counted a missing balance and a
missing total in one counter and returned `l1_not_checkable`, which rejects the student's whole
row-group. The two absences are now counted separately: a missing **balance** still rejects, a group
stating **no** total is *not applicable* (no finding), and a group stating **some** totals and not
others is still rejected — the file offering the check and withholding it in the same breath.

**3. `Last Amended On` is IGNORED, and that is a decision, not an omission.** It is when the record
was touched, which may not be a payment, so mapping it onto `received_at` would assert something
false. Carrying it as provenance would need a staging column — a migration — which the fuse excludes,
and its predecessor `last_payment_date` was deliberately retired
(`2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file.php`). Unknown columns are
already read past silently, so the file needs no editing; the template's notes now say so explicitly,
including that it is not a payment date.

**4. One row per student is now enforced, not summed.** With the fallback applied, the row key
`(school, batch, admission, fee_type_label)` collapses to one row per student, so a second line for
the same student is refused as `duplicate_row_key_in_file`. That is the correct reading of a
single-column extract — two closing balances for one student is a file defect, not an instruction to
add them — and it is stated at the code that causes it.

## A measured observation the brief did not ask for

**Brookstone's raw header is refused, loudly and early.** Driven exactly as sent:

```
$ finance:import-opening-balances --file=brookstone.csv …
Missing required column(s): admission_number.
```

`read()` lowercases and trims header cells but does not fold spaces, so `Admission Number` does not
match `admission_number`. This is the designed flow — the platform issues the template (R13) and the
operator pastes into it. I did not add header aliasing: it is outside the fuse, and silently
accepting a differently-named column is also how a wrong file gets in.

**CORRECTION (cold review, finding 1.2).** An earlier version of this paragraph said the refusal
happens "before a batch row is written, so no idempotency key is spent". **That is true of the
console and FALSE of the screen**, which is the path an operator will actually use.
`OpeningBalanceBatchController::store()` inserts the batch at `:148` — before the queued job parses a
byte, deliberately, so §7's key is enforced by the engine and the operator has something to poll —
and `StoreOpeningBalanceImportRequest::batchReference()` defaults the reference to the uploaded
FILENAME, which the screen pre-fills. So an operator who tries the raw extract, sees it refused,
corrects the header and re-uploads **the same filename** hits `unique(school_id, batch_reference)`
and gets a 409 on a file that is now correct. Nothing un-spends it; a batch row is never deleted.

That consequence now sits in `docs/handoff/post-deploy-tasks.md` beside the paste-into-the-template
line, because the person it bites is the one running the cutover at the time they are least able to
diagnose it.

## What changed

`OpeningBalanceFileValidator` — three `required` flags flipped (`wcbs_student_ref`,
`fee_type_label`, `student_total_balance`); **no column deleted**, so the per-fee-type capability
survives in the file and a future extract that can split does so with no code change. Two new
constants: `SIGN_CONVENTION` (the project lead's words, quoted, not paraphrased) and
`FALLBACK_FEE_TYPE_LABEL = 'General Arrears'`, applied once at the top of the row loop so the
duplicate key, the length check, the staged row and the narration all see the same string.

`OpeningBalanceInterpretation` (new) — the control that did not exist. Computed from the staged rows
on every read, never stored, so it cannot describe a batch other than the one that would post.
Classifies **per student by net position**, matching how step 2 nets a student's credits. Counts and
aggregates only — no id, no per-student figure.

Surfaced in three places: the console report (every run, clean or not), the batch payload the
operator screen polls, and the screen itself — rendered directly above **Submit for approval**, which
is the last moment anyone can refuse it.

The template's notes gained the sign convention, the Excel leading-zeros warning, the
single-column instructions, and the extra-columns note. The screen renders the same constants, so all
of it reaches both surfaces from one edit.

## Verification

**Watched reds — every mutation verified landed in the source before the run.** No count in this
heading, deliberately: it said "four" over a five-row table and then a six-row one, and a number
beside a list it does not govern is a number that goes stale twice.

| Mutation | Result |
|---|---|
| Invert the posting's sign test (`! isNegative` → `isNegative`) | **RED** 8/11 — the charge arm and the payment arm each failed on their own message |
| Delete the fallback assignment | **RED** 9/11 — the label arm and the narration arm |
| Delete L1's not-applicable branch | **RED** 1/11 — ten arms; this branch is what makes Brookstone's file readable at all |
| Hardcode the summary's direction (`elseif ($net->isNegative())` → `elseif (true)`) | **RED** 10/11 — the reversal arm |
| Scale the summary's figures by 1/100 in `naira()` | **RED** 11/12 — the magnitude arm ONLY; see *The magnitude question* below |
| Restore the original false control-total NOTE | **RED** 10/11 in the template suite — the new NOTES gate, bite-proved against the note that actually shipped |

**Four arms in the existing suite contradicted this commit and were restated, not weakened** — each
with the reason written into the test:

- `rejects a blank fee_type_label` → now asserts it takes the constant, and still asserts it is never
  staged as `''` (which would reach a statement as `" — Balance Brought Forward"`).
- the missing-required-column abort named `student_total_balance`, now optional → uses `balance`, the
  one column with nothing to fall back to.
- the R12 freeze → four optional columns, and it now **also asserts positively** that
  `['admission_number', 'balance']` are required, so a future "relax one more" has to argue here.
- an L2 message assertion → wording only; the behaviour (a two-totals group is excluded) is unchanged.

`tests/Feature/Finance` — **464/464** after remediation. New file 14/14.

**Drive on `portal_drive`, Brookstone's actual six rows** — −846,100 · 0 · 29,000 · −2,597,500 ·
−61,800 · 0 **NAIRA**, Σ = −₦3,476,400.00:

```
L2 (kobo): Σ stated student totals=-347640000, --control-total=-347640000, Δ=0

WHAT THIS FILE SAYS — read it, and stop if it is wrong:
  NEGATIVE = the school owes the family; they paid in advance and are in CREDIT. POSITIVE = the
  family owes the school; ARREARS. Figures are NAIRA.
  3 student(s) are in CREDIT — the school owes them ₦3,505,400.00 in total. 1 student(s) are in
  ARREARS — they owe the school ₦29,000.00 in total. 2 student(s) are square and will post nothing.
  Net: the school OWES FAMILIES ₦3,476,400.00. If that is not what this file means, the sign
  convention is inverted — do not approve it.

Clean: every row validated, both checksum levels hold. Batch status: validated
```

**That sentence is the control, and it is what will be quoted at whoever runs the cutover.**

Staged (`Last Amended On` present in the file and ignored):

```
line 2 STU-BX-001  -84610000  label=General Arrears  ok
line 3 STU-BX-002          0  label=General Arrears  ok  nothing_to_post
line 4 STU-BX-003    2900000  label=General Arrears  ok
line 5 STU-BX-004 -259750000  label=General Arrears  ok
line 6 STU-BX-005   -6180000  label=General Arrears  ok
line 7 STU-BX-006          0  label=General Arrears  ok  nothing_to_post
staged=6 read=6 findings=0
```

Posted:

```
ledger charge     2900000  General Arrears — Balance Brought Forward
ledger payment  -84610000  Payment #900000001 — Balance Brought Forward
ledger payment -259750000  Payment #900000002 — Balance Brought Forward
ledger payment   -6180000  Payment #900000003 — Balance Brought Forward
payment ref=900000001  84610000 origin=migrated bank=NULL received_at=2026-08-06
payment ref=900000002 259750000 origin=migrated bank=NULL received_at=2026-08-06
payment ref=900000003   6180000 origin=migrated bank=NULL received_at=2026-08-06
batch status now: posted
```

One charge for the one positive, three migrated payments for the three negatives, references from the
reserved band, `bank_account_id` NULL, dated the cutover — and **nothing at all for the two zeros**.

## The magnitude question, settled from the rendered page

An earlier revision of this report quoted the drive as saying **"Net: the school OWES FAMILIES
₦34764.00"** on a file whose Σ is −3,476,400 — exactly ÷100 — while L2 read Δ=0 on the same run. Two
explanations were possible: a naira figure fed to a kobo-expecting formatter (a defect in the one
control guarding the sign convention), or a harness artifact.

**It was the harness, and the fault was mine: I built the drive CSV by writing Brookstone's NAIRA
figures as if they were kobo** (`-8461.00` where their extract says `-846100`). Every internal figure
was then self-consistent, which is why L2 read Δ=0 and nothing looked wrong.

Settled by reading the page rather than the console. There is no browser plugin in this repo, so the
closest available reading was taken: the exact payload the screen polls, put through the page's own
`formatNaira` — the function extracted from `resources/js/lib/format.ts` at runtime rather than
reimplemented — plus the `sentence` string verbatim. That is what the two components render; it is
not a screenshot, and I am not claiming it is one.

```
--- THE STAT TILES, as the page renders them (formatNaira) ---
In credit : 3 · ₦3,505,400.00
In arrears: 1 · ₦29,000.00
Square    : 2
Net       : -₦3,476,400.00
--- THE SENTENCE, as the server sent it (BEFORE the fix below) ---
… the school owes them ₦3505400.00 in total … Net: the school OWES FAMILIES ₦3476400.00 …
```

Magnitude correct in both. **But reading the page found a real defect the console had hidden:** the
same figure rendered two ways inches apart — `₦3,476,400.00` in the tile, `₦3476400.00` in the
sentence, because `Money::toNaira()` returns an ungrouped machine form. That is not cosmetic on this
string. The sentence's entire mechanism is that a human reads a figure and agrees with it or refuses;
a seven-digit run with no groups is precisely what hides a magnitude error, which is precisely what
the control is for. `OpeningBalanceInterpretation::naira()` now groups, matching `formatNaira`
including sign placement — string work only, no arithmetic and no `number_format` (which casts to
float).

**And the arm that would have caught it did not exist**, which is why a drive was what raised it. The
other arms assert counts and direction; none asserted the FIGURES, so a summary off by a factor of
100 would have passed every one of them. Added: the sentence pinned verbatim against Brookstone's
real six rows, plus the kobo behind it so a formatter change cannot move the underlying figure.
Watched red by scaling `naira()` by 1/100 — **11/12, only the new arm**, confirming nothing else was
pinning the magnitude.

## An accepted consequence of the ruling, stated not buried

After this cutover the portal **cannot answer "how much of this arrears is tuition"** for migrated
balances. Brookstone cannot supply the split, so every migrated line is filed under
`General Arrears`. This was already a carried ticket; the ruling makes it permanent for cutover data.
It is a **cost of the decision, not a defect** — and the mechanism to do better survives untouched:
the column is still in the format and an extract that can split is honoured with no code change.

## The two reds, and what they actually were

The pre-push hook blocked twice after a clean 14/14 at 20:37. Captured before re-running, per the
standing rule.

**Both reds had an IDENTICAL failing set of 7** — deterministic, so not the intermittent flake:
four `ActivityLogApiTest` arms, `AuthenticationTest::users are rate limited`, and two
`GuardianProfileTest` arms. None in finance; my diff touches none of those files. The leaked rows
carry another test's timestamps, so this is the cross-test pollution already on record — and worth
noting, it implicates **both** suspects previously listed as untested (the rate limiter and the
activity log), not the permission cache.

**But those seven were never the blocker.** All seven are in `tests/ratchet-baseline.txt`, and
running the ratchet against the captured junit says so:

```
$ php bin/ci-test-ratchet.php junit-20260810-205544-38252.xml
ratchet: OK — no new failures beyond the baseline (7 known-failing).
```

The actual blocker was **step 3, `lint-changed`** — Prettier on the one `.tsx` I hand-edited:

```
==> Prettier (check) on 2 changed file(s)
[warn] resources/js/pages/admin/finance/opening-balances/import.tsx
```

I had run Pint over the explicit PHP list and never ran Prettier over the frontend files. Two things
follow. First, I misread the first red as a test failure because the tail of the output showed the
suite's failures and I did not read the step list — the discipline the capture rule exists to
enforce, applied to the wrong artefact. Second, `lint-changed` is diff-aware and sees only committed
work, so at 20:37 it reported "no changed frontend files" and the same gate that passed then had
nothing to check; it only saw the file once it was committed. That is exactly the tech-debt ticket
raised on `feat/finance-bank-account-fks`, biting from the other direction — and evidence for it.

## Cold-review remediation (on top of `1311dfd`)

A cold review found one defect with three faces — the interpretation sentence is the only control
against an irreversible sign inversion, and the commit undermined it three ways. All three are fixed.

**4.1 — the summary spoke about a file nobody had read.** `serialize()` computed it unconditionally,
including on `store()`'s 201 for a `draft` batch with zero staged rows, and the screen gated the panel
on truthiness rather than status. The operator saw "0 student(s) are in CREDIT … Net: the two sides
cancel exactly … do not approve it" beside the Validating spinner. The server now sends `null` while
the batch is `draft`; the TS type is nullable so the check cannot be forgotten. Arm added, and it
asserts the validated case FIRST so a null cannot pass because the field was dropped entirely.

**4.2 — a correctness bug, not wording.** The summary classified per student NETTED and claimed those
students "will post nothing". `PostOpeningBalanceBatch` posts per ROW and GROSS: it skips only a row
whose OWN balance is zero (`:192`), and step 2 nets credits against CREDITS, never against charges.
So a legal multi-row student with +5,000 Tuition and −5,000 Bus was called square while two ledger
rows were about to be written for them. The class docblock's own justification was the source of the
error and said the classification "matches the posting"; it is corrected here and in the TS type.
Net-zero students are now split — `square_students` (every row zero, posts nothing) and
`offsetting_students` (rows cancel, both sides post) — and the sentence names the second only when it
occurs. **All twelve arms used one row per student**, which is the cutover's own shape and why
nothing reddened; the new arm uses two rows and ends by posting, so the ledger is the oracle rather
than my reading of it.

**4.3 — two NOTES became false and nothing in the suite read them.** One told the operator to type Σ
of `student_total_balance`, a column Brookstone's file does not have and which this commit made
optional; one said every amount cell must carry a figure. `OpeningBalanceOperatorScreenTest` asserts
the COUNT of notes and nothing else, so both shipped green. Both corrected, and NOTES is now read by
two arms.

The first version of that gate was wallpaper and it is worth recording why: it searched for demanding
language near an optional column name, and checked against the two notes that were actually wrong it
caught **neither** — "Every amount cell must carry a figure" names no column, and the control-total
note named the column without a demanding verb — while firing on a legitimate sentence. It was
replaced with two narrow checks that do bite: a note explaining the control total may not name an
optional column (bite-proved by restoring the original false note — red), and no note may name a
column the format does not have. The class the string check cannot reach is stated in the test rather
than papered over.

**Also fixed:** 3.1 (the template docblock argued one row per student was "the exact mistake L1
exists to refuse", 90 lines above the note this commit added instructing exactly that — restated);
2.1 (both fallback arms built their expectation FROM the constant, so `FALLBACK_FEE_TYPE_LABEL = ''`
passed everything — the literal is now asserted, and the arm claiming "spelled exactly once in the
codebase" was renamed to what it actually tests); 3.2 (the test file recorded Brookstone's figures as
kobo in one arm and naira in another — the ÷100 confusion this commit's own conclusion identified is
no longer preserved in its comments); 3.3 (the L2 line printed "Σ stated student totals" on runs
where every contributor was derived, and only the mismatch path named the derivation, so a clean run
— the run an operator sees — never showed it; the count is now on the DTO and printed every run);
1.2 and 1.1 above.

**Re-driven on `portal_drive` after 4.1 and 4.2**, both being screen behaviour neither provable from
the suite:

```
L2 (kobo): Σ student totals=0 over 2 student(s) (2 DERIVED from their own balances, 0 stated in the file), --control-total=0, Δ=0

  0 student(s) are in CREDIT … 0 student(s) are in ARREARS …
  1 student(s) have a zero balance and will post nothing.
  1 student(s) have lines that CANCEL to zero — they will still post both a charge and a credit.
  Net: the two sides cancel exactly.
```

```
status=validated  interpretation=present
status=draft      interpretation=NULL — the panel does not render
```

## What I did NOT do

- **No review of my own work.** No `finance-reviewer` was spawned — this session is under a standing
  instruction not to invoke agents unless asked. A cold review WAS run by the project lead and its
  findings are remediated above; this report has not been re-reviewed since.
- **The tickets are all filed now.** 4.4 at
  `docs/handoff/tickets/opening-balance-index-hydrates-every-row.md`; 3.4, 3.5 and 1.3 at
  `docs/handoff/tickets/opening-balance-three-stale-comments.md`, once their descriptions arrived —
  they were held rather than guessed at. All three verified before filing: the `'admission:'` key in
  the interpretation is unreachable because the query filters `status = Ok` and every path leaving
  `student_id` null also adds a finding; `OpeningBalanceRow`'s docblock still calls "the lines sum to
  themselves" a degradation when this commit made it the designed normal case; and the watched-reds
  heading said "four" over what is now a six-row table. **1.3 was fixed rather than deferred** — the
  count is gone from the heading, since a number that had already gone stale twice would go stale a
  third time.
- **No header aliasing** — see the measured observation above.
- **The drive fixture still seeds no academic slot** (`terms: 0` in `portal_drive`); the drive script
  created the term and the six students itself. Second commit running into this; worth folding into
  `DriveFinanceStates`.
- **The interpretation is not on the approvals queue row.** `OpeningBalanceBatchResource` is
  deliberately the uniform five-type shape and a richer row is the seam a special case grows back
  into. The summary is on the maker's screen before submission and in the console report. If the lead
  wants the ED to see it at the moment of approval, that is a deliberate change to the queue's shape
  and its own decision.
