# Step 5 — the guardian pay screen: copy and states, for review BEFORE components

**Status:** draft, for review. **Nothing is built from this yet** — parent-facing money copy is
cheaper to argue about as prose than as components, and this document exists so the argument happens
here. Base: `staging` @ `4138af7a` (gated green, 20/20).

Read `docs/handoff/reports/step-5-...` for what was built once this is signed.

---

## 0 · Vocabulary, enforced rather than requested

**The physical column name never appears under `resources/`, including in a comment.** Say
**withheld** or **not yet released**. The screen consumes an API shape, not a table; naming the
column in this layer is the first step toward someone deciding to filter on it.

This is not a style note — `tests/Arch/ReleasedToPayersHasOneDefinitionTest` reds on any occurrence
under `resources/`, comments included, and the arm asserts its own denominator so it cannot pass by
scanning nothing.

---

## 1 · Three corrections to the brief, before the copy

### 1.1 · "Withheld renders identically to nothing outstanding" is a SERVER property, not a copy discipline

The brief asks the copy not to hint that something exists but is hidden. **The client cannot hint at
it, because it is never told.** `GuardianFinanceController::wards` withholds unreleased bills on
**both** keys — `invoices` (via `outstandingForStudent`) and `account` (via
`guardianAccountPositionForStudent`) — and its docblock states why the two must answer about the same
set: a response withholding one and not the other would print a positive balance directly above
"Nothing outstanding".

So this state needs **no special copy and no special branch**. A ward whose only invoices are
withheld arrives byte-identical to a ward with nothing outstanding. That is the strongest available
form of the requirement, and the screen should not contain a comment explaining it — a comment is
where the next person learns the distinction exists.

**What the client must NOT do:** introduce any client-side filtering that could reintroduce the
distinction, or any empty-state copy conditioned on a count the server did not send.

### 1.2 · There is no reachable dead band, so no copy is owed for one

The brief asks for copy when "the amount lands in the dead band". **Measured: no input reaches it.**
`GatewayFeeCalculator::grossFor` builds one candidate per regime and takes the smallest that
recovers; the class states that every bill maps to a consistent regime under Paystack's current
schedule, and the no-candidate path **throws** rather than branching, deliberately — *"a branch no
input can reach is untestable code that reads as coverage."*

Writing parent-facing copy for it would be the same defect one layer up: a message for a state the
system cannot produce, which reads as coverage and is never exercised.

**What IS real is the rounding, and it is one kobo.** `grossFor` rounds up so the school receives
**at least** the chosen amount; the residual is at most a kobo and banks as account credit under an
existing rule. It needs no sentence of its own — the confirmation's three numbers already show it,
because the amount the parent chose is the amount credited.

### 1.3 · The fee is an ESTIMATE, so the copy states only what is exact and bounds the rest

`feeOn()` is our measured model of Paystack's schedule, rounded up on purpose. The fee actually taken
is the provider's reported `fees` at settlement, and `SettleGatewayTransaction` records that one
*"MEASURED, NEVER RECOMPUTED"* precisely so a disagreement survives to be found. So `Fee: ₦1,600`
stated flatly is a claim wider than the artifact.

**And attributing it to Paystack is WORSE, not better** — the first draft of this section proposed
"Payment provider's charge", which sources our estimate to a third party and makes it sound *more*
authoritative while being no more verified. An overclaim with a false citation attached.

The resolution is to say only what is exact and let the rest be a bound. Two facts carry it:

- **The total is exact** — it is the number we send to Paystack.
- **The amount settled is a guaranteed MINIMUM**, because `grossFor()` rounds up and `feeOn()`
  over-estimates, so the error direction is in the payer's favour and lands as their credit.

Nothing in the wording of §4 can be contradicted by the settlement.

### 1.4 · The alternative that was rejected, named so nobody reopens it as an oversight

**Quoting an exact fee from Paystack before the parent commits.** It would make the middle figure a
fact rather than a bound. It is rejected here as a **different product decision, not a better
implementation of this one**: it requires a call to the provider before the confirmation renders,
which puts a network dependency and a failure mode in front of a screen that currently has neither,
and it changes what the parent is agreeing to. If the business wants it, it is its own step.

---

## 2 · States, with copy

Every heading below is a state of ONE ward's card. The screen renders a card per ward, in the order
the API returns them.

### 2.1 · No wards

> **No students linked to your account**
> If you expect to see a child here, contact the school office.

*Not an error.* `GuardianService::forUserInActiveSchool` returning null is a legitimate state — the
parent may hold wards in a school they have not switched to. The copy must not say "something went
wrong".

### 2.2 · Ward with nothing outstanding

> **Ada Obi**
> Nothing outstanding.

No amount field, no pay button. **This is also what a ward with only withheld invoices renders** —
see §1.1. There is no second branch and there must not be one.

### 2.3 · Ward with outstanding invoices

Per invoice: `display_number`, `kind` ("Term bill" / "Supplementary"), `academic_context`, `total`,
and **`outstanding` as the prominent figure** — it is what the parent is being asked for.

> **Pay this invoice**
> Amount: [ ₦ ______ ]  · outstanding ₦48,500

### 2.4 · Ward with available credit

Dev 1's approved wording, verbatim and unedited:

> ₦X credit on this student's account. This will be applied automatically to the next invoice issued.
> To apply it to an outstanding invoice, contact the bursar.

**It appears alongside outstanding invoices, not instead of them.** The two are independent facts
about the same student, and the credit sentence must not suppress or reduce the displayed
`outstanding` — that figure comes from the server and is already correct.

---

## 3 · Amount entry

Partial, full and over are **all permitted**. The field is pre-filled with `outstanding` and is
editable in both directions.

**Below the minimum:**

> The smallest payment we can take online is ₦1,000. For a smaller amount, please pay at the school
> office.

**This check is a CONVENIENCE, not a control.** The server refuses independently
(`GatewayMinimumPayment`, which throws when the value is unset as well as when it is too low). The
client check exists so a parent is not sent to Paystack to be refused; it is tested separately and
labelled as such, because a control that lives only in the client is theatre.

---

## 4 · Confirmation — before anything is committed

Under parent-bears the parent pays **more than they typed**, and an unexplained card statement is a
chargeback. So nothing is initiated until this has been shown and acknowledged:

> You'll be charged **₦101,600**. This settles **₦100,000** on invoice BSS-000214. The difference is
> the payment processing charge — if it comes to less than we've estimated, the remainder is credited
> to your account.

**One exact number, one guaranteed minimum, and the error direction stated plainly.** The total is
exact because we send it; the settled amount is a floor because our estimate rounds up in the payer's
favour. No figure here can be contradicted by what the provider actually charges — which is the test
this wording had to pass and the reason it does not name a fee at all (§1.3).

The invoice is named, not just the amount: a parent paying the newest bill because the oldest is
disputed must be able to see which one this is.

## 5 · Failure copy

### 5.1 · Initiation refused

> We could not start this payment. Nothing has been charged.

Then the specific reason where it is the parent's to act on (below the minimum; this invoice is no
longer payable). **Never the server's internal reason** — an unconfigured settlement account or an
unset minimum is the school's problem and must not be narrated to the payer.

### 5.2 · Pending — the one that matters most

> **Payment received — we are confirming it with the provider**
> Your payment has gone through at Paystack. It can take a few minutes to appear against this
> invoice. You do not need to pay again, and you do not need to do anything else.

**It must not imply failure.** Since #370 the webhook re-verifies on every redelivery, so a payment
whose confirmation has not yet arrived recovers on **Paystack's own retry schedule**, with no human
involved. The copy should say what happens next, which is: nothing, on their part.

The sentence *"You do not need to pay again"* is the load-bearing one — a parent who pays twice
creates real money the school must return.

### 5.3 · Provider unreachable during confirmation

Renders **identically to 5.2**. From the payer's side "we have not been told yet" and "we could not
ask yet" are the same situation and the same instruction. Distinguishing them tells the parent about
our infrastructure, not about their money.

---

## 6 · Authorisation — no new ability

`parent_portal.access` plus `GuardianPaymentAuthorisation::mayPay()`. **Adding an ability would break
the grants-convergence lint on merge**, and it would be wrong anyway: which invoice *this* parent may
pay is a relationship question, and a permission cannot express "this parent, that child".

---

## 7 · The known negatives, at the API boundary — MEASURED, and three of four already exist

A hidden button is not a guarantee. The brief asked for four arms planted, observed red, then fixed
green. **Checked against the repo before writing any of them, and the honest answer is that most are
already there** — so what is owed is not four new tests but a bite-proof of the existing ones (a test
name is a claim; only a planted regression is evidence) and the one arm that is genuinely missing.

| # | Arm | Where it already lives |
|---|---|---|
| N1 | A withheld invoice is absent from the payload | `ParentPortalFinanceReadTest` — three arms: it withholds; it excludes it from the balance too; and the shown balance **equals** the sum of the shown invoices |
| N2 | Another guardian's ward, same school | `ParentPortalFinanceReadTest:210` (read, with a **mirror control** so it cannot pass on "return the first student") and `GatewayInitiateTest:214` (write) |
| N3 | Another **school** | `GatewayInitiateTest:245`, already separate from N2 and labelled *"isolation, which is a different guarantee"* — **but there is NO read-side equivalent.** See below. |
| N4 | Below the minimum, refused server-side | `GatewayInitiateTest:159`, with the accepting side (at the minimum exactly) in the same arm |

**A SIXTH THING, FOUND BY WRITING THE MISSING ARM RATHER THAN BY READING FOR IT.** The read
endpoint resolves the active school from `users.school_id`, not the session — measured by flipping
that column between two schools and watching the response follow it while the requested school did
nothing. No existing arm can see this, because all 24 set `users.school_id` to the school they then
read, so the session path and the legacy fallback always agree. Whether a real browser request
behaves the same way is **not** established and needs a drive.
`docs/handoff/tickets/the-parent-finance-read-resolves-school-from-users-school-id.md`. **This is
the pay screen's multi-school case**, so it is a step-5 dependency and not background.

**The one real gap: read-side isolation.** `ParentPortalFinanceReadTest` covers *"a guardian-role
user with no guardian row in this school"* → empty list. That is a different case from **a guardian
who legitimately holds wards in two schools, browsing one of them**, which must return only the
active school's ward. Nothing covers it, and it is the case the pay screen sits directly on top of.

It is written as its own arm, never folded into N2 — combined, one passes when *either* mechanism is
removed, which is exactly how the ward arm in `feat/gateway-initiate` survived its own mutation
(a cross-school guardian meant `SchoolScope` refused and `mayPay` was never reached).

**N5, the client-side minimum check, is a convenience and is labelled one** — it exists so a parent
is not sent to Paystack to be refused. It is tested separately from N4 and its test says so, because
a control that lives only in the client is theatre.

## 8 · Open, with owners

- **The wording of §2.1, §3, §5.1 and §5.2 is mine and unapproved.** Only §2.4 is Dev 1's approved
  text. Everything else in this document is a proposal.
- **§1.3 — is "Payment provider's charge" acceptable**, given it is our estimate? If the answer is
  that the fee must be exact before the parent commits, that is a different design (quote from
  Paystack first) and a bigger change than this step.
- **Nothing writes the release stamp on new invoices** —
  `docs/handoff/tickets/nothing-releases-a-new-invoice-to-parents.md`. This screen renders correctly
  and shows every ward "nothing outstanding" until that is answered. **It is the gating item for the
  6th, not this step.**
