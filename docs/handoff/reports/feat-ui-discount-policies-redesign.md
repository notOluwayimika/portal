# `feat/ui-discount-policies-redesign` — the discount-policies screen, laid out to the design system

**Branched from:** `origin/staging` @ **`820d0010c438e0fa5bf2925ed9e93a1418e3983e`**
(fetched 2026-08-16; `git rev-parse origin/staging` re-derived at checkout, and
`bin/quality` printed `quality gate — base 820d001` against the same commit).
§ 26 of `docs/ui-ux-design-system.md` is **on staging** at that sha — commit
`8618033`, merged — so it was read from there and not from
`docs/design-system-what-has-gone-wrong`.

**Commit:** `d7dff21`. **Not pushed.**

---

## 0. A correction to a landed claim, recorded not edited

`docs/handoff/reports/feat-ui-bank-accounts-fee-schedules-redesign.md` calls bank accounts and fee
schedules _"the last two Finance pages still on the pre-guide shape"_. They were not: this screen was
a third, and it carried the older shape than either of them — a raw `<table className="w-full
text-sm">`, a raw `<select>`, and a failed load that was a toast and nothing else. That report is
**not edited**; a landed report records what was claimed when, and the correction belongs here.

Whether there is now a fourth is not something this change establishes. What it establishes is that
"the last two" was a claim about a set nobody had enumerated, which is the same class of error as a
carried number.

---

## 1. What the screen was, and what it is

|              | Before                                                                  | After                                                                           |
| ------------ | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Page shell   | `<div className="space-y-4 p-4">`                                       | `min-h-screen bg-[#f5f7fb] … max-w-7xl space-y-5` (§ 3)                         |
| Header       | an `h1` and a five-line paragraph                                       | hero card, icon tile, one-line subtitle, action bar (§ 4/§ 5)                   |
| KPIs         | none                                                                    | three stat cards via `FinanceStatCard` (§ 6)                                    |
| Table        | raw `<table className="w-full text-sm">` at `:414`                      | styled card table, `text-xs`, `text-[10px]` uppercase headers (§ 8)             |
| Dropdown     | raw `<select>` at `:584`                                                | shared `Select` (`@/components/ui/base-dropdown`), twice (§ 7/§ 23)             |
| Status       | local `STATUS_CLASS` map, `bg-muted`, no `dark:` pair                   | shared `StatusPill` (§ 14)                                                      |
| Failed load  | `toast.error(...)`, then the screen rendered "No discount policies yet" | distinct error row + Retry, cards dashed, counter suppressed (§ 13/§ 26)        |
| Modal footer | buttons inline in the body                                              | `Modal`'s `footer` prop (§ 10)                                                  |
| Filters      | none                                                                    | client-side search + status filter, with the pagination dependency written down |

Structure copied from `resources/js/pages/admin/finance/index.tsx`, the guide's own canonical
reference for a list page with KPI stat cards (`docs/ui-ux-design-system.md:20`).

---

## 2. The preserved rules, and where each now lives

The file's header docblock states three rules the file was written to hold. Each is carried into the
new structure, and each is still stated in the docblock, which was kept and extended rather than
replaced.

### 2.1 Four acts and no fifth — no approve, no reject

**Where it lives now:** the hero action bar holds exactly two page-scoped controls — `Refresh`
(outline) and `Propose a policy` (the one primary, placed last, per § 5) — and an **active** row's
Actions cell holds exactly two, `Amend` and `Retire`, as ghost `h-7 w-7` icon buttons with
`title`/`aria-label` (§ 8). That is four. There is no approve control, no reject control, and no
route to `/finance/approvals` from this page.

**Driven:** the maker seat's page reported `hero buttons: ["Refresh","Propose a policy"]` and the
active row's Actions cell reported `buttons: ["Amend Sibling discount","Retire Sibling discount"]`
(aria-labels) — nothing else. The ED's decision was taken on `/finance/approvals`, a second seat and a
second screen, as the rule requires.

**CORRECTED 2026-08-16 (cold review). This rule is held by the component's markup and nothing
else.** The first version of this report claimed it was "still enforced by more than a comment" by
`DiscountPoliciesScreenTest`'s _"has ONE MAKER ability"_ arm. That is wrong, and wrong in the
direction that matters. That arm filters the permission catalog with
`str_contains(strtolower($ability), 'discount') && ! ApprovalAbility::isExcludedFromSuperAdminBypass($ability)`,
and `isExcludedFromSuperAdminBypass` is true for any ability whose terminal segment is `approve` or
`reject` (`app/Support/ApprovalAbility.php:40-47`). **The fifth act this rule forbids is precisely an
approve or a reject** — so an Approve button added to this page would post
`finance.discount-policy.change.approve`, the filter would exclude it, `$makerAbilities` would still
be `['finance.discount-policy.change.submit']`, and the arm would stay green through exactly the
change it was cited as catching.

What that arm actually pins is narrower and still worth having: that no **second maker** ability
appears, which is the U1 defect (page gate and button gate asking different questions). It says
nothing about a checker control appearing here. Nothing does. The four-acts rule is a convention
with no mechanism — a wish, by the project's own definition — and it is held by the fact that this
file renders four things.

### 2.2 Retired and superseded rows stay on the list, below the active ones, without controls

Expressed as an **ordering inside one table** rather than as a second table:

- `resources/js/pages/admin/finance/discount-policies.tsx:458-461` (re-derived 2026-08-16) — `rows` is
  `[...visible.filter(active), ...visible.filter(!active)]`. Active first, always, whatever the
  filter or the search box is doing.
- A **spanning divider row** is emitted before the first closed row (derived from the ordering, not
  from a second render pass) carrying `NO LONGER IN USE` and the provenance sentence. One table means
  one set of column widths, so a superseded policy's terms line up directly under the active policy
  that replaced it — which is the comparison a reader of this list is making.
- A closed row's Actions cell renders `Kept for the invoices it priced` **instead of** the two
  buttons.

**CORRECTED 2026-08-16 (cold review): the first version of this report, the divider row's copy and
the file's docblock all said the server refuses such a request. It does not.** The screen told
operators _"none of them can be amended or retired again"_ and the docblock called the withheld
control _"a request the Action refuses"_. Verified against the code: `SubmitDiscountPolicyChange`
checks school, reason, target shape and one-open-request-per-target and **never the target's
status**; `ApproveDiscountPolicyChange` checks the change's status and maker ≠ checker and then
updates the target unconditionally; and `finance_discount_policies_update_guard` guards every column
**except `status`**, deliberately — its own message is _"only status may change"_. So retiring a
retired policy is a silent no-op success, and amending a superseded one succeeds unless the new name
collides with an active row.

The **behaviour** is right and unchanged — closed rows should not offer these controls, because the
act is meaningless. What was wrong was the reason given for it, in three places at once, and a wrong
reason in a docblock is how the next person concludes a guard exists. Copy, comment and docblock all
now say the true thing: this screen withholds the controls, and the server does not refuse them.
Filed as [`discount-policy-changes-do-not-check-target-status.md`](../tickets/discount-policy-changes-do-not-check-target-status.md).

**Driven, all three states, through the real approval flow** (§ 6 below): the list rendered
`Active → Superseded → Retired` in that order with the divider between the first and the second, and
both closed rows reported `"Kept for the invoices it priced"` in their Actions cell while the active
row reported two aria-labelled buttons.

### 2.3 The frontend computes no money

`formatNaira`, `minorToNairaInput` and `nairaToMinor` (all from `@/lib/format`) remain the **only**
conversions in the file. `valueLabel()` is unchanged apart from formatting; the amount branch pairs
`value_minor`/`value_currency` back into the wire shape and hands it to `formatNaira`, and the percent
branch touches neither helper because a percentage is not money.

Two additions of mine could have broken this and do not:

- **The KPI cards count rows.** `activeCount` / `supersededCount` / `retiredCount`
  (`:433-437`, re-derived 2026-08-16) are `policies.filter(...).length`. There is no total, no sum, no `reduce`. Beyond
  § 24's ban, a value total here would be **meaningless as well as forbidden**: an amount policy
  carries money in `value_minor` and a percent policy carries an integer in `percent`, so there is no
  quantity the two can be added into. This is stated in the file docblock so the next person adding a
  fourth card reads the reason and not just the rule.
- **The search box** joins `name` and `description` only — no numeric field is stringified or
  compared.

`bin/ci-money-lint.php` scopes its strict zone to `resources/js/pages/admin/finance/` and
`resources/js/components/finance/` (`bin/ci-money-lint.php:42-43`), so this file is inside it. Step 9
of `bin/quality`: `money-lint: OK — no money-rule violations (0 known exception(s))`.

---

## 3. The failed-path read-back — every number and every sentence

§ 26's rule: _"When you fix a state-confusion defect, enumerate every other number and sentence on
the screen and read what each one now says."_ The error state was forced by intercepting
`GET /api/v1/finance/discount-policies` and answering `500`, then reading the rendered DOM back.
Screenshot: `maker-07-error-light.png` / `maker-08-error-dark.png`.

| #   | Region                                                                                                                  | What it says on the failed path                                                                                                                                                                      | Why that                                                                                                                                                                      |
| --- | ----------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `h1`                                                                                                                    | `"Discount policies"`                                                                                                                                                                                | Static. Identifies the page; carries no data.                                                                                                                                 |
| 2   | Hero subtitle                                                                                                           | `"Every reduction on an invoice has to name one of these, and only the executive director may change the list."`                                                                                     | Static. A statement about the system, true regardless of the fetch.                                                                                                           |
| 3   | Hero button 1                                                                                                           | `"Refresh"`                                                                                                                                                                                          | Static.                                                                                                                                                                       |
| 4   | Hero button 2                                                                                                           | `"Propose a policy"`                                                                                                                                                                                 | **Deliberately still live.** Authoring a NEW policy reads nothing from the catalog, so disabling it would remove a working act because an unrelated read failed.              |
| 5   | Stat card "Active"                                                                                                      | value **`—`**, label `Active`, sub-text `Citable on an invoice raised today`                                                                                                                         | The count is unknown, so no number is rendered. The label stays because it is what tells the reader _which_ figure is missing.                                                |
| 6   | Stat card "Superseded"                                                                                                  | value **`—`**                                                                                                                                                                                        | Same.                                                                                                                                                                         |
| 7   | Stat card "Retired"                                                                                                     | value **`—`**                                                                                                                                                                                        | Same.                                                                                                                                                                         |
| 8   | Stat-card skeletons                                                                                                     | **absent** on all three (`skeleton=false` read off the DOM)                                                                                                                                          | A skeleton beside a Retry button says "still arriving" and would pulse forever — § 26 names that as the defect wearing the costume of a fix.                                  |
| 9   | Search input                                                                                                            | placeholder `"Search by name or description…"`                                                                                                                                                       | Static, and it filters an empty array — harmless, and removing it would move the page's furniture on an error.                                                                |
| 10  | Status filter `Select`                                                                                                  | trigger `value="" label="All policies"`; its four options are constants                                                                                                                              | Renders no data.                                                                                                                                                              |
| 11  | "Showing X of Y" counter                                                                                                | **not rendered at all** (`counterPresent: false`)                                                                                                                                                    | Both numbers come from an array that is empty because the fetch died. There is no honest number, and "Showing — of —" is a sentence with no content — worse than no sentence. |
| 12  | Clear button                                                                                                            | not rendered (no filters were active)                                                                                                                                                                | Would render if filters were set; it is an action, not a claim.                                                                                                               |
| 13  | Table header row                                                                                                        | six static uppercase labels                                                                                                                                                                          | Static.                                                                                                                                                                       |
| 14  | Table body                                                                                                              | one spanning row: red `AlertCircle`, `"Could not load discount policies"`, `"Something went wrong fetching the catalog. This school may still have policies — none of them could be read."`, `Retry` | The error state (§ 13). The second sentence is deliberate: it denies the inference the old toast-then-empty screen invited.                                                   |
| 15  | `NO LONGER IN USE` divider                                                                                              | **not rendered** — no closed rows exist to introduce                                                                                                                                                 | Derived from the row list, so it cannot outlive it.                                                                                                                           |
| 16  | Empty-state copy (`"No discount policies to show"` / `"Until one is approved no invoice can carry a discount at all…"`) | **not rendered** — the `error` branch precedes the `rows.length === 0` branch                                                                                                                        | This is the original defect. On the old screen this sentence was exactly what a failed load produced.                                                                         |
| 17  | Row count                                                                                                               | 0 rows                                                                                                                                                                                               | —                                                                                                                                                                             |

**The dash is conditional, proven in the same run** (§ 26 asks for this explicitly, because a card
that dashes unconditionally passes every test you would write for the failure case). In the healthy
read immediately before the interception, on the same page object:

```
card[0] : skeleton=false texts=["Active","1","Citable on an invoice raised today"]
card[1] : skeleton=false texts=["Superseded","0","Replaced by an amendment, still explaining old invoices"]
card[2] : skeleton=false texts=["Retired","0","Withdrawn from choice, never deleted"]
counter : "Showing 1 of 1"
```

Two **genuine zeros rendering `0`**, beside a genuine `1`, in the same run in which all three later
rendered `—`.

**Empty is distinguishable from error, driven both ways.** With a search term matching nothing, the
counter renders `"Showing 0 of 3"` and the body renders `"No discount policies to show"` /
`"No policies match this view. Try clearing the filters."` / `Clear filters`. Same screen, same
zero, a different sentence and a different icon from the error path — and the counter is _present_
there because 0-of-3 is a number the page actually has.

**One Retry restores everything.** This screen has **one** fetch (unlike the two-fetch screen § 26
records), so there is no second array for a Retry to leave empty. Driven: after removing the
interception and clicking `Retry`, all three cards returned to `1 / 0 / 0`, the counter returned as
`"Showing 1 of 1"`, and the row returned.

---

## 4. The base-dropdown consumer count — verified, and it is SEVEN

Re-derived, not carried:

```
$ grep -rln 'base-dropdown' resources/js | grep -v 'components/ui/base-dropdown' | sort
resources/js/pages/admin/finance/bank-accounts.tsx
resources/js/pages/admin/finance/discount-policies.tsx      ← new
resources/js/pages/admin/finance/fee-schedules.tsx
resources/js/pages/admin/finance/index.tsx
resources/js/pages/admin/students/index.tsx
resources/js/pages/admin/teacher-assignments/index.tsx
resources/js/pages/admin/teachers/index.tsx
```

Six before this change, **seven** after. § 26 says _"One dropdown has six"_ — that line is now stale
by one and this is where the successor number is recorded.

**I changed nothing in `resources/js/components/ui/`.** `git diff --name-only origin/staging...HEAD`
lists three non-screenshot files and none of them is under that directory, so the blast-radius
exercise § 26 requires before _changing_ a shared component does not apply. What does apply is the
other half of that paragraph — **exercise the structural case your own screens do not cover** — and
this screen supplies one that was already known to be hard: the modal `Select` sits **inside the
modal's scrollable body**, which is the case `base-dropdown.tsx:132-147` was taught about (the
`capture: true` scroll listener that repositions a `fixed` portalled panel). Both consumers were
opened and read **by value**, not by label:

```
STATUS FILTER options (4): ["|All policies","active|Active","superseded|Superseded","retired|Retired"]
MODAL BASIS   options (2): ["percent|A percentage of the bill","amount|A fixed amount off"]
```

and choosing an option was verified to move real state, not just the label:

```
after choosing amount: {"trigger":"amount","triggerLabel":"A fixed amount off",
                        "amountFieldPresent":true,"percentFieldPresent":false,
                        "amountLabel":"Amount off (₦)"}
```

The basis `Select` is `id="dp-basis"`, which base-dropdown puts on the **trigger button**, so the
existing `<Label htmlFor="dp-basis">` still names the control. No ARIA beyond the component's own
`aria-expanded` was added — § 26's listbox-without-a-keyboard entry stands and is not reopened here.

---

## 5. The payload contract — the existing arm, what it covered, and what I extended

`tests/Feature/Finance/DiscountPoliciesScreenTest.php`'s U8 arm
(_"carries `status` and `requires_approval`, in the shapes the invoice modal reads"_) pinned **four**
keys: `id`, `name`, `status`, `requires_approval`. **It does not cover every key this screen reads.**

This screen reads **nine**. The five it did not cover are `basis`, `value_minor`, `value_currency`,
`percent` and `description` — and every one of them fails the exact silent way § 26 describes,
because the screen's own no-value glyph absorbs the loss:

- **`basis` renamed** → `policy.basis === 'percent'` is false for every row, `valueLabel()` takes the
  amount branch, `value_minor` is `undefined`, and every Value cell renders `—` while the Basis
  column reads "Fixed amount" for a percentage. No error, no console entry.
- **`value_minor` / `value_currency` / `percent` renamed** → the same dash from the other side, and —
  the expensive half — `openAmend()` prefills the form from those keys, so an amendment authored from
  a blanked prefill re-states the policy's terms as empty, and the ED approves _that_.

Per the brief I **extended that arm rather than writing a second one**: same response, same key set,
one place to update when the Resource changes. The extension pins presence, type (`value_minor` and
`percent` integer-or-null, because they are handed to integer minor-unit helpers), the two-value
`basis` union, and the **basis-exclusive rule on the payload** (the row carrying money carries no
percent and vice versa — that is what lets `valueLabel()` branch on `basis` alone). Two
non-vacuousness guards: both bases must actually occur in the body, and `description` must carry its
value rather than merely existing.

**Watched red on all four, restored between each:**

| Removed from `DiscountPolicyResource::toArray()` | Result                                                                               |
| ------------------------------------------------ | ------------------------------------------------------------------------------------ |
| `'basis'`                                        | `tests 9, passed 8, failed 1` — `Failed asserting that an array has the key 'basis'` |
| `'value_minor'`                                  | `tests 9, passed 8, failed 1` — `… has the key 'value_minor'`                        |
| `'percent'`                                      | `tests 9, passed 8, failed 1` — `… has the key 'percent'`                            |
| `'description'`                                  | `tests 9, passed 8, failed 1` — `… has the key 'description'`                        |

Each reds this arm and **nothing else in the file** (8 of 9 still pass in every case). Restored:
`git diff --stat app/Finance/Http/Resources/DiscountPolicyResource.php` is empty. Green:
`{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":88}`.

**What this still does not pin** — unchanged from what the U8 arm already said about itself: there is
no JavaScript test runner in this repository, so the client's half of every one of these contracts is
unguarded. Inverting a predicate in the `.tsx` reds nothing.
(`docs/handoff/tickets/no-javascript-test-runner.md`.)

---

## 6. The drive

Per `.claude/skills/finance-drive/SKILL.md`. Throwaway instance, `APP_ENV=drive`, database
`portal_drive`, port 8001, `pnpm run build` **before** seeding. Browser: system Chrome via
`puppeteer-core`, installed **outside the repository** (in the session scratchpad), so `node_modules`
was not mutated. Screenshots: `docs/handoff/drives/2026-08-16-discount-policies/`.

**CORRECTED 2026-08-16 (cold review): the split was stated as "23 maker/checker + 2 isolation + 3
checker-queue", which is both mislabelled and in the wrong order.** Re-derived —
`ls … | sed 's/-[0-9].*//' | sort | uniq -c` — the original 28 were **23 `maker-*` + 3 `checker-*` +
2 `isolation-*`**. The cold-review round adds **7 `fix-*`** (the counter bite-proof on two screens
and the dark-modal measurement), for **35** in total. The arithmetic happened to come out right the
first time, which is exactly why nobody noticed the labels were swapped.

### 6.1 The fixture count table, verbatim

Checked **before** opening a browser, as the skill requires. No zero in the column this screen
depends on.

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy:
+--------------+-------------------+-------+--------------+---------------+-------------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies |
+--------------+-------------------+-------+--------------+---------------+-------------------+
| A (school#1) | 1                 | 1     | 2            | 1             | 1                 |
| B (school#2) | 1                 | 1     | 2            | 1             | 1                 |
+--------------+-------------------+-------+--------------+---------------+-------------------+
```

### 6.2 Seat 1 — `maker@drive.test` (accounts_officer, school#1), healthy path

```
url: http://localhost:8001/finance/discount-policies   title: Discount policies - Laravel
h1            : "Discount policies"
subtitle      : "Every reduction on an invoice has to name one of these, and only the executive director may change the list."
hero buttons  : ["Refresh","Propose a policy"]
card[0]       : skeleton=false texts=["Active","1","Citable on an invoice raised today"]
card[1]       : skeleton=false texts=["Superseded","0","Replaced by an amendment, still explaining old invoices"]
card[2]       : skeleton=false texts=["Retired","0","Withdrawn from choice, never deleted"]
counter       : "Showing 1 of 1"          (present)
spanning cells: []                        (no closed rows, so no divider)
status filter : value="" label="All policies"
rows (1)
  row[0] : ["Sibling discountSecond and subsequent children enrolled in the same term.","Percentage","10% of discountable charges","A bursar applies it directly on the invoice","Active",""]
  pill[0]: Active :: … bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400
  actions: [{"buttons":["Amend Sibling discount","Retire Sibling discount"],"text":""}]
```

The Actions cell's `text` is `""` because both controls are icon-only; the `aria-label`s are what
carry their names, which is why they are read here instead of the text.

### 6.3 What the fixture could not show, and how it was reached anyway

The brief is right about the fixture: `DriveFinanceStates::ensureDiscountPolicy()` seeds **one**
policy per school — a **percentage**, `requires_approval` false, `status` active — so the amount-basis
rendering, the superseded pill, the retired pill and the approval-required wording are all unreachable
by seeding. **They were not left code-read.** Each was authored through the real flow, maker proposing
and the ED approving at `/finance/approvals` in a second browser context (separate cookie jar — the
two seats cannot be one login, and sharing a context silently redirects the second login off
`/login`).

**Round 1 — amend, percent → fixed amount.** The amend modal prefilled from the policy being
superseded, as the docblock's rule requires:

```
amend prefill: {"modalTitle":"Amend Sibling discount","name":"Sibling discount",
                "description":"Second and subsequent children enrolled in the same term.",
                "basisTrigger":"percent","percent":"10"}
```

Basis switched to `amount`, `25000.50` typed, reason given, sent. The ED's queue then showed:

```
["Discount policy","amend · Sibling discount · ₦25,000.50","—","Maker Drive",
 "Board fixed the sibling reduction at a flat rate for 2026/2027.","16/08/2026","—","ApproveReject"]
```

**The money arithmetic, so a reader can check it:** `25000.50` naira typed into `#dp-amount` →
`nairaToMinor` → `value_minor` **2500050** minor units, `value_currency` `NGN` posted explicitly →
rendered back through `formatNaira` as **`₦25,000.50`** on both the approvals queue and this screen's
Value cell. Nothing in either page computed it; the only two conversions are the named helpers in
`@/lib/format`.

After approval:

```
counter       : "Showing 2 of 2"
spanning cells: ["No longer in useSuperseded and retired policies stay here for good. Each one may still be
                 the only thing that explains a discount on an invoice issued months ago, so none of them can
                 be deleted — and none of them can be amended or retired again."]
rows (2)
  row[0] : [… ,"Fixed amount","₦25,000.50","A bursar applies it directly on the invoice","Active",""]
  row[1] : [… ,"Percentage","10% of discountable charges","A bursar applies it directly on the invoice","Superseded","Kept for the invoices it priced"]
  pill[0]: Active     :: bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400
  pill[1]: Superseded :: bg-slate-100   text-slate-600   dark:bg-slate-800      dark:text-slate-300
```

Active above, superseded below, the divider between them, controls on the first and the sentence on
the second — the § 2.2 rule, rendered.

**Round 2 — retire.** The new active policy was retired through the same two-seat flow
(`"retire · Sibling discount"` in the queue, approved). The list then had **no active row at all**,
which exercises the divider's `index === 0` branch:

```
card[0] "Active" = "0"   card[1] "Superseded" = "1"   card[2] "Retired" = "1"
counter: "Showing 2 of 2"
rows (2)
  row[0] : [… ,"Percentage","10% of discountable charges", … ,"Superseded","Kept for the invoices it priced"]
  row[1] : [… ,"Fixed amount","₦25,000.50", … ,"Retired","Kept for the invoices it priced"]
  pill[0]: Superseded :: bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300
  pill[1]: Retired    :: bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300
```

Both closed states share the slate tone and are told apart by their label, which is the § 21 rule
working as intended rather than a compromise.

**Round 3 — a `requires_approval = true` policy**, the last unrendered branch. Proposed
("Hardship award", 50%, second radio), approved by the ED. Final state, all three statuses, both
bases, both approval wordings:

```
card[0] "Active" = "1"   card[1] "Superseded" = "1"   card[2] "Retired" = "1"
counter: "Showing 3 of 3"
rows (3)
  row[0] : ["Hardship awardCase-by-case, on evidence of family circumstance.","Percentage","50% of discountable charges","Each award needs the ED’s sign-off — raised as a credit note, never as an invoice line","Active",""]
  row[1] : ["Sibling discount…","Percentage","10% of discountable charges","A bursar applies it directly on the invoice","Superseded","Kept for the invoices it priced"]
  row[2] : ["Sibling discount…","Fixed amount","₦25,000.50","A bursar applies it directly on the invoice","Retired","Kept for the invoices it priced"]
```

Screenshots `maker-20-all-states-light.png` / `maker-21-all-states-dark.png`.

### 6.4 Filters and inline validation

```
search "hardship"          → counter "Showing 1 of 3", 1 row, cards unchanged at 1/1/1
status filter → "retired"  → trigger value="retired", counter "Showing 1 of 2" (at that point), 1 row
search "zzzz-no-such-…"    → counter "Showing 0 of 3", empty state:
    ["No discount policies to show","No policies match this view. Try clearing the filters.","Clear filters"]
```

The KPI cards stay at the whole-catalog counts under every filter — they are the school's headline
figures and a view filter must not restate them.

Submitting the modal empty produced the three inline messages and no request:

```
["A policy needs a name — this is what a bursar picks it by.",
 "Enter the amount taken off, in naira — for example 25000 or 2500.50.",
 "The executive director reads this, and it is the only context they get."]
```

### 6.5 Isolation — by id, both seats side by side

The two schools' policy **names are identical strings by construction** (`Sibling discount` in both),
so the DOM labels prove nothing. The ids do. Read with a page-initiated `fetch()` (carries the session
cookie _and_ a `Referer`, so Sanctum treats it as stateful — `page.request.get()` 401s, which is the
harness artifact the skill records):

```
Seat 1 — `maker@drive.test` (accounts_officer, school#1)
  CATALOG BY ID (uuid|status|basis|name):
    a2845ba7-6cd4-4d66-b1f6-8592b4a64240|superseded|percent|Sibling discount
    a2845e5c-c485-4e8b-ad3b-5ce14e9f1709|retired|amount|Sibling discount
  cards: Active 0 · Superseded 1 · Retired 1     counter: "Showing 2 of 2"

Seat 2 — `school-b@drive.test` (accounts_officer, school#2)
  CATALOG BY ID (uuid|status|basis|name):
    a2845ba7-6f12-4307-9216-69f77479fcc2|active|percent|Sibling discount
  cards: Active 1 · Superseded 0 · Retired 0     counter: "Showing 1 of 1"
```

Three disjoint uuids and one repeated name. The second half of the check also holds: School A's
authored amount policy (`a2845e5c-…`, `₦25,000.50`, retired) is **absent** from School B's list, and
School B's own policy is absent from School A's — School B still shows exactly the one seeded
percentage policy, untouched by three approvals in School A.

### 6.6 Console, every page

Every page load in every seat produced exactly these, and nothing else:

- `[vite] connecting…` / `[vite] connected.` (debug — the dev-server client in the built bundle)
- `Download the React DevTools…` (info)
- **`Failed to load resource: the server responded with a status of 403 (Forbidden)`** — on the
  **login POST redirect only**, for every finance seat. This is the pre-existing `/dashboard` 403 the
  skill documents under Friction (`feat-discount-policies-page.md:456-460`): the finance seats sign in
  and are refused on `/dashboard`. Not caused by this change and not fixed by it.
- `Slow network is detected … Fallback font will be used` (info) — the bunny.net webfont on one seat.
- On the forced-error run only: `Failed to load resource: … 500 (Internal Server Error)`, which is
  the interception doing its job.

No `pageerror`, no uncaught exception, no React warning, on any page in any seat.

### 6.7 Dark mode

Both themes captured on the list, the error state, the modal and the all-states view. Per § 26,
**dark mode is unreachable by any user today**
(`docs/handoff/tickets/dark-mode-is-unreachable-for-every-user.md`), so I say plainly what I did: I
set `document.documentElement.classList.add('dark')` directly and screenshotted.

**CORRECTED 2026-08-16 (cold review). The first version of this section said "every region reads
correctly at that setting". That is false, and my own screenshot was the evidence against it.**
`maker-05-modal-amount-dark.png` shows the proposal modal with unreadable field labels, and I filed
it as a pass. Measured afterwards with `getComputedStyle`, `.dark` on `<html>`
(`fix-07-dark-modal-measured.png`):

```json
{
    "htmlHasDark": true,
    "modalPanelBg": "rgb(255, 255, 255)",
    "modalTitleColor": "oklch(0.21 0.034 264.665)",
    "fieldLabelColor": "oklch(0.95 0.01 260)",
    "legendColor": "oklch(0.929 0.013 255.508)"
}
```

`resources/js/components/ui/Modal.tsx` carries **zero** `dark:` variants (`grep -c` → 0; same for
`ConfirmDialog.tsx`, `EmptyState.tsx` and `Toast.tsx`), so the panel stays `bg-white` while this
screen's correctly-paired `dark:text-slate-200` labels flip to near-white. Near-white on white is
about **1:1**. Every field label in that modal is invisible in dark mode. Filed as
[`ui-chrome-components-have-no-dark-variants.md`](../tickets/ui-chrome-components-have-no-dark-variants.md).

The lesson is not about the modal. **I took the screenshot, filed it, and read "I captured both
themes" as "both themes are correct".** A dark screenshot proves nothing until someone reads the
contrast in it, and I did not.

What I can now state, having actually looked: the list regions are correct — the three cards, the
three pills (each tone has its `dark:` pair), the divider row, the counter and the error row. The
**modal is not**, and neither is any other flow that uses `Modal`, `ConfirmDialog`, `EmptyState` or
`Toast`, which is most of the application. A separate and minor note that survives: the divider row's
`dark:bg-slate-900/30` is nearly indistinguishable from the card surface; the uppercase
`NO LONGER IN USE` label carries it, so it is legible, but the tint is doing no work there.

### 6.8 What was NOT driven

- **A reject.** The ED approved all three proposals; no rejected discount-policy change was rendered
  anywhere. This screen renders no proposal state at all, so the gap is in `/finance/approvals`'
  coverage rather than in this one — but it is the same gap every previous drive has recorded.
- **A genuinely empty catalog** (a school with zero policies, showing the non-filtered empty copy
  _"Until one is approved no invoice can carry a discount at all…"_). Both fixture schools are seeded
  with one policy and `finance_discount_policies` has a **no-DELETE trigger**, so there is no way to
  reach zero from the fixture without changing the seeder. The **filtered** empty state was driven and
  the two share the same branch and differ only in the sub-sentence; the unfiltered sub-sentence is
  therefore **code-read only**.
- **The `super@drive.test` and `void-checker@drive.test` seats.** Neither is about this screen: the
  page route is gated on the one maker ability, `finance.discount-policy.change.submit`, and the two
  route-level arms in `DiscountPoliciesScreenTest` already pin who gets in and who is refused. Not
  driven, and not claimed.
- **A 422 from the server.** The inline (client-side) validation was driven; the server's own refusals
  — an already-open request for the same policy, a cross basis combo — were not provoked, so
  `parseErrorBag`'s message-without-a-bag branch is code-read only. It is unchanged by this commit.

---

## 7. `git diff --stat`, raw

```
$ git diff --stat origin/staging...HEAD -- ':!docs/handoff/drives'
 .../tickets/fee-schedule-index-unpaginated.md      |  50 +-
 .../js/pages/admin/finance/discount-policies.tsx   | 808 ++++++++++++++++-----
 .../Feature/Finance/DiscountPoliciesScreenTest.php |  86 +++
 3 files changed, 739 insertions(+), 205 deletions(-)

$ git diff --stat origin/staging...HEAD | tail -4
 .../tickets/fee-schedule-index-unpaginated.md      |  50 +-
 .../js/pages/admin/finance/discount-policies.tsx   | 808 ++++++++++++++++-----
 .../Feature/Finance/DiscountPoliciesScreenTest.php |  86 +++
 31 files changed, 739 insertions(+), 205 deletions(-)
```

The 31-file figure is the 3 source files plus 28 drive screenshots (binary, so they contribute no
line counts).

---

## 8. `bin/quality`, raw

Re-derived, not carried: `grep -c '^\s*step "' bin/quality` → **15**, and `bin/quality:59` prints
`[%d/15]`. Both agree, which is what `tests/Feature/Quality/QualityStepCountTest.php` exists to keep
true.

```
quality gate — base 820d001

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint: no changed PHP files
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
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

### 8.1 Which steps actually read my files

**Step 3 read none of them, and said so out loud.** `Pint: no changed PHP files · Prettier: no
changed frontend files · ESLint: no changed JS/TS files` — because `bin/lint-changed.sh:51` diffs
`"$BASE"...HEAD` and the work was uncommitted when the gate ran. That is the known ticket
(`docs/handoff/tickets/lint-changed-cannot-see-uncommitted-work.md`), and it is exactly the shape of
a green that proves nothing.

So I ran the three tools by hand against the file before the gate — `prettier --write`
(`All files formatted correctly`), `eslint` (`No issues found`), `pint --test` on the test file
(`passed`) — and then, **after committing**, ran the gate's own script with the right base to confirm
it now sees them:

```
$ bash bin/lint-changed.sh origin/staging
==> Pint (check) on 1 changed PHP file(s)
{"tool":"pint","result":"passed"}
==> Prettier (check) on 1 changed file(s)
Checking formatting...
All matched files use Prettier code style!
==> ESLint on 1 changed file(s)
   (no output — clean)
```

| Step                          | Read my files?              |                                                                          |
| ----------------------------- | --------------------------- | ------------------------------------------------------------------------ |
| 3 lint-changed                | **no** (as run in the gate) | Uncommitted; re-run post-commit above, clean on all three.               |
| 4 tsc ratchet                 | **yes**                     | Whole tree.                                                              |
| 5 vite build                  | **yes**                     | The page is in the bundle.                                               |
| 9 money lint                  | **yes**                     | Scans all of `resources/js` (`bin/ci-money-lint.php:78`).                |
| 15 test ratchet               | **yes**                     | Ran `DiscountPoliciesScreenTest` as part of the unfiltered suite.        |
| 14 larastan                   | no                          | `phpstan.neon` `paths: app` only — neither the `.tsx` nor the test file. |
| 1, 2, 6, 7, 8, 10, 11, 12, 13 | no                          | None of their scopes covers a page component or this test file.          |
| any                           | no                          | Nothing reads `docs/handoff/tickets/fee-schedule-index-unpaginated.md`.  |

**On the shared-component lint hole named in the brief:** `resources/js/components/ui/*` is in **both**
`.prettierignore` and the ESLint ignore list, so the lint step would have skipped anything I changed
there. Verified live and incidentally, in the `origin/main`-based run of `lint-changed.sh`:

```
/Users/mac/Documents/Projects/portal/resources/js/components/ui/base-dropdown.tsx
  0:0  warning  File ignored because of a matching ignore pattern.
```

**I changed nothing under that directory** (`git diff --name-only origin/staging...HEAD` lists three
source files, none of them there), so the hole is not load-bearing for this change — but that is a
fact about the diff, not a property of the gate.

---

## 9. The pagination dependency

`DiscountPolicyController::index()` (`app/Finance/Http/Controllers/DiscountPolicyController.php`) is
unpaginated: it validates one optional `status` (`:39-41`), applies it with `->when()` (`:44-47`),
orders by name (`:48`) and ends in **`->get()` at `:49`**. No `page`, no `per_page`, no envelope. The
whole School's catalog arrives in one response, so the client-side search, the status filter and both
counter numbers are drawn from the same array as the rows — sound today, for the same reason and with
the same expiry as bank accounts and fee schedules.

Per § 26 (_"Write the dependency into the ticket that will break you, and name every dependent"_), the
screen is added to `docs/handoff/tickets/fee-schedule-index-unpaginated.md`, which now reads **six
dependents across three endpoints** rather than five across two. The row cites exact lines
(`:439-453` filter, `:458-461` ordering, `:235-236` state, `:227-234` comment, `:626`/`:630` counter,
`:433-437` and `:549`/`:557-558`/`:565-566` cards — **all re-derived 2026-08-16 after the cold-review
round moved them, which is the third time this branch has had to restate its own line numbers**), and
the ticket's own standing warning applies to them:
those numbers rot, the symbol names do not.

One thing I added to the ticket's fix section that the other two dependents do not need: a server-side
status filter on this screen **cannot** be implemented by adding `?status=` to its existing fetch.
`DiscountPoliciesScreenTest`'s last arm asserts that URL stays unfiltered, because a superseded or
retired policy is the only thing that can explain a discount on an old invoice. The default view has
to remain all states.

---

## 10. Things I changed that the brief did not ask for, and why

- **The `eslint-disable` on the initial-fetch effect.** The old file carried a comment stating
  explicitly that `react-hooks/set-state-in-effect` did _not_ fire here, so copying the siblings'
  disable would be an unused directive claiming a rule was being broken when it was not. That was
  true of the old `load` and **stopped being true** when this one grew the error state. Verified both
  ways rather than assumed: eslint on the pre-change revision of the file is silent, and on this one
  reports the rule at the `void load()` line. The comment now says that, and the disable is the same
  one `bank-accounts.tsx:113` and `finance/index.tsx:95` carry.
- **Modal buttons moved into `Modal`'s `footer` prop** (§ 10 requires the footer, and the old file put
  them inline in the body). Same two buttons, same handlers.
- **`text-amber-800` → `text-amber-700 dark:text-amber-400`** on the approval-required sentence. The
  old class had no `dark:` pair, which § 20 calls the most common bug.
- **The long header paragraph moved.** § 4 requires a one-line subtitle. The argument it carried — a
  policy's terms are immutable, an amendment supersedes rather than edits — now sits where it is
  acted on: the amend modal's notice (unchanged text) and the divider above the closed rows.

---

## 11. What I could not verify

1. **Anything a JavaScript test would catch.** There is no JS test runner here. Every claim in § 3
   about what the failed path renders comes from reading the live DOM in a browser, not from an
   assertion that will re-run. If someone inverts `error ? '—' : String(activeCount)`, all fifteen
   gate steps stay green.
2. **The unfiltered empty state's sub-sentence** — code-read only. See § 6.8.
3. **The server-side 422 paths** (`parseErrorBag`'s message-without-a-bag branch) — unchanged by this
   commit, and not provoked in the drive.
4. **Dark mode as a user experiences it** — unreachable; I set the class directly and say so (§ 6.7).
5. **Whether this is now the last pre-guide Finance page.** I have not enumerated the set, and § 0 is
   about what happens when someone asserts that without enumerating it. I am not repeating the claim.
6. **The gate's non-determinism.** `bin/quality` passed 15/15 once, in ~7 minutes. ADR 0053 records
   that byte-identical code has produced both a pass and a 23-failure red on this machine, one in
   twelve runs, cause not found. One green is one green.
7. **`resources/js/components/ui/base-dropdown.tsx`'s six pre-existing consumers.** I exercised the
   two on this screen. The other five were not opened; nothing in this change touches the component,
   but "nothing touches it" is a claim about the diff, not a test.

---

## 12. The cold-review round (2026-08-16)

A cold review of this branch returned seven findings. All seven were verified against the repository
before anything was changed; all seven held. The corrections are inline above, marked
**CORRECTED**; this section carries what is new.

### 12.1 The counter was lying in the LOADING state, on three screens

**The defect.** The predicate was `{!error && …}`, not `{!error && !loading && …}`. `load()` clears
`error` and sets `loading` in the same breath, so during every fetch `error` is `false`, `loading` is
`true` and the array is `[]` — and the page renders **"Showing 0 of 0"**. On first paint, on Refresh,
and on the Retry click that leaves the error state. That last one is the sharpest version: the
counter was suppressed on the error path by this very branch, and then said "0 of 0" the instant the
operator clicked the button offered to fix it.

This is § 26's **fifth** recorded manifestation of the state-confusion defect, and the third
involving this exact counter. My § 3 enumeration is why it survived: I enumerated every number and
sentence **on the failed path** and stopped there. The rule as § 26 stated it says to enumerate the
other regions; it did not say to enumerate the other **states**. It does now.

**Inherited, and fixed on all three anyway.** Identical predicates were live and merged on
`bank-accounts.tsx` and `fee-schedules.tsx`. Leaving two known-false screens shipped because they
belong to someone else's commit is how this defect reached its third occurrence, so all three are
fixed here.

**The three call sites, re-derived after the fix:**

| File                                                     | Line  | Now                        |
| -------------------------------------------------------- | ----- | -------------------------- |
| `resources/js/pages/admin/finance/discount-policies.tsx` | `622` | `{!error && !loading && (` |
| `resources/js/pages/admin/finance/bank-accounts.tsx`     | `363` | `{!error && !loading && (` |
| `resources/js/pages/admin/finance/fee-schedules.tsx`     | `744` | `{!error && !loading && (` |

**Bite-proved in the browser, on two of the three screens, in three states.** The catalog fetch was
held open for 4 s so the loading state is observable, then answered normally. Raw:

```
=== discount-policies ===
DURING LOAD : {"counterPresent":false,"counterText":null,"spinnerInTable":true,
               "cards":["Active=Citable on an invoice raised today",
                        "Superseded=Replaced by an amendment, still explaining old invoices",
                        "Retired=Withdrawn from choice, never deleted"]}
AFTER LOAD  : {"counterPresent":true,"counterText":"Showing 3 of 3","spinnerInTable":false,
               "cards":["Active=1","Superseded=1","Retired=1"]}

=== the Retry click, out of the error state ===
ERROR STATE : {"counterPresent":false,"counterText":null,"errorRow":true,
               "cards":["Active=—","Superseded=—","Retired=—"]}
MID-RETRY   : {"counterPresent":false,"counterText":null,"spinnerInTable":true,"errorRow":false,
               "cards":["Active=Citable on an invoice raised today", …]}
AFTER RETRY : {"counterPresent":true,"counterText":"Showing 3 of 3","errorRow":false,
               "cards":["Active=1","Superseded=1","Retired=1"]}

=== bank-accounts (the inherited site) ===
DURING LOAD : {"counterPresent":false,"counterText":null,"spinnerInTable":true,
               "cards":["Bank accounts=Every account this school has added", …]}
AFTER LOAD  : {"counterPresent":true,"counterText":"Showing 1 of 1","spinnerInTable":false,
               "cards":["Bank accounts=1","Active=1","Deactivated=0"]}
```

`counterPresent: false` while the spinner is in the table, `true` with a real number after — on both
screens, and across the error → loading → loaded transition that the Retry button drives. The cards
show their **labels only** during load, which is the skeleton doing its job: the value `<p>` is
replaced by the `animate-pulse` block, so there is no number to be wrong. Screenshots
`fix-01` … `fix-06`.

**The § 3 read-back, restated for the loading path** (the enumeration that was missing): `h1`,
subtitle and both hero buttons are static; the three card **values** are `animate-pulse` skeletons
and render no number; the counter is **absent**; the search box and status filter render their own
constants; the Clear button appears only if filters are set; the table body is a single spanning row
holding a centred `Spinner`; the divider row and both empty-state sentences are unrendered. Nothing
on the loading path now states a quantity.

### 12.2 Two false sentences in the file this branch wrote

Both verified against the code before being changed; both are corrected in place, and the server gap
is ticketed rather than fixed inside a UI commit. See the **CORRECTED** block in § 2.2 for the
finding, and
[`discount-policy-changes-do-not-check-target-status.md`](../tickets/discount-policy-changes-do-not-check-target-status.md)
for what is and is not refused.

Two further claims in the same docblock, both new on this branch and both wrong:

- _"There is no fifth control anywhere on the page."_ The **rule** is four **acts**, which is true.
  The sentence said controls, and there are ten-odd interactive elements outside the modal — a search
  box, a dropdown trigger and its four options, a Clear button, a Retry button, an empty-state
  button. Reworded to say acts, and to say why the distinction matters.
- _"(an arch test says so)"_ on the single-writer claim. **The fact holds** — a repo-wide search finds
  no other writer — **but the citation does not**: `tests/Arch/` holds four files
  (`ArchitectureBoundaryTest`, `BoundaryLintCoverageTest`, `NotificationsArchTest`,
  `SqlClockLintCoverageTest`) and none mentions `DiscountPolic`. The same claim appears in
  `ApproveDiscountPolicyChange.php:21`. The docblock now states the fact and states that nothing
  enforces it, which by this project's own rule makes it a wish rather than a rule.

### 12.3 Two stale cross-file citations this branch created

`new-invoice-modal.tsx` cited `discount-policies.tsx:167-172` and `:183-185`. Both were correct at
`820d001` and both were broken by this branch's rewrite of that file — the first now points at
`parseErrorBag`, the second at a passage that records the **opposite** outcome (that screen now
carries the `eslint-disable` the comment cited it for not needing, because growing an error state
made the rule start firing). Both fixed: `:248-252` and `:268-276`, re-derived, with the reason for
the change written next to them.

### 12.4 The pagination ticket, re-derived rather than adjusted

Three problems, all confirmed:

- **A seventh dependent was unnamed.** `new-invoice-modal.tsx:282-296` fetches the same catalog with
  `params: { status: 'active' }` and narrows it again in the browser via `selectablePolicies`
  (`:73-81`, `requires_approval !== true`). Paginated, the modal offers the applicable policies **on
  page 1** and otherwise asserts the school has none. Same class as `record-payment-modal`, which the
  ticket already listed. It was missed twice, and the cause is the same both times: the section is
  organised by _screen_, and a modal is not a screen.
- **The prose counts had drifted from the table** — "three screens and one modal", "a sixth
  dependent", "either endpoint", "both table cards". Every figure re-derived from the table rather
  than nudged: **eleven dependent sites across five files and three endpoints.**
- **Every fee-schedules citation was stale, and had been _retyped_ while stale.** `fee-schedules.tsx`
  is untouched by this branch, so the rot predates it; the defect is that the previous round's reflow
  restated all four rows with the authority of a fresh edit, directly beneath that table's own warning
  not to. All four were wrong by 17–20 lines. Every citation in the table is now re-derived, with the
  commands that produce them.

### 12.5 Three tickets

- [`discount-policy-changes-do-not-check-target-status.md`](../tickets/discount-policy-changes-do-not-check-target-status.md)
  — the server gap behind § 12.2, with what each layer does and does not check.
- [`ui-chrome-components-have-no-dark-variants.md`](../tickets/ui-chrome-components-have-no-dark-variants.md)
  — `Modal`, `ConfirmDialog`, `EmptyState` and `Toast` carry **zero** `dark:` variants between them,
  with the measured contrast from § 6.7 and a cross-reference to
  `dark-mode-is-unreachable-for-every-user.md`, which is the ticket this one is waiting behind.
- No new malformed-200 ticket. `setPolicies(data ?? [])` on this screen validates no shape, and
  [`a-malformed-200-renders-the-empty-state-not-the-error-state.md`](../tickets/a-malformed-200-renders-the-empty-state-not-the-error-state.md)
  already covers it — its table listed `discount-policies.tsx:172`, which this branch moved to
  `:256`. That ticket is updated instead of duplicated: all six unwrap sites re-derived, the two
  modal sites added (they were missing), and a note that the redesign closed the `catch` door on this
  screen and left this one exactly as described.

### 12.6 What this round could still not verify

Everything in § 11 still stands. Added:

- **The `fee-schedules.tsx` counter fix is not bite-proved in a browser.** The predicate is
  character-identical to the two that were, and the surrounding state machine is the same, but that is
  an argument and not an observation. Driving it needs a fee-schedule fixture state and a term
  selection this round did not set up.
- **The dark-mode measurement covers one modal on one screen.** `ConfirmDialog`, `EmptyState` and
  `Toast` are asserted to have zero `dark:` variants by `grep`, which is a fact about the files; what
  that looks like rendered was not measured for those three.
- **No new arm pins the counter fix.** There is still no JavaScript test runner. Reverting
  `!loading` on any of the three screens reds nothing, and the next gate run is green.
