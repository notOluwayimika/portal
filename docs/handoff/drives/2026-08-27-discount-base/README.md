# Drive — the discount base (axis C) on /finance/discount-policies

Branch `feat/discount-policy-base-control` at `f2c0c29`, driven 2026-08-27 on the
throwaway `APP_ENV=drive` instance (`portal_drive`, port 8001, `pnpm run build`
first). Never the dev database, never a production copy.

**Why this drive exists.** The acceptance suite on that commit proves the wire shape
and the two pure functions, and is structurally blind to whether the control is
BOUND and whether `send()` spreads what `changeTerms()` returns. The commit named
that seam as unguarded. **This drive closes it by reading the POST body off the
wire** rather than by trusting the screen — see § 3, observation 3.

Privacy: `user#<id>` / `school#<id>`, counts and ids. The names visible in the
screenshots (`Maker Drive`, `Checker Drive`, `Drive School A`) are seeded fixture
personas, not people.

## 1. The fixture, before a browser was opened

Both count tables, verbatim from `APP_ENV=drive php artisan finance:seed-drive-fixture`.
The data rows come out unpadded from a non-TTY stdout; the numbers align to the
header above them, left to right.

```text
Drive fixture seeded. Sign in at APP_URL with any user below (password: drive-password):
+--------------------------------------------+----------------------------+
| Role in the drive | Email |
+--------------------------------------------+----------------------------+
| Maker (accounts_officer) | maker@drive.test |
| Full checker (executive_director) | checker@drive.test |
| Void-only checker (no credit-note.approve) | void-checker@drive.test |
| Super admin | super@drive.test |
| School B bursar (isolation) | school-b@drive.test |
| Admin (guardians screen) | admin@drive.test |
| School B admin (guardian isolation) | admin-b@drive.test |
| Guardian editor, NO update_credentials | guardian-editor@drive.test |
+--------------------------------------------+----------------------------+

Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the bulk-run screen (U6) prices a COHORT from an ACTIVE schedule and reports the unplaceable; the decisions surface (U13/U14) reads back what a checker has already settled:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
| School | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Active schedules | Cohort at slot | Unplaceable | Decided credit notes | Decided voids |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
| A (school#1) | 2 | 2 | 2 | 2 | 1 | 5 | 0 | 2 | 8 | 1 | 2 | 9 | 2 | 1 |
| B (school#2) | 2 | 2 | 2 | 1 | 1 | 0 | 0 | 0 | 1 | 1 | 2 | 1 | 0 | 0 |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+------------------+----------------+-------------+----------------------+---------------+
Bulk invoice runs: /finance/bulk-invoice-runs — the cohort above sits at (term, JSS 1); JSS 2 has an empty one on purpose.
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy; the receipt screen (U11) renders ONE payment and refuses for a migrated one; the guardians screen links a new guardian to students by admission number:
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
| School | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Payments w/ remainder | Open invoices | Students | Guardians |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
| A (school#1) | 2 | 2 | 2 | 2 | 1 | 5 | 0 | 2 | 8 | 12 | 0 |
| B (school#2) | 2 | 2 | 2 | 1 | 1 | 0 | 0 | 0 | 1 | 3 | 0 |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+-----------------------+---------------+----------+-----------+
 School A (school#1) admission numbers: ADM04703, ADM97506, ADM17064, ADM30987, ADM72163, ADM44991, ADM51307, ADM38375, ADM71456, ADM46794, ADM58272, ADM03856
 School B (school#2) admission numbers: ADM24653, ADM65370, ADM96489
Statements: open /finance and click a student; the queue is /finance/approvals.
```

`Discount policies` is **1** for both schools, so the catalog can be listed, amended
and retired from either seat. Nothing in this drive depends on a zero column:
the two schools' academic slots, bank accounts and policies are all non-zero, and
the columns this screen does not use (`Cohort at slot`, `Unplaceable`, `Payments
w/ remainder`, `Open invoices`, `Decided credit notes`, `Decided voids`) were not
relied on. `Payments (migrated)` is 0 by construction, as it is on every fixture,
and this screen has no migrated-payment case.

The first ten columns of table 2 repeat table 1's, value for value, as they should.

## 2. Harness

`playwright-core` 1.62.1 driving the cached `chromium-1234` build, both installed in
`~/.drive-harness` — **outside the repository**, so `node_modules` here was never
mutated. The drive scripts are throwaway and not committed.

**One environment fact cost a cycle and belongs in the record.** `session.domain` is
`localhost` on the drive instance, so a harness pointed at `http://127.0.0.1:8001`
logs in successfully (`POST /login` → 302) and then holds no session: the cookie is
rejected for the wrong host, `/api/v1/finance/*` answers 401 and every page bounces
to `/login`. It looks exactly like a broken login. **Drive `http://localhost:8001`,
which is also `app.url`.** This is a harness mistake, not a defect, and it is a
sibling of the `:8001` Sanctum entry already recorded in the skill.

`GET /dashboard` returns **403** for `maker@drive.test`, `checker@drive.test` and
`school-b@drive.test` and bounces to `/login` — the known, pre-existing friction the
skill documents. It is the only console error in the whole drive (§ 5).

## 3. What was observed, in order

Every control was read out of the DOM by attribute, and every submit was read off
the **wire** — the POST body Playwright captured on the request — because the base
control exposes no machine-readable value (finding **F1**).

### Observation 1 — the base control renders on a percent basis

```text
  basis: {"dataValue":"percent","text":"A percentage of the bill"}
  BASE CONTROL: {
 "count": 2,
 "radios": [
  {"domValue":"on","hasValueAttr":false,"dataValue":null,"checked":true,
   "labelText":"of discountable charges Only the charges a discount is allowed to reduce. A bill can carry lines this leaves alone entirely."},
  {"domValue":"on","hasValueAttr":false,"dataValue":null,"checked":false,
   "labelText":"of the whole bill Every charge on the bill, including the ones the other option would leave alone. The same percentage, and more money."}
 ],
 "legend": "The percentage is taken of what?",
 "percentHelp": "A whole number from 1 to 100. What it is taken of is the next question."
}
```

*Establishes:* the maker can state the base at all — two options, seeded on
`discountable`, which is the value a create is stamped with server-side. The two
option titles are `baseLabel`'s two phrases character for character, so the maker
chooses the string the ED will approve. **And the help text no longer asserts a
base** — the sentence that was unconditionally "applied to the discountable charges
on the bill" now says what a percentage is and defers what it applies to.
`maker-01-create-percent-base-control-visible.png`

### Observation 2 — it is absent on an amount basis

```text
  BASIS options (2): ["percent|A percentage of the bill","amount|A fixed amount off"]
  basis: {"dataValue":"amount","text":"A fixed amount off"}
  BASE CONTROL: {"count":0,"radios":[],"legend":null,"percentHelp":null}
```

*Establishes:* `count: 0` — the fieldset is not rendered, not merely disabled, which
is the `prohibited_if:basis,amount` side. Switching back to `percent` restores it
with `discountable` checked. No layout gap where it was.
`maker-02-amount-basis-base-control-absent.png`

### Observation 3 — a `total` policy is authored, and the wire carries the base

Control as submitted, then the request body:

```text
  CONTROL AS SUBMITTED: radios[0].checked=false ("of discountable charges"),
                        radios[1].checked=true  ("of the whole bill")
  POST /api/v1/finance/discount-policy-changes
    {"kind":"create","reason":"drive: axis C, whole bill","name":"Drive whole-bill 50",
     "description":null,"basis":"percent","requires_approval":false,"percent":50,"base":"total"}
  result: {"modalStillOpen":false,"inlineErrors":[]}
```

*Establishes:* **the seam the commit named as unguarded is closed.** The radio the
maker clicked reached `form.base`, `changeTerms()` put it in the percent branch, and
`send()` spread it into the request — `"percent":50,"base":"total"` on the wire, from
a click. Accepted, not a 422: the modal closed and no inline error rendered.
`maker-03-total-selected-before-submit.png`, `maker-04-after-submit-toast.png`

### Observation 4 — the checker sees the base, and approves

The pending queue as the ED's screen received it, and the row it rendered:

```text
  PENDING: [{"kind":"amend","name":"Drive whole-bill 50","basis":"percent",
             "percent":55,"base":"total","effective_base":"total"}]
  QUEUE row: ["DISCOUNT POLICY","amend · Drive whole-bill 50 · 55% of the whole bill","—",
              "Maker Drive","drive: raise the rate, base unmentioned","27/08/2026","—","Approve Reject"]
```

and for a create on the other base:

```text
  PENDING: [{"kind":"create","name":"Drive discountable 15","basis":"percent",
             "percent":15,"base":"discountable","effective_base":"discountable"}]
  QUEUE row: ["DISCOUNT POLICY","create · Drive discountable 15 · 15% of discountable charges", …]
```

*Establishes:* the Subject column states the base, both values, and the string is the
same one the maker chose. Two bases rendered side by side in one queue is what makes
this readable as a distinction rather than as boilerplate — a checker seeing only one
of them could not tell the axis was there.
`checker-03-queue-amend-55-whole-bill.png`, `checker-05-queue-create-discountable.png`

### Observation 5 — the catalog reads the same phrase back

```text
  CATALOG (api): [{"basis":"percent","percent":50,"base":"total","status":"active", …}]
  CATALOG (rendered): ["Drive whole-bill 50","Percentage","50% of the whole bill",
                       "A bursar applies it directly on the invoice","Active",""]
```

*Establishes:* maker → checker → catalog is one string end to end. The `50% of the
whole bill` here and the `50% of the whole bill` the ED approved are the same
`baseLabel` call.
`maker-05-catalog-whole-bill.png`

### Observation 6 — the amend seeds the policy's own base, untouched

The amend modal opened on the `total` policy and was submitted with the base control
never clicked:

```text
  seeded BASE CONTROL: checkedLabel = "of the whole bill"
  POST {"kind":"amend","target":"a29ac94b-…","percent":55,"base":"total", …}
  queue: "amend · Drive whole-bill 50 · 55% of the whole bill"   (base "total", effective_base "total")
  catalog after approval: 55% of the whole bill
```

*Establishes:* raising a rate does not silently move the base. **But read the middle
line: the change row's `base` is `"total"`, not NULL.** The brief expected NULL here
and the inherited value on the queue. See finding **F2** — the form now always states
the base, so the inheritance path is no longer reachable from this screen.
`maker-06-amend-seeded-whole-bill.png`, `maker-07-catalog-55-whole-bill.png`

### Observation 7 — the inheritance path, rendered (submitted as an API client)

Because the form can no longer produce it, the unstated amend was submitted from the
maker's own authenticated session as an API client would — no `base` key:

```text
  POST {"kind":"amend","target":"a29ac9d9-…","name":"Drive whole-bill 50",
        "basis":"percent","percent":60,"requires_approval":false,
        "reason":"drive: base UNSTATED, inheritance path"}          ← no `base`
  PENDING: [{"kind":"amend","percent":60,"base":null,"effective_base":"total"}]
  QUEUE row: "amend · Drive whole-bill 50 · 60% of the whole bill"
  catalog after approval: 60% of the whole bill   (base "total")
```

*Establishes:* **`base: null` beside `effective_base: "total"`, and the screen renders
the second.** This is the observation the brief called the most important one, and it
is the arm the whole inheritance mechanism exists for, seen by a human: a checker
approving "60%" is shown which 60% it is, from a change row that states nothing.
Approved, and the catalog was stamped `total` — inheritance carried it.
`checker-04-queue-inherited-base-raw-null.png`, `maker-08-catalog-60-still-whole-bill.png`

### Observation 8 — the amend to an amount basis is accepted

```text
  seeded BASE CONTROL (still percent): checkedLabel = "of the whole bill"
  after switching to amount:  {"count":0,"legend":null,"radios":[],"checkedLabel":null}
  POST {"kind":"amend","target":"a29ac9ed-…","basis":"amount",
        "value_minor":3000000,"value_currency":"NGN"}              ← no `base` key
  result: {"modalStillOpen":false,"inlineErrors":[]}
  queue: "amend · Drive whole-bill 50 · ₦30,000.00"   (base null, effective_base "discountable")
  catalog: {"basis":"amount","value_minor":3000000,"base":"discountable","status":"active"}
```

*Establishes:* the payload drops the key when the basis drops the control, so the
submit is accepted rather than the 422 that posting `base` on an amount basis
produces. `₦30,000.00` renders with no base phrase beside it — an amount policy has
no percentage to take of anything. `30000` typed, masked to `30,000.00`, sent as
`3000000` minor units: `30000 × 100 = 3000000`, and nothing on the page computed it.
`maker-09-amend-to-amount-no-base-control.png`

### Observation 9 — the round trip back to percent seeds DISCOUNTABLE, not total

```text
  BASE CONTROL while still on amount: {"count":0, …}
  BASE CONTROL after switching back to percent: {
   "radios":[{"checked":true,"label":"of discountable charges"},
             {"checked":false,"label":"of the whole bill"}],
   "checkedLabel":"of discountable charges"}
  POST {"kind":"amend","basis":"percent","percent":25,"base":"discountable"}
  catalog: 25% of discountable charges
```

*Establishes:* **`amendBase()`'s cross-basis rule, rendered.** The policy being
amended stores `base: discountable` after the amount hop, and the chain began at
`total` three versions back; the control offers `discountable`, which is exactly what
`effectiveBase()` would stamp for a cross-basis amend. The screen does not offer a
base the server would not have used.
`maker-10-back-to-percent-seeded-discountable.png`, `maker-11-catalog-round-trip-discountable.png`

## 4. Isolation — by id, both seats side by side

```text
Seat 1 — maker@drive.test (accounts_officer, school#1)
  CATALOG a29ac94b-0018-46db-9602-25017d302ad7|percent|50|total|superseded|Drive whole-bill 50
  CATALOG a29ac9d9-8281-47eb-8507-5dbcfc4d2b0f|percent|55|total|superseded|Drive whole-bill 50
  CATALOG a29ac9ed-86ae-4276-851f-78ba7f7bdf72|percent|60|total|superseded|Drive whole-bill 50
  CATALOG a29aca57-10b0-4c3a-ba65-029fef924a3b|amount|3000000|discountable|superseded|Drive whole-bill 50
  CATALOG a29aca6b-c290-4b20-947c-4809a11e3cf2|percent|25|discountable|active|Drive whole-bill 50
  CATALOG a29ac769-a280-4318-9d59-4a1067f0a3b6|percent|10|discountable|active|Sibling discount

Seat 2 — school-b@drive.test (isolation, school#2)
  CATALOG a29ac769-a514-47f3-b225-28c4903055bb|percent|10|discountable|active|Sibling discount

  ids A: 6, ids B: 1
  intersection: []
  School A authored names present in B?: []
```

*Establishes:* the two `Sibling discount` rows carry **identical labels and different
ids** (`a29ac769-a280-…` against `a29ac769-a514-…`) — the reason isolation is read by
id here and not by name. Nothing School A authored in this drive appears in School B's
catalog, and the intersection of the two id sets is empty.

School B's own maker opens the same control, so the feature is not School-A-only:

```text
   basis: {"dataValue":"percent","text":"A percentage of the bill"}
   BASE CONTROL: {"count":2,"legend":"The percentage is taken of what?",
    "radios":[{"checked":true,"label":"of discountable charges"},
              {"checked":false,"label":"of the whole bill"}]}
```

`isolation-01-school-a-list.png`, `isolation-02-school-b-list.png`,
`isolation-03-school-b-base-control.png`

## 5. Console

Watched on every seat for the whole drive. One error, repeated per seat, and it is
the known pre-existing `/dashboard` bounce:

```text
=== >=400 RESPONSES ===
[maker]     403 GET /dashboard
[checker]   403 GET /dashboard
[school-b]  403 GET /dashboard
=== CONSOLE errors ===
Failed to load resource: the server responded with a status of 403 (Forbidden)
=== PAGE ERRORS ===
(none)
```

No page errors, no uncaught exceptions, no blanked screen, and nothing from any
finance route. Every `/api/v1/finance/*` call in the drive answered 2xx.

## 6. Findings — observed, unfixed

### F1 · The base control puts no value in the DOM · ticket

```text
  dpBase:              [{"value":"on","hasValueAttr":false},{"value":"on","hasValueAttr":false}]
  dpRequiresApproval:  [{"value":"on","hasValueAttr":false},{"value":"on","hasValueAttr":false}]
  basisTriggerDataValue: "percent"
```

Both `dp-base` radios report `value="on"` and carry no `value` attribute, so the only
handle the DOM offers for *which base is selected* is the option's prose. This drive
had to read the label text and then confirm the real value off the wire.

It matters more here than on its sibling. `dp-requires-approval` has the same shape,
but its two options read as opposites at a glance; the base's two read as similar
prose — which is precisely why the brief said to read values and never labels, and
this control makes that impossible. The screen's own `<Select>` already does the right
thing: `base-dropdown.tsx:214` puts `data-value` on every option, and its comment at
`:202-207` gives the reason in this drive's own terms — *"A native `<select>` exposes
it as `option.value`; this control renders buttons, so without it the only thing
readable from the page is the label — and a drive checking School isolation reads
values precisely because the two Schools' labels are identical strings by
construction"*. The radio group is the same problem the same file already solved,
one control along.

Not a user-facing defect: the value reaches the server correctly (observation 3).
It is a testability and assertion gap, and it will make any future DOM-level arm on
this control assert prose.

### F2 · The form can no longer produce an unstated base, so the inheritance path is unreachable from this screen · ticket

Observation 6's wire body is `…"percent":55,"base":"total"…`. The control is seeded
from `amendBase(policy)` and posted on every percent submit, so **an amend authored on
this screen always states the base**. The change row's `base` is therefore never NULL
from the UI, and `effectiveBase()`'s inheritance step — the one 48e3ad2 built, and the
one the `effective_base` Resource key exists to make visible — is reachable only from
an API client or from pre-axis rows.

**No behavioural difference, and that is why this is a ticket and not a fix.** The
value posted is by construction what inheritance would have produced: `policy.base` on
a percent→percent amend, `discountable` on a cross-basis one, which is exactly
`effectiveBase()`'s two branches. Observations 6 and 7 landed on the same catalog
value by both routes.

What changes is what is *exercised in production*: the queue's null-base rendering path
now has no UI producer. It was still driven here (observation 7) by submitting the
API-shaped body deliberately — and that is the honest framing of that observation, not
"the queue showed the inherited value after an ordinary amend", which is what the brief
predicted and is no longer what happens.

### F3 · `session.domain=localhost` makes a `127.0.0.1` harness look like a broken login · ticket (drive environment)

Recorded in § 2. Not a defect in the change; friction that will cost the next driver a
cycle if it is not written down, in the same class as the `:8001` Sanctum entry.

## 7. What was NOT driven

- **`super@drive.test`** — not needed, and the brief said so. It holds `super_admin`
  with no finance grant, and its question is the bypass exclusion (a super admin does
  not approve). This change adds no ability and no gate: it adds a control inside a
  screen the maker already reaches and a phrase on a queue the ED already reads. No
  authorization surface moved, so there is nothing here for that seat to prove.
- **`void-checker@drive.test`** — same reason. The partial-checker question is about
  `/finance/approvals` tolerating a feed the viewer cannot check; this change alters
  the discount feed's *subject string*, not its gating.
- **The reject path.** Every proposal in this drive was approved. A rejected
  discount-policy change renders the same subject string through the same
  `rowSubject`, so nothing about the base axis is untested by that omission, but it
  was not seen.
- **The retire path.** `openRetire` proposes no terms and so has no base control;
  it was not exercised.
- **A 422 from the base rule, rendered.** Posting `base` on an amount basis is a 422,
  and this screen is built so the maker cannot do it — the control is absent and the
  key is dropped. The refusal is proven by test
  (`BssPerStudentDiscountTest`, `rule 58`) and was **not** rendered here, because
  reaching it would mean posting a body the form cannot construct. Stated rather
  than claimed.
- **Anything that applies a policy to an invoice.** The base's arithmetic — 50% of
  discountable against 50% of the whole bill being different money — is the awards
  path, not this screen. Covered by test only.
