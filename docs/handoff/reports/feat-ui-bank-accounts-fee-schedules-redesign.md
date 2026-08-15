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
3. **`data-value` on the trigger and on every option**, plus `role="listbox"`/`role="option"`/
   `aria-selected`. See § 5.

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

I added `data-value` (and `role="listbox"`/`role="option"`/`aria-selected`) to restore it. Both are
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

Light and dark captured for every screen and modal (12 pairs). Dark renders correctly: canvas,
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

## 8. Findings — reported, not fixed

Per the skill: a drive observes; the decision is the project lead's. All three are left in place.

### 8.1 The KPI cards render `0` when the load FAILED — a defect in this branch

Visible in `docs/handoff/drives/2026-08-15-error-states/bank-accounts-01-error-light.png`: the table
correctly says "Could not load bank accounts" with a Retry button, while the three cards above it
read **"Bank accounts 0 / Active 0 / Deactivated 0"**.

Cause: the cards receive `loading={loading && accounts.length === 0}`. Once the fetch fails,
`loading` is `false` and `accounts` is `[]`, so the skeleton stops and a **hard zero** is rendered —
a number presented as fact when the truth is that we do not know. This is § 1.1's defect (empty and
failed looking alike) surviving in the one region of the page I did not extend the error state to,
which is a fair description of how partial fixes fail.

The fix is one condition per card — pass `loading={loading || error}`, or render `—` on `error` —
in both files. **Not applied**, per the observe-don't-fix rule; flagging it as the finding I would
action first.

### 8.2 The application's dark-mode toggle is inert (pre-existing, not this branch)

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

---

## 9. Gates

Three runs, all green, all reported.

| Run | Tree                                                                           | Result                 |
| --- | ------------------------------------------------------------------------------ | ---------------------- |
| 1   | commit `119c820`, ticket edit uncommitted                                      | **PASS 15/15**, exit 0 |
| 2   | + `data-value`/a11y on `base-dropdown`, + both documents, staged not committed | **PASS 15/15**, exit 0 |
| 3   | the committed tree (`c4c19da`)                                                 | **PASS 15/15**, exit 0 |

**No red on any run.** Run 3 exists because of a limitation worth stating rather than glossing:
`bin/lint-changed.sh` diffs against the merge-base and therefore **only ever sees committed work**
(the known gap, ticketed in
[`lint-changed-cannot-see-uncommitted-work.md`](../tickets/lint-changed-cannot-see-uncommitted-work.md)).
Runs 1 and 2 linted the four `.tsx` from `119c820`; only run 3 is a gate result for the tree that is
actually being handed over.

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
docs/handoff/tickets/fee-schedule-index-unpaginated.md                (+ dependency section)
docs/handoff/drives/2026-08-15-*/                                     (drive artefacts)
resources/js/components/finance/status-pill.tsx                       (new)
resources/js/components/ui/base-dropdown.tsx                          (scroll tracking, id, data-value, roles)
resources/js/pages/admin/finance/bank-accounts.tsx
resources/js/pages/admin/finance/fee-schedules.tsx
```
