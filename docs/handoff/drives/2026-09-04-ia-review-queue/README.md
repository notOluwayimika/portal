# Drive — Internal Audit review queue, and the return control

**Screen:** `/internal-audit/review-queue` · **Date:** 2026-09-04 · **Branch:**
`feat/finance-ia-queue-return-control` · **Instance:** `APP_ENV=drive`, `portal_drive`, port 8001,
`localhost` (never `127.0.0.1` — `session.domain` is `localhost`).

Chrome driven with `puppeteer-core`, installed **outside the repository** in the session scratchpad.
`pnpm run build` first, then seed, then serve — in that order.

## 1. The fixture, and what had to be added before a browser could be opened

**No seat in the cast could open this screen.** The page and its API group are gated on
`finance.invoice.approve`; `internal_auditor` is the only role holding it, and none of the eight
drive seats held that role. Per SKILL.md the fixture is a precondition, not a finding, so this
commit adds two seats and two columns.

- **`auditor@drive.test`** — `internal_auditor`, School A. The only seat that can open the queue.
- **`approve-only@drive.test`** — a dedicated `drive_ia_approve_only` role holding
  `finance.invoice.approve` and **not** `finance.invoice.reject`. **This seat does not exist in
  production and that is exactly why the fixture must build it:** the queue is reached through
  `approve`, the return route adds `reject` on top, and `internal_auditor` holds both — so no
  seeded role can distinguish the route's own gate from its group's. Same shape and justification
  as the existing `drive_void_checker`.

Table 1 gained **`Awaiting review`** and **`Returned to Finance`**. Neither is derivable from
`Open invoices`: that column asks whether money is owed, these ask whether a human has signed the
bill off, and the two are independent. Split rather than summed, because the screen renders them as
two cards whose sum is the omission detector.

```
+--------------+ … +----------------------+---------------+-----------------+---------------------+
| School       | … | Decided credit notes | Decided voids | Awaiting review | Returned to Finance |
+--------------+ … +----------------------+---------------+-----------------+---------------------+
| A (school#1) | … | 2                    | 1             | 10              | 1                   |
| B (school#2) | … | 0                    | 0             | 1               | 0                   |
+--------------+ … +----------------------+---------------+-----------------+---------------------+
```

Both non-zero for School A, so neither card is a bare `0` that a wrongly-wired card would
imitate. The returned bill is staged through **`ReturnInvoice`**, never a write — the pairing
triggers would refuse a piecemeal insert as errno **1644**.

*(The full three tables, unabridged, are in the implementation report; the columns above are the
ones this drive added and reads.)*

## 2. Seat 1 — `auditor@drive.test`

Login landed directly on `/internal-audit/review-queue` — `SchoolAwareLoginResponse` routing this
seat to its queue rather than to `/dashboard`, which it cannot open.

```
  CARD Awaiting sign-off  | 10 | Unreleased and not out with Finance
  CARD Returned to Finance | 1 | Sent back for correction, still unreleased
  rows=10   Return buttons=10
```

**The cards match the fixture table exactly — 10 and 1.** Read off the rendered DOM, not from the
API.

The dialog, read out of the DOM:

```
  title            "Return bill 2 to Finance"
  intro            "This bill stays unreleased and invisible to the payer. Finance corrects it and raises it again."
  helper           "Say what Finance must correct. Recorded in the activity log and readable by other staff — describe the bill, not the payer."
  maxlength        "255"
  submit (empty)   disabled: true
  submit label     "Return to Finance"
  submit on "   "  disabled: true
  submit on a real reason  enabled: true
```

`maxlength` is **255**, mirroring `ReturnInvoice::REASON_MAX` through the constant the arch test
pins.

## 3. A real return, and the counts

```
  BEFORE  Awaiting sign-off 10 || Returned to Finance 1 || rows=10
  AFTER   Awaiting sign-off  9 || Returned to Finance 2 || rows= 9   dialogClosed=true
```

**The row leaves the page, one card falls, the other rises, and the sum is unchanged** — 11 before,
11 after. The endpoint's own payload, read in the same run:

```
  counts={"awaiting_review":9,"returned_to_finance":2,"unreleased_total":11}   pagination.total=9
```

`pagination.total` is **9**, equal to `awaiting_review` and not to `unreleased_total` — the filtered
subset, exactly as the controller docblock now says. The invariant
`unreleased_total == awaiting_review + returned_to_finance` holds live, not only in the test.

## 4. A refused return — the sentence lands in the error slot and stays

The dialog was opened, the same bill was returned **out from under it** by a second request (a real
race, and the only honest way to make the server refuse a submit the form itself considers valid),
then the dialog was submitted.

```
  dialogStillOpen  true
  errorText        "Invoice a2ab128e-… was already returned to Finance by user#7 on 2026-09-04 and is awaiting correction."
  errorClasses     "rounded-md bg-destructive/10 p-2 text-sm text-destructive"
  toastPresent     false
  after 6s         still on screen, unchanged
```

§9's banner classes exactly; the action's sentence **verbatim**, naming the returner and the date;
**no toast**; and still readable six seconds later. Screenshot:
`auditor-07-refusal-in-error-slot.png`.

## 5. Seat 2 — `approve-only@drive.test`

Driven in a **separate browser context**. (The first attempt shared the auditor's cookie jar, so
`/login` redirected away and the script reported "no email input" — a harness artifact, corrected,
recorded because it looks exactly like a broken login page.)

```
  path=/internal-audit/review-queue   rows=8   Return buttons=0
  headers=["","INVOICE","KIND","TOTAL","RAISED","ACTIONS"]
  API: pending=200   returnAttempt=403
```

**The page opens and the control is absent — and the route refuses it anyway.** Both halves: the UI
gate (`usePermissions().can('finance.invoice.reject')`) hides the button, and the server returns
**403** to a seat that constructs the request by hand. A control that renders and cannot be used is
the defect class §26 records; this seat proves it does not render.

**One nit, observed and not fixed:** the `ACTIONS` header still renders for this seat, over a column
that is empty for every row.

## 6. Dark mode — a measured instance of an already-ticketed defect

**`.dark` was set DIRECTLY on `<html>`.** No user can reach dark mode
(`dark-mode-is-unreachable-for-every-user.md`), so this is not a claim to have seen it as a user
would. Computed styles inside the dialog:

```
  modalSurface  bg rgb(255,255,255)          color oklch(0.95 0.01 260)   ← white surface, near-white inherited text
  modalTitle    color oklch(0.21 0.034 264)                                ← explicit text-gray-900, readable
  label         color oklch(0.95 0.01 260)                                 ← NEAR-WHITE ON WHITE. INVISIBLE.
  textarea      bg oklch(0.208 0.042 265)    color oklch(0.968 …)          ← a dark box on a white card
  errorSlot     bg oklab(… / 0.1)            color oklch(0.5 0.2 27)       ← destructive tokens, readable
```

Screenshot `auditor-08-dark-dialog.png`: the page behind the modal flips correctly; **the modal does
not**, and the field label all but disappears.

**This is not new and it is not this screen's.** §26 already records that `Modal.tsx`,
`ConfirmDialog.tsx`, `EmptyState.tsx` and `Toast.tsx` carry **zero** `dark:` variants — re-measured
here, `grep -c 'dark:'` still returns 0 — so **every form in this application** is authored inside a
surface that never flips, and every `<Label>` inside one is invisible in dark mode for the same
reason. Ticketed as `ui-chrome-components-have-no-dark-variants.md`. **A drive observes; it does not
fix**, and patching one label would make this one form correct in a mode no user can enter while
every sibling stayed broken.

## 7. The console, every page, the whole run

Only vite HMR notices, the React DevTools banner, and the two resource errors from the **deliberate
probes** in §4 and §5:

```
  [auditor error] Failed to load resource: … 422 (Unprocessable Content)     ← the refused return
  [seat2  error] Failed to load resource: … 403 (Forbidden)                  ← the refused seat
```

**No `pageerror`, no failed request, no undefined read.** The opening-balance drive found its second
defect here; this one found none.

## 8. One thing the drive changed

The character counter rendered as a bare **`210`** beside the helper sentence — the page's own "two
bare numbers is a bug report" rule at the level of one field. Labelled to `210 left`, and `N over`
past the cap because "-45 left" is not a sentence. Re-driven: `255 left` on an empty field,
`215 left` at 40 characters (`auditor-09-counter-labelled.png`).

## 9. What was NOT driven

- **School B isolation on this screen.** No School B seat holds `internal_auditor`, so the queue
  cannot be opened there at all. Isolation for this endpoint is covered by a server-side arm
  (another school's uuid answers `No such invoice in this School.`), not by eye.
- **A reason at the 255/256 boundary through the form.** `maxlength` stops typing at 255, so the
  over-cap path is unreachable from the keyboard; it is covered by the request's `max:` rule and by
  vitest.
- **Dark mode as a user would see it** — unreachable by construction, see §6.
