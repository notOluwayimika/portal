# Bank accounts + fee schedules — laid out to the design system

**Branch:** `feat/ui-bank-accounts-fee-schedules-redesign`
**Base:** `66ad215add030d461b7e995c0564f0a834d63c15` (`origin/staging`, fetched at the start of the
work — the branch was cut from the fetched ref, not from a local `staging` that might have drifted).
**Date:** 2026-08-15
**Scope:** `resources/js` only. No PHP, no migration, no route, no permission, no test file.

---

## 1. What changed, and why

Both screens predated [`docs/ui-ux-design-system.md`](../../ui-ux-design-system.md) and were the last
two Finance pages still on the old shape: a bare `space-y-4 p-4` wrapper with no page shell, an
unstyled `text-sm` table with `p-2` cells, raw `<select>` elements, raw `<input type="checkbox">`,
and a single `<Spinner />` standing in for loading, empty **and** error at once.

They now follow the canonical list-page structure the guide generalises from
(`resources/js/pages/admin/finance/index.tsx`): page shell → hero card → KPI stat cards →
filter/table card, with the guide's type scale, spacing, dark-mode pairs and row-action treatment.

Substantive changes beyond styling, each with its reason:

### 1.1 A distinct error state (§ 13)

**This is the change that fixes a real defect, not an appearance.** Before, a failed list fetch
called `toast.error(...)` and left `accounts`/`schedules` as `[]` — so the screen rendered its empty
state. **"This school has no bank accounts" and "the request failed" were the same screen**, three
seconds after the toast faded. Both pages now carry an `error` boolean and render a third,
distinguishable branch: a red `AlertCircle`, "Could not load …", and a **Retry** button that re-runs
the fetch. Driven and screenshotted — § 6.6.

The first cut of this applied the distinction to **the table only**, which left the KPI cards and the
"Showing X of Y" counter still rendering hard zeros above a correct error row — the same lie, two
elements higher. That was caught by the drive, not by reasoning, and is fixed in § 11. The error
state is now page-wide: **on a failed load, nothing on either screen renders a number.**

### 1.2 Search and filters, and the argument that makes them legal

Both pages gained a search box; bank-accounts also gained an active/deactivated status filter.

The design system bans this in general (§ 7: "never a client-side filter that silently disagrees
with server pagination"). It is permitted here for one specific reason: **neither endpoint
paginates.** `BankAccountController::index()` takes no query parameters and returns the School's
whole list; `FeeScheduleController::index()` applies `term_id`/`status` server-side with `->when()`
and returns everything matching, with no envelope. So "Showing X of Y" draws **both numbers from the
same array** and there is nothing for a client filter to disagree with.

That argument is load-bearing and it expires the day someone paginates either endpoint. It is
therefore recorded in three places, not one:

- a comment at each site — `fee-schedules.tsx:229-234`, `bank-accounts.tsx:81-86`;
- a new **"What now DEPENDS on this endpoint staying unpaginated"** section in
  [`docs/handoff/tickets/fee-schedule-index-unpaginated.md`](../tickets/fee-schedule-index-unpaginated.md),
  naming every dependent line and what each one does wrong once pagination lands;
- this report.

The comments alone were not enough, and that is the point of the ticket section: **a comment in a
`.tsx` is only found by someone already editing that `.tsx`, and the person who paginates the
endpoint is editing a controller.**

### 1.3 Shared components replace hand-rolled ones (§ 23)

Raw `<select>` → the shared `Select` (`@/components/ui/base-dropdown`), on the filter rows **and**
inside the fee-line form. Raw checkboxes → the shared `Checkbox`, each now with a real `<Label
htmlFor>`. The status-pill markup both pages carried separately → a new
`resources/js/components/finance/status-pill.tsx`, matching `AccountStatusBadge`'s shape and tone
map exactly.

### 1.4 What deliberately did NOT change

No API contract, no permission gate, no money path, no write path. Specifically preserved:

- the `currency` field carried through `openFrom()` (an edit replaces items wholesale, so a dropped
  currency re-denominates the schedule);
- the per-row 422 bag (`items.0.bank_account_id` split by row index);
- the `canPropose` gate on submit/retire — a different ability from the one that opens the screen;
- the identity-fields-not-rendered-on-edit guard (see § 4 — this one fought back);
- `sonner` as the toast library. The guide names `react-toastify`, but 3 of the 4 Finance pages use
  `sonner` and both containers are mounted (`app-layout.tsx:20`, `app.tsx:31`). Switching libraries
  mid-redesign is a separate decision; **flagged as guide/code drift, not resolved here.**

No `<Pagination>` footer on either table card, because neither endpoint pages. Adding one would
require faking it client-side, which is § 1.2's defect wearing a different hat.

---

## 2. The `base-dropdown` fix, and its six consumers

`resources/js/components/ui/base-dropdown.tsx` portals its option panel to `document.body` and
positions it `fixed`, computing coordinates **once, at open** (`updateDropdownPosition` was called
only from `toggleDropdown`). Fixed coordinates do not move with scroll, so **the panel detached from
its trigger the moment anything scrolled** and floated over unrelated content.

This was **pre-existing and repo-wide** — not introduced here. It became blocking for this branch
because the fee-schedule form's "Paid into" select now sits inside the modal's
`max-h-[70vh] overflow-y-auto` body, which an operator with eight fee lines certainly scrolls.

Three changes, all additive:

1. **Reposition on scroll and resize while open.** `document.addEventListener('scroll', …, true)` —
   `capture: true` is the load-bearing detail: **scroll does not bubble**, so a listener on
   `document` without capture never sees an ancestor container scrolling. Listeners are attached
   only while the panel is open and removed on close.
2. **An optional `id`** on the trigger button, so `<Label htmlFor>` can name the control. Swapping a
   native `<select id>` for this component had otherwise pointed three labels at nothing.
3. **`data-value` on the trigger and on every option.** See § 5.
   (This originally also added `aria-haspopup="listbox"`, `role="listbox"`, `role="option"` and
   `aria-selected`. **All four were removed in round 4** — the component has no keyboard handling to
   back them. `aria-expanded` is kept. See § 12.2 and
   [`base-dropdown-is-not-keyboard-operable.md`](../tickets/base-dropdown-is-not-keyboard-operable.md).)

### The six consumers, and which this branch touched

| #   | Consumer                                                 | Touched by this branch?                       | Driven?                                    |
| --- | -------------------------------------------------------- | --------------------------------------------- | ------------------------------------------ |
| 1   | `resources/js/pages/admin/finance/bank-accounts.tsx`     | **yes** — new consumer (was a raw `<select>`) | yes, both seats, both themes               |
| 2   | `resources/js/pages/admin/finance/fee-schedules.tsx`     | **yes** — new consumer (was raw `<select>`s)  | yes, both seats, both themes, page + modal |
| 3   | `resources/js/pages/admin/finance/index.tsx`             | no                                            | **yes — scroll proof, § 6.5**              |
| 4   | `resources/js/pages/admin/students/index.tsx`            | no                                            | **yes — scroll proof, § 6.5**              |
| 5   | `resources/js/pages/admin/teachers/index.tsx`            | no                                            | no — see § 7                               |
| 6   | `resources/js/pages/admin/teacher-assignments/index.tsx` | no                                            | no — see § 7                               |

The change is to shared code, so proving it on the two screens that _needed_ it would have been
proving the easy half. Consumers 3 and 4 were driven precisely because they were **already relying
on this component** and did not ask for the fix.

---

## 3. KPI cards count ROWS — never sums, never money

Both pages gained a three-card KPI row. Every value is a **row count**:

- bank-accounts: `accounts.length`, `accounts.filter(a => a.is_active).length`, and the complement.
- fee-schedules: `visible.filter(s => s.status === …).length` for draft / pending_approval / active.

**No card sums anything, and no card touches money.** This is not stylistic caution — it is the
Constitution's money rule and `bin/ci-money-lint.php` enforces it (the lint refuses arithmetic on
`amount_minor`, and refuses `.reduce(` outright anywhere in the Finance UI). A "Total value of
active schedules" card is the obvious fourth card and it is **exactly the thing that must not
exist** on the frontend: the schedule totals are summed by `FeeScheduleResource` in PHP precisely
because the frontend is not allowed to add money, and adding them up across schedules in JS would
reintroduce the float-money bug the backend was built to prevent. If that number is wanted, the API
returns it.

There is a second honesty constraint. The fee-schedule cards count **the loaded set**, and
`term_id`/`status` are applied _server-side_ — so with a status filter active, two of the three
cards are 0 by construction. Their sub-text therefore reads **"In this view — …"** rather than
implying a school-wide total. A card in the position the design system reserves for headline metrics
must not quietly describe a filtered subset.

**A defect in this very rule was found by the drive — see § 8.1.** The cards are honest about
filtering and dishonest about failure.

---

## 4. The `BankAccountTest` LAYER 3 incident

**What happened.** While restructuring `bank-accounts.tsx` I hoisted the modal's field-list ternary
out of the JSX into a `const fields = editing ? [...] : [...]` above the `return`. Purely cosmetic —
the rendered output was identical, and the guard it implements (bank name and account number are
**not rendered at all** when editing, not disabled and not readonly) was fully intact.

`BankAccountTest`'s LAYER 3 arm then failed:

```
Expecting '' not to be ''.
tests/Feature/Finance/BankAccountTest.php:359
```

**Why.** There is no JS test runner in this repo, so that arm asserts on the **source read as
text**. It isolates the edit branch by splitting the file on the literal `{(editing`
(`BankAccountTest.php:356-357`) and then asserts `bank_name` and `account_number` do not appear in
it. My hoist removed that literal, so `explode` returned nothing, `$editBranch` became `''`, and the
two `str_contains` assertions that are the actual guard **would have passed vacuously against an
empty string.**

That is the part worth recording. **The guard did not go red because it caught something. It went
red because a deliberately-placed tripwire — `expect($editBranch)->not->toBe('')` — noticed it had
gone blind.** Without that one line, my refactor would have produced a green suite in which the
guard tested nothing, and the next person to add `'bank_name'` back into the edit branch would have
shipped an editable account number.

**What I did.** I restored the inline `{(editing ? … : …)}` form in the JSX and added a comment at
the site saying why it must stay inline and which test depends on it. **I did not touch the test.**
Adjusting an assertion so it matches a refactor I made for readability would have traded a real
guarantee for a cosmetic preference, and it would have done so invisibly — the suite would have gone
green either way.

**How I noticed, because that is the reusable part.** Not by reading the diff, and not by reasoning
about it — I had already convinced myself the change was equivalent, and it _was_ equivalent as
rendered. I noticed because I ran the feature tests for the screens I had touched **before** claiming
the work was done, having first grepped `tests/` for files naming these `.tsx` paths:

```bash
grep -rln "fee-schedules.tsx\|bank-accounts.tsx\|base-dropdown\|finance-stat-card" tests/
```

Two hits, both run. The transferable rule: **a frontend-only change is not automatically outside the
PHP suite's reach in this repo, because several guards here assert on source text rather than
behaviour.** Grep the test directory for your file's _path_ before you refactor its shape, and
re-read any assertion that splits on a string literal — those are the ones a rename or a hoist
silences rather than breaks.

The secondary lesson, aimed at whoever writes the next such guard: **the `not->toBe('')` line is
what made this recoverable.** A source-scanning assertion should always first assert that it found
something to scan.

---

## 5. `data-value` — a change the drive forced, and why it is not a violation

The `finance-drive` skill is explicit that School isolation is checked **by id, never by label**,
because `seedAcademicSlot()` gives both schools byte-identical labels ("2026/2027 — First Term",
"JSS 1", "Drive account · Drive Bank"). Past drives read those ids out of native
`<option value="…">`.

My own change had removed that. `base-dropdown` renders option **buttons** with the value held only
in a React closure — nothing in the DOM. So the swap from `<select>` to the shared component made
the isolation check unreadable from the page: **only labels were left, and the labels are identical
by construction.**

I added `data-value` to restore it. (I also added `role="listbox"`/`role="option"`/`aria-selected`
at the same time; **those were wrong and were removed in round 4** — § 12.2. Only the `data-*`
attributes were ever load-bearing for the drive, and only they remain.) Both are
inert — no styling and no behaviour keys on them.

**On the "a drive observes; it does not fix" rule:** this is not a finding about the system's
behaviour that I quietly repaired. It is a hole _my own change_ punched in the drive's ability to
produce the evidence the brief requires, discovered while building the harness and before the drive
proper began — the same category as the skill's sanctioned fixture exception (a precondition of the
drive, not a result of it). Every actual finding below is reported and left alone.

---

## 6. The drive

Per [`.claude/skills/finance-drive/SKILL.md`](../../../.claude/skills/finance-drive/SKILL.md).
Throwaway instance: `APP_ENV=drive`, database `portal_drive`, port 8001. Assets built with
`pnpm run build` **before** seeding and again after the `data-value` change. Browser driver was
`puppeteer-core` against system Chrome, installed under the session scratchpad — **not** in the
repo's `node_modules`. The drive script is throwaway and not committed.

### 6.1 The fixture count table, verbatim

```
Authoring slot per school — the fee-schedules screen selects a term, a class level and an account; the discount-policies screen amends and retires a policy:
+--------------+-------------------+-------+--------------+---------------+-------------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies |
+--------------+-------------------+-------+--------------+---------------+-------------------+
| A (school#1) | 1                 | 1     | 2            | 1             | 1                 |
| B (school#2) | 1                 | 1     | 2            | 1             | 1                 |
+--------------+-------------------+-------+--------------+---------------+-------------------+
```

No zero in any column, for either school — so both screens can author, and the drive was worth
starting. Checked before the browser was opened, as the skill requires.

### 6.2 Seat 1 — `maker@drive.test` (`accounts_officer`, school#1)

**`/finance/bank-accounts`** — `h1="Bank accounts"`

```
KPI cards : ["Bank accounts = 1","Active = 1","Deactivated = 0"]
counter   : "Showing 1 of 1"
rows(1)   : ["Drive accountDrive Bank | 9000000001 | — | Active | "]
PAGE select #0 current="" options(3): ["|All accounts","active|Active","inactive|Deactivated"]
EDIT modal: title="Edit Drive account" inputs=["","ba-label","ba-account_name"]
events    : clean
```

**`/finance/fee-schedules`** — `h1="Fee schedules"`

```
KPI cards : ["Drafts = 0","With the ED = 0","Active = 0"]
counter   : "Showing 0 of 0"
rows(0)   : []
PAGE select #0 current="" options(2): ["|All terms","1|2026/2027 — First Term"]
PAGE select #1 current="" options(6): ["|Any status","draft|Draft","pending_approval|With the ED","active|Active","superseded|Superseded","retired|Retired"]
props.terms        : ["1|2026/2027 — First Term"]
props.class_levels : ["1|JSS 1","2|JSS 2"]
MODAL select #2 current="1" options(1): ["1|2026/2027 — First Term"]
MODAL select #3 current="1" options(2): ["1|JSS 1","2|JSS 2"]
MODAL select #4 current="" options(2): ["|Choose an account…","a282b8eb-ac0b-49e7-abc0-94218e41b7aa|Drive account · Drive Bank"]
```

(`MODAL select #0/#1` in the raw log are the page's own filter selects, still mounted behind the
modal; the modal's three are #2, #3, #4.)

Note the placeholder: `"|Choose an account…"` — a `|` with **nothing to its left**. That is the
empty-string value of an unselected control, and the ellipsis belongs to the label on the far side
of the separator.

**What each line establishes**

1. `options(3)`/`options(6)` with real values — the design system's shared `Select` replaced the raw
   `<select>` on both filter rows and still offers exactly the states the API accepts.
2. `EDIT modal inputs=["","ba-label","ba-account_name"]` — **no `ba-bank_name`, no
   `ba-account_number`.** The identity fields are absent as _inputs_, which is the § 4 guard holding
   in a real browser rather than in a source scan. (The `""` entry is the page's own search box,
   which carries no `id`.)
3. `MODAL select #4` offers one account and the placeholder — the fixture's single active account.
   A deactivated account would be excluded, since the server's `exists` rule is
   `whereNull('deactivated_at')`.

### 6.3 Authoring a draft, and the arithmetic

The fixture seeds no fee schedules, so the list is legitimately empty. I authored one through the UI
to get a row with a total on screen.

Two lines entered: **Tuition `250000.50`** and **Books `12000`**.

```
AFTER CREATE — KPI cards : ["Drafts = 1","With the ED = 0","Active = 0"]
AFTER CREATE — counter   : "Showing 1 of 1"
AFTER CREATE — rows(1) : ["DRIVE JSS 1 — First Term | 2026/2027 — First Term | JSS 1 | Draft | 2 | ₦262,000.50 | "]
```

**Arithmetic:** `250000.50 + 12000.00 = 262000.50`, rendered `₦262,000.50`. **Nothing in the page
computed it** — the inputs went out through `nairaToMinor` as minor units, `FeeScheduleResource`
summed them in PHP, and the screen passed the returned `Money` to `formatNaira`. The "Lines" column
reads `2`, which is `items.length` — a row count, not a sum.

The KPI card moved `Drafts = 0 → 1` on the same render, from a row count over the reloaded list.

**Search, and the counter's honesty:**

```
SEARCH "zzz-no-match" — counter="Showing 0 of 1" rows=0 cards=["Drafts = 0","With the ED = 0","Active = 0"]
```

`0 of 1` — the first number is the filtered view, the second is the full loaded set. This is § 1.2's
argument visible on screen: both numbers come from one array, so the counter cannot lie. It is also
exactly what stops being true the day the endpoint paginates.

### 6.4 Seat 2 — `school-b@drive.test` (`accounts_officer`, school#2) — isolation by id

```
Seat 1 — maker@drive.test (school#1)
  props.terms        : ["1|2026/2027 — First Term"]
  props.class_levels : ["1|JSS 1","2|JSS 2"]
  MODAL account opts : ["|Choose an account…","a282b8eb-ac0b-49e7-abc0-94218e41b7aa|Drive account · Drive Bank"]
  bank-accounts row  : ["Drive accountDrive Bank | 9000000001 | — | Active | "]

Seat 2 — school-b@drive.test (school#2)
  props.terms        : ["2|2026/2027 — First Term"]
  props.class_levels : ["3|JSS 1","4|JSS 2"]
  MODAL account opts : ["|Choose an account…","a282b8eb-ad71-482f-9153-ea08d9c6622f|Drive account · Drive Bank"]
  bank-accounts row  : ["Drive accountDrive Bank | 9000000002 | — | Active | "]
```

Term `1` against `2`. Class levels `1,2` against `3,4`. Two different account uuids. Account numbers
`9000000001` against `9000000002`. **Every label string matches character for character** — which is
the whole reason the ids are what is being read.

The second half of the check: School A's newly authored `DRIVE JSS 1 — First Term` is **absent** from
School B's list — `[school-b] /finance/fee-schedules rows(0) : []`, `counter: "Showing 0 of 0"`,
all three KPI cards `0`.

### 6.5 The scroll proof — the part this brief exists for

Method: open a dropdown, measure `panel.top − trigger.top`, scroll, measure again. Under the **old**
behaviour the panel is `position: fixed` with coordinates taken once at open, so scrolling moves the
trigger and leaves the panel behind — the delta changes by the scroll amount. Under the **new**
behaviour the delta is invariant.

**Consumer 3 — `/finance` (`admin/finance/index.tsx`, untouched by this branch):**

```
before={"triggerTop":333,"panelTop":371,"deltaTop":38}
after ={"triggerTop":227,"panelTop":265,"deltaTop":38}   scrolledBy=106  deltaTopMoved=0
```

**Consumer 4 — `/students` (`admin/students/index.tsx`, untouched by this branch):**

```
before={"triggerTop":207,"panelTop":245,"deltaTop":38}
after ={"triggerTop":127,"panelTop":165,"deltaTop":38}   scrolledBy=80   deltaTopMoved=0
```

**The fee-schedule modal's scrollable body (the case that motivated the fix):**

```
modal body overflow: {"scrollHeight":1611,"clientHeight":630,"canScroll":true}
before={"triggerTop":1641,"panelTop":1679,"deltaTop":38}
after ={"triggerTop":1391,"panelTop":1429,"deltaTop":38}  scrolledBy=250  deltaTopMoved=0
```

In every case the trigger moved by the full scroll amount and the panel moved with it, `deltaTop`
pinned at 38px. Under the old behaviour `panelTop` would have stayed at 371 / 245 / 1679 and
`deltaTop` would have become 144 / 118 / 288.

The `overflow` line matters: **a first run measured `scrolledBy: 0` and proved nothing**, because two
fee lines fit inside `max-h-[70vh]` and a container that cannot scroll cannot detach anything. Eight
extra lines were added to force genuine overflow before the measurement was taken.

Screenshots show the panel **open**, before and after the scroll —
`docs/handoff/drives/2026-08-15-untouched-consumers/`.

### 6.6 The new error state, driven

Forced by aborting the list request at the network layer — no code change and no fixture change.

```
bank-accounts: {"showsCouldNotLoad":true,"showsRetry":true,"showsEmptyCopy":false}
fee-schedules: {"showsCouldNotLoad":true,"showsRetry":true,"showsEmptyCopy":false}
```

`showsEmptyCopy:false` is the assertion that matters: the failure path renders "Could not load …"
with a Retry button and **does not** render "No … to show". Before this branch both produced the
empty state. Screenshots in `docs/handoff/drives/2026-08-15-error-states/`, light and dark.

### 6.7 Both themes

**Nine** light/dark pairs, counted from the files rather than estimated — an earlier version of this
line claimed twelve. Three fee-schedules captures are single-theme by design (the modal scroll proof,
the search empty state, and School B's modal), so the directory holds 11 files but only 4 pairs:

```
2026-08-15-bank-accounts       light=3 dark=3  total=6   singles=0
2026-08-15-fee-schedules       light=4 dark=4  total=11  singles=3
2026-08-15-error-states        light=2 dark=2  total=4   singles=0
2026-08-15-untouched-consumers light=0 dark=0  total=4   singles=4   (scroll proof, one theme)
                                                          PAIRS = 9
```

Dark renders correctly across those nine: canvas,
hero/stat cards, table chrome, status pills, tone tiles, form controls and the toast all pick up
their `dark:` counterparts, with no white-on-white.

**One caveat, and it is a finding rather than a caveat — see § 8.2.** Dark mode was set by adding
`.dark` to `<html>` directly, because **the application's own appearance toggle is inert on this
build**. That is what the stylesheet keys on (`@custom-variant dark (&:is(.dark *))`), so the
screenshots prove the branch's `dark:` pairs are correct — they do not prove a user can reach them.

### 6.8 Console, every page

| Page / seat                                            | Console + failed responses                                                                                                                             |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `/finance/bank-accounts`, maker                        | clean                                                                                                                                                  |
| `/finance/fee-schedules`, maker (incl. modal + create) | clean                                                                                                                                                  |
| `/finance/bank-accounts`, school-b                     | clean                                                                                                                                                  |
| `/finance/fee-schedules`, school-b (incl. modal)       | clean                                                                                                                                                  |
| `/finance`, maker                                      | clean                                                                                                                                                  |
| Both error-state pages                                 | clean apart from the deliberately aborted request                                                                                                      |
| Post-login, every finance seat                         | `http 403: GET /dashboard`                                                                                                                             |
| `/students`, super@drive.test                          | `http 403: GET /api/notifications/unread-count`, `http 403: GET /api/students/resources`, `pageerror: AxiosError: Request failed with status code 403` |

The `/dashboard` 403 is the **pre-existing** bounce the skill documents; I recorded the URL rather
than assuming the known story. The `/students` 403s are pre-existing and on a page this branch does
not touch — see § 8.3.

**No page touched by this branch produced a single console error.**

### 6.9 Screenshots

```
docs/handoff/drives/2026-08-15-bank-accounts/       6 files (list + edit modal, both seats, both themes)
docs/handoff/drives/2026-08-15-fee-schedules/      11 files (empty, modal, created draft, search, isolation)
docs/handoff/drives/2026-08-15-error-states/        4 files (both screens, both themes)
docs/handoff/drives/2026-08-15-untouched-consumers/ 4 files (dropdown open, before/after scroll)
docs/handoff/drives/2026-08-15-drive-log.txt        the raw drive log
```

---

## 7. What was NOT driven

- **`teachers/index.tsx` and `teacher-assignments/index.tsx`** — consumers 5 and 6 of
  `base-dropdown`. The brief asked for at least two of the four untouched consumers and two were
  driven. The fix is in shared code and the invariant proven on consumers 3 and 4 is not
  page-specific, but these two were **not** opened and I am not claiming them from inference.
- **The fee-schedule lifecycle past `draft`.** Submit-for-approval, the ED's approve/reject,
  `active`, re-price/supersede and retire were not exercised, so the **`With the ED`, `Active`,
  `Superseded` and `Retired` status pills were never rendered against real data** — only `Draft`
  was. Their tone mapping is asserted by code reading alone. Reaching `active` needs the ED seat and
  a second sign-in; it is reachable on this fixture and was cut for time, not blocked.
- **The "Mixed currencies" total.** Requires a schedule whose items disagree on currency, which
  **this form cannot author** (it writes NGN only). Never rendered.
- **Bank-account deactivate / reactivate.** The row actions were rendered and named but not clicked,
  so the emerald↔slate pill transition was not observed live.
- **The 422 paths.** Inline amount validation, the slot-collision `message`, and the per-row error
  bag were not triggered on this run; the restyled error containers are unproven against real
  server responses.
- **`super_admin` and `void-checker` on the redesigned screens.** Only `accounts_officer` seats were
  used, plus `super@drive.test` on `/students` for the scroll proof.
- **Mobile/responsive widths.** Everything was driven at 1440×900. The responsive classes (§ 19) are
  unverified by eye.

---

## 8. Findings

The drive produced three. **The first was a defect in this branch and has since been fixed and
re-driven — see § 11.** The other two are pre-existing, on code this branch does not own, and were
filed as tickets rather than fixed.

### 8.1 The KPI cards render `0` when the load FAILED — FIXED in § 11

_As originally observed, kept for the record:_ the table correctly said "Could not load bank
accounts" with a Retry button, while the three cards above it read **"Bank accounts 0 / Active 0 /
Deactivated 0"**.

Cause: the cards received `loading={loading && accounts.length === 0}`. Once the fetch fails,
`loading` is `false` and `accounts` is `[]`, so the skeleton stops and a **hard zero** is rendered —
a number presented as fact when the truth is that we do not know. This is § 1.1's defect (empty and
failed looking alike) surviving in the one region of the page the error state had not been extended
to, which is a fair description of how partial fixes fail.

**Fixed in the round documented in § 11**, along with the same lie one element over in the
"Showing X of Y" counter, which the original write-up missed.

### 8.2 The application's dark-mode toggle is inert (pre-existing, not this branch)

**Filed as [`dark-mode-is-unreachable-for-every-user.md`](../tickets/dark-mode-is-unreachable-for-every-user.md).**

`resources/js/hooks/use-appearance.tsx:40-42`:

```ts
const isDarkMode = (appearance: Appearance): boolean => {
    return false;
};
```

It ignores its argument and returns a hard `false`, so `applyTheme` always removes `.dark` and sets
`colorScheme: 'light'`. Selecting "Dark" stores the preference in `localStorage` and a cookie and
**changes nothing on screen**, on any page in the application.

Untouched by this branch and unrelated to it. It is reported here because it changes what § 6.7's
screenshots mean: this branch's `dark:` pairs are correct and will be right the moment the toggle
works, but **no user can currently reach them.**

### 8.3 `/students` fires two 403s and renders two empty filters (pre-existing, not this branch)

As `super@drive.test`: `GET /api/students/resources` → 403, with an uncaught
`AxiosError: Request failed with status code 403` reaching the page. The consequence is visible in
the drive log — the class-level and arm filters render with **one option each**:

```
PAGE select #0 current="" options(1): ["|All class levels"]
PAGE select #1 current="" options(1): ["|All arms"]
```

Two selects offering only their placeholder. `GET /api/notifications/unread-count` also 403s for
this seat. Both are on a page this branch does not modify; noted because the drive was there and saw
it. The scroll proof in § 6.5 is unaffected — the panel renders and is measurable regardless of how
many options it holds.

**Filed as [`students-index-403s-render-two-placeholder-only-selects.md`](../tickets/students-index-403s-render-two-placeholder-only-selects.md)**,
where tracing the routes corrected this write-up's implicit reading: **both 403s are the `tenant`
middleware refusing a `super_admin` who has not selected a school**, which is isolation working as
designed (ADR 0036 — bypass is authorization, never isolation), not a permission defect. The defect
is purely that the page proceeds as though the request succeeded — a `.then()` with no `.catch()` at
`students/index.tsx:115`. The ticket also records that this was only ever observed on that one seat
state, which is the least representative seat on the platform.

---

## 9. Gates

> **Rewritten 2026-08-15 after cold review.** The previous version of this table listed four runs,
> two of them against `c4c19da` and `1679072`. **Neither sha exists on this branch.** Both were
> `git commit --amend`-ed away — confirmed in the reflog, which shows `c4c19da → 7ae1c10 (amend)`
> and `1679072 → d7fb290 (amend)` — so they survive only in one local object store and are
> unreachable from any ref in any clone.
>
> **The rule being applied: a gate run against a commit nobody else can check out is not evidence.**
> A reader cannot re-run it, cannot diff it, and cannot tell whether the tree it passed on resembles
> the tree they have. Such a run may be true and is still worthless as a record, so it does not
> belong in a table headed "Gates". Runs are recorded here **only** against shas reachable from the
> branch head.

| Run | Commit                                    | Result                 |
| --- | ----------------------------------------- | ---------------------- |
| 1   | `119c820` — the redesign                  | **PASS 15/15**, exit 0 |
| 2   | `d7fb290` — the KPI/counter fix + tickets | **PASS 15/15**, exit 0 |
| 3   | **HEAD** — the cold-review round          | **PASS 15/15**, exit 0 |

**Run 3 names `HEAD` rather than a sha, and that is deliberate rather than lazy.** A sha cannot be
written into the commit it names: stamping it requires an amend, the amend produces a different sha,
and the stamp is false again — the regress has no fixed point. The three ways out are all worse than
this one (a follow-up commit whose only content is the sha; a sha in the message, which the amend
also changes; or the stamp naming the pre-amend commit, which is exactly the unverifiable citation
this section was rewritten to remove). `HEAD` is checkable by the reader with `git rev-parse HEAD`
and stays true across any later amend, which is more than the shas in rows 1 and 2 can say.

For the record while it is current: run 3 was performed on `3e04b0f`, and re-performed on the amended
head after this paragraph was added. Both green. If `git rev-parse HEAD` disagrees with whatever a
reader finds here, believe the reader's `HEAD` and re-run — the gate takes about seven minutes.

**No red on any run, including the ones now struck from the record.** The runs against the
amended-away shas were also green; they are simply not citable.

Two runs were made against staged-but-uncommitted trees during the earlier rounds and are likewise
not listed, for a related reason: `bin/lint-changed.sh` diffs against the merge-base and therefore
**only ever sees committed work** (the known gap, ticketed in
[`lint-changed-cannot-see-uncommitted-work.md`](../tickets/lint-changed-cannot-see-uncommitted-work.md)).
A gate run over a tree the gate could not fully see is not a result for that tree.

**And even run 3 does not lint the two documents.** `lint-changed.sh:46` selects Prettier's file set
as `resources/*.{ts,tsx,js,jsx,vue,css,json}` — **`.md` is not in it**, and neither are the PNGs. So
the report and the ticket were formatted by hand (`prettier --write`, then `--check` clean) and the
gate's silence about them is not evidence. Stated because "PASS 15/15" would otherwise read as a
claim about every file in the commit, and it is not one.

The `4 changed file(s)` in step 3 is stable across all three runs for the same reason: it counts the
four `resources/**.tsx` files, of which `base-dropdown.tsx` is then skipped internally by both tools
(see below).

### Which steps actually read this branch's files

Of the 15 steps, this branch is `resources/js`-only, so the live ones are:

| Step                              | What it is                                                                                                               | Reads this branch?                                                             |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| 3                                 | lint changed files (Pint / Prettier / ESLint)                                                                            | **yes** — Prettier + ESLint on the changed files; Pint reported no changed PHP |
| 4                                 | types (tsc ratchet vs baseline)                                                                                          | **yes** — 0 errors in the touched files; ratchet not moved                     |
| 5                                 | frontend build (vite)                                                                                                    | **yes** — the step that catches what the tsc ratchet structurally cannot       |
| 9                                 | money lint (UI money rules)                                                                                              | **yes** — the rule § 3 is written to satisfy                                   |
| 1, 2, 6, 7, 8, 10, 11, 12, 13, 14 | dependency integrity, wayfinder, authz, boundary, grants, runtime-zero, identifier-generation, sql-clock, arch, Larastan | no PHP changed; they pass as before                                            |
| 15                                | tests + failure ratchet                                                                                                  | yes in the sense that § 4's source-scanning guards live in the suite           |

`resources/js/components/ui/base-dropdown.tsx` is in `.prettierignore` (`resources/js/components/ui/*`)
and is ESLint-ignored, so its formatting was deliberately left in the file's existing house style
rather than reflowed — reformatting an ignored file would have produced exactly the unrelated-noise
diff `CLAUDE.md` warns about.

### Targeted tests

`59/59` across `BankAccountTest`, `FeeScheduleTest`, `FeeSchedulesScreenTest` and
`EditFeeScheduleDraftTest` — including the LAYER 3 arm of § 4, red then green.

---

## 10. Files

```
docs/handoff/reports/feat-ui-bank-accounts-fee-schedules-redesign.md  (this file)
docs/handoff/tickets/fee-schedule-index-unpaginated.md                (+ dependency section; corrected § 12.4)
docs/handoff/tickets/dark-mode-is-unreachable-for-every-user.md       (new — § 8.2; corrected § 12.4)
docs/handoff/tickets/students-index-403s-render-two-placeholder-only-selects.md  (new — § 8.3; corrected § 12.4)
docs/handoff/tickets/base-dropdown-is-not-keyboard-operable.md        (new — § 12.2 / § 12.5)
docs/handoff/tickets/base-dropdown-repositioning-is-unmeasured-and-unclamped.md  (new — § 12.5)
docs/handoff/tickets/a-malformed-200-renders-the-empty-state-not-the-error-state.md  (new — § 12.5)
docs/handoff/drives/2026-08-15-*/                                     (drive artefacts)
resources/js/components/finance/status-pill.tsx                       (new)
resources/js/components/ui/base-dropdown.tsx                          (scroll tracking, id, data-value; ARIA removed § 12.2)
resources/js/pages/admin/finance/bank-accounts.tsx
resources/js/pages/admin/finance/fee-schedules.tsx                    (+ reload() — § 12.3)
```

---

## 11. Round 3 — fixing § 8.1, and two tickets

### 11.1 What a card shows when the load failed, and why

**An em dash.** Both screens, one condition per card:
`value={error ? '—' : String(count)}`.

The three candidates and why this one:

- **A dash** — chosen. It is the repo's existing "no value" convention: the tables on these very
  screens already render `account_name ?? '—'` and `term_label ?? '—'`, so the glyph already means
  "there is no value here" to anyone who has used the app. It cannot be parsed as a quantity, which
  is the brief's actual requirement.
- **A skeleton** — rejected. `animate-pulse` means "still arriving". On a failed load it would pulse
  indefinitely **beside a Retry button that exists precisely because nothing more is arriving** — two
  parts of the same card row contradicting each other. It also makes a hung request and a failed one
  look identical, which is a new instance of the defect being fixed, not a fix for it.
- **Suppressing the cards** — rejected. The label is the part carrying the information on an error
  path: "Deactivated —" says _which_ figure is unavailable, and an absent card says nothing at all.
  It would also collapse the layout under the hero, so the page would visibly restructure itself
  between the failed and successful renders of the same screen.

**A real zero still renders as `0`.** That distinction is the whole point and it is proved below: on
the healthy path `Deactivated` reads `0` and `With the ED` reads `0`, because those are counts the
page actually has. `—` is reserved for _unknown_.

### 11.2 Scope extended by one element, deliberately

The brief said one condition per card. **I also suppressed the "Showing X of Y" counter on the error
path**, on both screens.

It is the same defect the cards had: both numbers come from an array left empty by a dead fetch, so
it rendered **"Showing 0 of 0"** — a sentence that asserts the school has nothing, immediately above
a row saying the data could not be retrieved. I found it by looking at the § 11.4 screenshot rather
than by reasoning about the diff.

Suppressed rather than dashed: "Showing — of —" is a sentence with no content, and the error row
directly below already says what happened. There is no honest number, so there is no counter.

Flagged as a scope extension so it can be reverted independently if the call goes the other way —
it is two `{!error && (…)}` wrappers and nothing else depends on them.

### 11.3 Two tickets, not fixes

- **[`dark-mode-is-unreachable-for-every-user.md`](../tickets/dark-mode-is-unreachable-for-every-user.md)**
  — `isDarkMode` returns a constant `false`, both call sites (`:49`, `:97`) are therefore constant,
  and `applyTheme` is the only writer of the `dark` class. Traced: `:49` drives
  `classList.toggle('dark', …)` + `colorScheme` and is reached from all three theme-changing paths
  (load, user click, OS change); `:97` drives `resolvedAppearance`, read only by the 2FA QR
  inversion. Not fixed here, per the brief — appearance is app-wide and needs its own drive.
  **The ticket states, about this report's own screenshots, that they were produced by setting the
  class directly and are not evidence a user can reach dark mode.**
- **[`students-index-403s-render-two-placeholder-only-selects.md`](../tickets/students-index-403s-render-two-placeholder-only-selects.md)**
  — both 403s recorded with their routes, the two placeholder-only selects recorded by value, the
  resemblance to the U1 empty-select defect drawn out, and the `/dashboard` 403 cross-referenced.

    **Two corrections came out of writing it.** First, the 403s are not a permission defect: both
    routes carry `tenant`, and a `super_admin` bypasses `permission:` but never isolation, so a seat
    with no school selected is refused correctly. The defect is the unhandled `.then()`. Second, the
    `/dashboard` 403 **has no ticket to cross-reference** — the `finance-drive` skill calls it "filed
    as a ticket", but it exists only as a `ticket`-tagged bullet inside
    `docs/handoff/reports/feat-discount-policies-page.md:456-460`, and no file for it exists under
    `docs/handoff/tickets/`. The ticket links the report bullet and says so.

### 11.4 Bite-proof — re-driven, not reasoned

The fix is invisible to every gate this project has (no JS test runner renders a component and reads
a computed value), so the drive is the proof. One state re-driven on both screens, list fetch aborted
at the network layer:

```
=== bank-accounts — list fetch aborted ===
  CARD "Bank accounts" value="—" sub="Every account this school has added"
  CARD "Active" value="—" sub="Offered as a destination for fees and payments"
  CARD "Deactivated" value="—" sub="Withdrawn from choice, still nameable on old payments"
  table state      : {"couldNotLoad":true,"retry":true,"emptyCopy":false,"counter":false}
  numeric cards    : 0  (PASS — no card renders a count)

=== fee-schedules — list fetch aborted ===
  CARD "Drafts" value="—" sub="In this view — priced, not yet submitted"
  CARD "With the ED" value="—" sub="In this view — frozen until the decision"
  CARD "Active" value="—" sub="In this view — currently billable"
  table state      : {"couldNotLoad":true,"retry":true,"emptyCopy":false,"counter":false}
  numeric cards    : 0  (PASS — no card renders a count)
```

`numeric cards` is the assertion made mechanically rather than by eye: every card's rendered value is
matched against `/^-?\d[\d,]*$/` and the count of matches must be zero. `counter:false` is the § 11.2
suppression.

**And the other direction, because a fix that dashes unconditionally would also pass the above.** The
healthy path, same build, same seat:

```
/finance/bank-accounts  ["Bank accounts=1","Active=1","Deactivated=0"]
/finance/fee-schedules  ["Drafts=1","With the ED=0","Active=0"]
```

Real counts, **including two genuine zeros** — which is precisely the case that must not be confused
with the dash. Without this half, "all six cards show —" would be consistent with having broken the
cards entirely.

Screenshots, light and dark, both screens:
`docs/handoff/drives/2026-08-15-error-states/` (overwritten with the post-fix state; the pre-fix
images they replace are described in § 8.1). Raw log:
`docs/handoff/drives/2026-08-15-error-state-redrive-log.txt`.

### 11.5 What this round did NOT verify

- **The `Retry` button was rendered but not clicked.** The recovery path — error state → Retry →
  successful load → cards return to real counts — is unproven end to end. The two halves of § 11.4
  were driven as separate page loads, not as a transition.
- **Only the aborted-request failure mode.** A 500, a 403 or a timeout all set the same `error`
  boolean, but only a network abort was exercised.
- **Nothing in the two tickets was fixed or driven**, by instruction. The `/students` ticket's own
  scope caveat stands: one seat state observed, three plausible states unchecked.
- **The unchanged parts of the branch were not re-driven this round.** § 6's evidence stands from the
  earlier run; only the failed-load state was re-driven, plus the healthy KPI row as its control.

---

## 12. Round 4 — cold review

A cold reviewer read the branch and the three documents against the repository. Every finding below
was verified against the source before acting; all were correct, and two of them corrected claims in
documents this branch had itself written to correct other claims.

### 12.1 The gate record named commits that do not exist

Verified in the reflog: `c4c19da` and `1679072` were each replaced by `git commit --amend`
(`c4c19da → 7ae1c10`, `1679072 → d7fb290`). They are reachable only from one local reflog. § 9 is
rewritten to list runs **only against shas on the branch**, and states the rule: a gate run against a
commit nobody else can check out cannot be re-run, diffed or trusted by a reader, so it is not
evidence regardless of its result. `d7fb290` now has its own run.

### 12.2 ARIA without keyboard support — removed

The branch had added `aria-haspopup="listbox"`, `role="listbox"`, `role="option"` and
`aria-selected` to `base-dropdown`. The component has **no** keyboard handling: no arrow keys, no
Enter, no Escape, no focus management, no `aria-activedescendant`; `handleSelect` fires from
`onClick` alone. Announcing a listbox promises an interaction model the component cannot honour,
which is worse than the plain button it replaced.

All four removed. **`aria-expanded` kept** — valid on a disclosure button and backed by real
behaviour — and **every `data-*` attribute kept**, since those are what a drive script reads and are
the reason they were added at all. The real fix is filed as
[`base-dropdown-is-not-keyboard-operable.md`](../tickets/base-dropdown-is-not-keyboard-operable.md),
which names all six consumers and says explicitly that the ARIA goes back only as part of the
keyboard work, never on its own.

### 12.3 Retry did not restore the fee-schedule modal — fixed and bite-proved

`loadAccounts` is a separate effect with `[]` deps, so it runs once per mount. When both fetches fail
together — server down, session expired — a Retry wired only to `load()` restored the table and left
`accounts` at `[]` until a full page reload, leaving the "Paid into" select holding nothing but its
placeholder on a screen that now looked healthy. **That is this branch's own `/students` ticket,
inside this branch's own feature.**

Both Refresh and Retry now call a `reload()` that runs both fetches. Refresh was included
deliberately — leaving it as the half-refresh reproduces the identical defect one button over, which
is the § 11.2 lesson.

**Bite-proof — Retry was clicked, for the first time in any round:**

```
=== STATE 1: both fetches failed ===
  error row visible : true
  retry button      : true
  MODAL "Paid into" while broken (1): ["|Choose an account…"]

=== STATE 2: clicked Retry (real button click: true) ===
  error row gone    : true
  KPI cards         : ["Drafts=1","With the ED=0","Active=0"]
  counter           : "Showing 1 of 1"
  rows              : 1
  MODAL "Paid into" after Retry (2): ["|Choose an account…","a282b8eb-ac0b-49e7-abc0-94218e41b7aa|Drive account · Drive Bank"]
  real (non-placeholder) options: 1  (PASS — accounts restored without a page reload)
  navigations since load: 1 (1 = never reloaded)
```

The defect is reproduced in state 1 (placeholder only) and closed in state 2 (the real account uuid
back in the select). `navigations since load: 1` is the load-bearing line — it proves the recovery
came from the Retry click and not from a page reload. Screenshots:
`docs/handoff/drives/2026-08-15-retry-recovery/`.

This also closes the first item of § 11.5's "did NOT verify" list.

### 12.4 Three documents corrected where a reader would have acted

**[`dark-mode-is-unreachable-for-every-user.md`](../tickets/dark-mode-is-unreachable-for-every-user.md)**
— four errors, all confirmed:

- "applyTheme is the only writer of the `dark` class" was **false**. There are three writers:
  `app.blade.php:2` (`@class`, server-side), `app.blade.php:9-21` (an inline script before React),
  and `applyTheme`.
- The **PHP half was missing entirely**. `HandleAppearance.php:19` is
  `View::share('appearance', 'light')` — hard-coded, cookie never read — and that is what makes both
  Blade writers inert. `bootstrap/app.php:48` still exempts the cookie from encryption, so it remains
  readable by the middleware that no longer reads it.
- The ticket called it "not a stub". `git log` reaches **`83447b3` "feat: remove dark mode"**
  (2026-05-25), which deleted the PHP cookie read and the JS predicate **in the same commit**. It is
  the deliberate removal of a shipped feature, which makes restoring it a decision to revisit rather
  than a defect to repair.
- "What a fix has to cover" named no PHP change. Corrected: restoring `isDarkMode` alone leaves the
  server-rendered **first paint** light on every load (a white flash before the runtime class lands)
  and leaves `system` unable to resolve at first paint at all, because the inline script's guard
  needs `$appearance === 'system'`.

**[`students-index-403s-render-two-placeholder-only-selects.md`](../tickets/students-index-403s-render-two-placeholder-only-selects.md)**
— the conclusion held, the named layer did not. `SetSchoolContext:51` is
`if (! $isSuperAdmin && ! $activeSchoolId)`, so a super admin without a school **falls through the
middleware**. The 403s come from `ActiveSchool::getOrFail()` → `abort_unless(…, 403)` at
`ActiveSchool.php:70`, reached from `StudentController.php:196` and
`NotificationFeedController.php:120`. And the notifications route group carries **no `permission:`
middleware at all**, so nothing in that stack could have produced its 403. Still isolation, not
permission; a controller call, not a middleware.

**[`fee-schedule-index-unpaginated.md`](../tickets/fee-schedule-index-unpaginated.md)** — three
corrections:

- **Four of six line citations were stale** — both counters and both card-render sets, i.e. exactly
  the regions § 11's fix edited after the table was written. All re-derived; cross-referenced to
  [`stale-path-line-citations.md`](../tickets/stale-path-line-citations.md) as the third recorded
  occurrence of the class. A note now says the symbol names are the durable part and the numbers are
  not.
- **It named three dependents; there are five.** Both additions write a **money destination** rather
  than misreporting a count, which makes them worse than the counter:
  `fee-schedules.tsx:327-345`'s preservation branch silently blanks an operator's existing
  destination when the deactivated account it points at is not on page 1; and
  `record-payment-modal.tsx:118-131` auto-selects when `active.length === 1`, which **a page of one**
  satisfies — its own comment says guessing "would assert a destination nobody picked — on a row that
  is append-only".
- **"Nothing throws" now carries its condition.** That holds only if pagination keeps the rows at the
  same key. An envelope shape makes `visible.filter` / `accounts.filter` throw before anything paints
  — a crash, not a quiet lie. The fix list now recommends the envelope for exactly that reason.

### 12.5 Three more tickets — recorded, none fixed

- [`base-dropdown-is-not-keyboard-operable.md`](../tickets/base-dropdown-is-not-keyboard-operable.md)
  — § 12.2's real fix, six consumers named.
- [`base-dropdown-repositioning-is-unmeasured-and-unclamped.md`](../tickets/base-dropdown-repositioning-is-unmeasured-and-unclamped.md)
  — three things the scroll fix was **not** measured against: a per-row select inside
  `overflow-x-auto` (`teachers/index.tsx:382`, the one structural mounting no driven page exercises,
  and `updateDropdownPosition` sets `left` as well as `top`, so horizontal tracking is now live);
  no viewport clamp, so a panel now follows its trigger off the top of the screen where measure-once
  left it in place — **a behaviour this branch introduced**; and `reposition` calling
  `setDropdownStyle` on every scroll event, unthrottled and without `requestAnimationFrame`.
- [`a-malformed-200-renders-the-empty-state-not-the-error-state.md`](../tickets/a-malformed-200-renders-the-empty-state-not-the-error-state.md)
  — `data ?? []` guards only null/undefined, so a 200 with the wrong shape yields `[]` with
  `error` false and renders the **empty** state, complete with real zeros in the KPI cards. It is the
  empty-versus-broken confusion this branch exists to remove, on the one path an aborted-request
  drive cannot reach: aborting makes axios reject, a malformed 200 never does. Four sites listed,
  including `discount-policies.tsx`.

### 12.6 § 6.7's pair count was wrong

It claimed twelve light/dark pairs. Counted from the files: **nine**. Three fee-schedules captures
are single-theme by design. § 6.7 now carries the per-directory counts and the arithmetic; the
dark-mode ticket's "twelve" was corrected to nine too.

### 12.7 What this round did NOT verify

- **Nothing in the five tickets was fixed or driven**, by instruction — including the three ARIA
  attributes' replacement, which is the substantive half of § 12.2.
- **The base-dropdown ARIA removal was not re-driven.** It is an attribute deletion with no
  behavioural surface, verified by build and lint only; no screen reader was used at any point in any
  round, before or after.
- **Bank-accounts' Refresh/Retry was not re-driven.** That screen has a single fetch, so the § 12.3
  defect cannot occur there — but the assertion is by reading, not by clicking.
- **The three unmeasured repositioning behaviours remain unmeasured**, in both directions. They are
  the ticket's content, not its resolution.
- Everything still open from § 7 and § 11.5 stays open: consumers 5 and 6 undriven, the fee-schedule
  lifecycle past `draft` never exercised (so four of five status pills have never rendered against
  real data), "Mixed currencies" unauthorable, the 422 paths untriggered, and no mobile widths.

### 12.8 A near-miss worth recording: the glob sweep, third time, different tool

While formatting this round's documents I ran `npx prettier --write docs/handoff/tickets/*.md`. It
reformatted **22 pre-existing tickets this branch has nothing to do with** — reflowed prose, retabbed
tables — and staged them. `git status` showed 36 changed files where the real change was 14.

`CLAUDE.md` documents this exact class for **Pint** and says it has bitten the project three times
(#223, 71 files on `feat/finance-bank-accounts`, and once more on this very branch from a
substitution that expanded to nothing). The rule there is "pass explicit files and guard against an
empty list". I applied that rule to Pint this whole branch and then walked into the identical trap
with a different formatter, because the rule was filed in my head under _Pint_ rather than under
_any formatter with a glob_.

Caught by the instruction CLAUDE.md pairs with it — **"read `git diff --stat` against your own model
of the change before pushing"** — which is the only reason it is a near-miss and not a fourth
occurrence. 22 files reverted with `git restore --staged --worktree`; the staged set is now the 14
files listed in § 10.

The generalisation, since that is the reusable part: **the hazard is the glob, not the tool.** Any
formatter invoked over a directory rewrites files you did not choose, and no gate objects — correct
formatting is still correct. Name the files.
