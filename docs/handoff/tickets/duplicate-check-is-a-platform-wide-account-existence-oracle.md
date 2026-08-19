# `duplicate-check` answers "does this account exist" platform-wide, in a GET query string

**Raised by:** the second cold review of `fix/guardian-create-duplicates` (finding 4).

## What it does

`GuardianController::duplicateCheck` has two halves and they are scoped differently.

The **guardian** half is correctly pinned: `GuardianMatcher::candidatesInSchool` drops
global scopes and pins `guardians.school_id` by hand, and the isolation arm in
`tests/Feature/Guardian/GuardianCreateDeduplicationTest.php` proves it **by id** —
the same phone returns one uuid for school#1 and an empty set for school#2.

The **account** half is not, and cannot be. It asks whether an arbitrary submitted
address belongs to any `users` row, and `User` is explicitly exempt from `SchoolScope`
(`app/Models/Scopes/SchoolScope.php`, *"Users are identities, not tenant data"*). So any
holder of `academic_setup.manage` in any ONE school can probe arbitrary addresses and
learn whether an account exists **platform-wide**, plus `has_access_to_school` for their
own school.

## Why this is a ticket and not a fix

**It changes the cost, not the class.** `createGuardianWithUser`'s own refusal already
discloses exactly the same fact to exactly the same caller — *"This email address
already belongs to an account that is not a guardian in this school"* — so the
information was reachable before this endpoint existed, one create submission at a time.
The endpoint makes it cheap and scriptable rather than newly possible.

The endpoint is also deliberately no more disclosive than its sibling: `lookup` on the
same gate returns a **full, unmasked** `GuardianResource` and searches ALL schools.
`duplicate-check` returns masked contacts and a boolean. Tightening this one while
`lookup` stands is theatre.

## The secondary half: it travels in a query string

`use-guardian-lookup.ts` sends the address as GET query parameters, so every probed
email address lands in web-server access logs, proxy logs and browser history — a
place none of the request bodies elsewhere in this flow reach. That is true of `lookup`
too, and predates this change.

## What closing it looks like

Decide it as one question across both endpoints, not this one alone:

1. Whether "an account exists with this address" is disclosable to an
   `academic_setup.manage` holder at all, given the refusal message already says so.
2. If not: gate both endpoints on something narrower than the route group, and drop the
   `account` half of the response to a bare "not available here" rather than a boolean.
3. Move the identifier out of the query string on both — POST, or a body on a
   `GET`-shaped route — so it stops being logged.
4. Whatever is decided, it needs an arm. There is currently no test asserting either
   endpoint's specific ability, only that the route carries auth middleware.
