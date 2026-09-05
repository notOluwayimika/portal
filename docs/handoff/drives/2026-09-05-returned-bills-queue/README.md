# Drive — Finance's returned-bills queue (`/finance/returned-bills`)

**Change:** `feat/finance-returned-bills-queue` · **Date:** 2026-09-05 · **Fixture:** `portal_drive`,
`APP_ENV=drive`, port 8001, driven at `localhost` (never `127.0.0.1` — `session.domain`).

The acceptance suite proves the HTTP stack and is structurally blind to rendering. This drive exists
for what it cannot see: the ORDER on screen, the two numbers as an operator reads them, whether the
reason is legible without interaction, whether the empty state and the failed state look different,
and whether any internal identifier reaches the page.

## The fixture, from the command's own output

```
| School       | … | Awaiting review | Returned to Finance |
| A (school#1) | … | 8               | 3                   |
| B (school#2) | … | 1               | 0                   |

 Returned to Finance (/finance/returned-bills), oldest first: #3 returned 9 days ago · #2 returned 3 days ago · #1 returned today
```

School B's **0** is not an abort — it is the fixture for the empty state, and it is also the
isolation control: School A's three returns must not appear there.

**The three ages are the point.** With one returned bill, "oldest first" is satisfied by every
possible ordering and the age card reads the same whatever it is wired to. The oldest bill was
returned LAST, so it carries the highest `number` and the highest id — every ordering that is not
`returned_at ASC` puts a different row on top. Nine days is past the screen's stalled threshold and
zero is today, so both branches of the age card are reachable on one fixture.

## Seat 1 — `maker@drive.test` (accounts_officer, School A), both themes

Identical in light and dark.

```
  title      : Returned to Finance - Laravel
  h1         : Returned to Finance
  stat cards : ["Waiting to be corrected  3  Returned by Internal Audit, still unreleased",
                "Oldest has waited  9 days  Longer than a week — this queue is not being worked"]
  rows       : 3
    row 1: ["3", "Sam Settled",    "₦3,000.00", "27/08/2026", "Auditor Drive", "The tuition line is charged at last term's rate."]
    row 2: ["2", "Paula Part",     "₦3,000.00", "02/09/2026", "Auditor Drive", "The tuition line is charged at last term's rate."]
    row 3: ["1", "Ursula Unpaid",  "₦3,000.00", "05/09/2026", "Auditor Drive", "The tuition line is charged at last term's rate."]
  action controls inside the table: []
```

What each line establishes:

- **The order is `returned_at ASC` and nothing else.** 27/08 → 02/09 → 05/09 ascending, while the
  bill numbers run 3 → 2 → 1 DESCENDING. Number order, id order and insertion order all disagree
  with what is on screen, so no other sort produces this page.
- **Both numbers are there and they disagree with each other.** `3` waiting is a small, calm-looking
  count; `9 days` is the same queue saying it is not being worked. That is the whole argument for
  the second number, visible in one screenshot.
- **The stalled branch renders.** The sub-text changed to "Longer than a week — this queue is not
  being worked" and the card's tone went rose. A fixture whose oldest bill was two days old would
  have rendered only the calm branch.
- **The reason is readable without interaction.** Full sentence, in the cell, no ellipsis and no
  "show more" — measured as the complete string, not as a prefix.
- **No action controls at all**, not even a disabled one.

## Identifiers on the page — measured, not asserted

```
  uuids in VISIBLE TEXT : 0
  uuids inside the TABLE: 0
  uuids anywhere in the DOM: 6   — all of them the SIGNED-IN USER'S OWN uuid, in Inertia's
                                   shared-props <script>, which every page in the app carries
  the three invoice uuids present? false
  "user#" anywhere on the page: false
```

The six DOM occurrences were chased to their source rather than waved through: a DOM walk reported
them as a single text node inside a `<script>` element, and the value matches
`users.uuid` for `maker@drive.test`. None of `a2ac41e0-1384…`, `a2ac41e0-1b0e…` or
`a2ac41e0-27fc…` — the three returned bills — appears anywhere in the document.

## Seat 2 — `school-b@drive.test` (accounts_officer, School B) — isolation AND the empty state

```
  stat cards      : ["Waiting to be corrected  0  …", "Oldest has waited  —  How long the oldest bill has been waiting"]
  rows            : 0
  empty heading   : "Nothing has been returned"
  failed heading  : null
  School A's reason text present? false
```

Two separate facts in one screen. **Isolation:** School A's three bills and their reason text are
absent, and the request still succeeded — absent, not forbidden. **The empty state:** the count
reads `0` and the age reads `—`, because an empty queue has no oldest bill and `0 days` would claim
one arrived today.

## The failed state — it must NOT look like the empty one

The feed was aborted at the network layer and the page re-loaded.

```
  stat cards      : ["Waiting to be corrected  —  …", "Oldest has waited  —  …"]
  failed heading  : "Could not load the queue"
  empty heading   : null
```

**Both numbers render `—`, not `0`.** This is the state the `QueueView` union exists for: rendered as
an empty list with a confident `0`, the screen would tell Finance "nothing to correct" at the exact
moment the truth is "I could not ask". Compare it against School B's screenshot — the empty state is
emerald with a tick and no retry; the failed state is red with an alert and a Retry button.

## Seat 3 — `auditor@drive.test` (internal_auditor)

```
  GET /finance/returned-bills            -> HTTP 403
  /finance links in the auditor's sidebar -> []
```

The seat that CREATED these returns cannot open Finance's queue, and is not offered a menu item for
it. The two sides of one act are two seats, and both the route and the menu say so.

## Console

Four entries across the whole run, every one accounted for:

| entry                           | what it is                                                                                                |
| ------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `403 GET /dashboard` (maker)    | **pre-existing and ticketed** — every finance seat is bounced from `/dashboard`; the drive skill names it |
| `403 GET /dashboard` (school-b) | same                                                                                                      |
| `net::ERR_FAILED` (maker)       | the deliberate abort that produced the failed state above                                                 |
| `403` (auditor)                 | the deliberate refusal of `/finance/returned-bills`                                                       |

A separate response-level probe confirmed the only non-2xx on the maker's and School B's page loads
is that `/dashboard` bounce. **Nothing on this screen errored**, and `GET /invoices/returned` fired
exactly once per page load.

## Dark mode, and why the class is forced

`resources/js/hooks/use-appearance.tsx:40-41` is `const isDarkMode = () => false`, committed as
`83447b32 feat: remove dark mode`, so the appearance toggle cannot currently produce a dark render
anywhere in the application. The drive sets `document.documentElement.classList.add('dark')`
directly — the same DOM state the toggle would produce; only the toggle is gone. Recorded here
because a driver who does not know this spends a cycle debugging their harness.

## What was NOT driven

- **Pagination.** Three returned bills is one page at every offered size, so the pager renders but is
  not exercised. Its behaviour is covered server-side instead: arm `f3` asks for `?per_page=1&page=2`
  and asserts the age still describes the whole queue rather than the page.
- **A returner who is no longer a user.** `returnerLabel`'s "No longer a user" branch is asserted
  under vitest but cannot be staged here: `returned_by_user_id` is a lookup rather than an FK, and
  deleting the auditor mid-fixture would break every other state that names them.
- **Any correction verb**, because there is none. This commit is the read side only.

## Screenshots

| file                             | what it shows                                               |
| -------------------------------- | ----------------------------------------------------------- |
| `maker-light-returned-bills.png` | three rows oldest-first, both numbers, the stalled sub-text |
| `maker-dark-returned-bills.png`  | the same page in dark                                       |
| `school-b-light-empty.png`       | the empty state — `0` and `—`, emerald, no retry            |
| `school-b-dark-empty.png`        | the same in dark                                            |
| `maker-light-failed.png`         | the failed state — both numbers `—`, red, Retry             |
| `auditor-no-nav-item.png`        | the auditor's sidebar, with no Finance group                |
| `drive-log.txt`                  | the raw harness output these tables were read from          |
