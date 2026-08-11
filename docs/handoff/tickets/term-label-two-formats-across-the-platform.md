# The platform names a term two ways, and they are not interchangeable

**Raised by:** the second cold review of U1 commit 1 (`feat/fee-schedules-data-surface`).

## What is true today

There are two formats for "term, named to a human", and nothing reconciles them.

**Format A — `Term::displayLabel()`**, `app/Models/Term.php`:

```php
trim(($this->academicSession->name ?? '').' — '.$this->name)   // "2026/2027 — First Term"
```

Read by three finance-adjacent surfaces, which U1 commit 1 converged onto it:

- `app/Finance/Http/Resources/FeeScheduleResource.php` — `term_label` on the fee-schedules list
- `app/Finance/Http/Resources/FeeScheduleChangeResource.php` — `target_term` on the approvals queue
- `routes/web.php` — the opening-balance operator screen's term select

**Format B — written out, three times**, and untouched by that commit:

```php
app/Http/Resources/TermResource.php:20   $this->name.' - '.$this->academicSession->name
app/Services/BroadsheetService.php:65    $term->name.' - '.$term->academicSession->name
app/Services/BroadsheetService.php:163   same
```

They differ in **word order** (term first vs session first) **and in separator** (ASCII hyphen with
spaces vs an em dash). So one term reads `2026/2027 — First Term` on the fee-schedules list and
`First Term - 2026/2027` on a broadsheet, and neither string can be substituted for the other by a
reader, a sort, or a test.

## Why this is a ticket and not a cleanup

Converging them **changes what renders on result screens and in exported broadsheets**. That is a
product decision — someone has to say which of the two orders the school wants to read, and whether
an exported file's header changing is acceptable — and it is not the sort of thing a commit that was
fixing a fee-schedules data surface gets to decide on the way past.

The narrower risk, and the reason this is filed at all rather than left implicit: `displayLabel()`'s
docblock originally claimed the label lived "in one place". It does not. A future author reading that
claim and pointing `TermResource::full_name` or a broadsheet header at the method would ship a silent
format change to result screens. The docblock has been narrowed to the three finance-adjacent screens
and now names the three Format B sites explicitly, pointing here — but a comment is not a mechanism,
and nothing fails a build if the fourth caller is added.

## What would close it

1. Decide which format the platform uses (product).
2. Point every site at `Term::displayLabel()`, or at two clearly-named methods if the broadsheet's
   order is deliberate and different.
3. An arm asserting the chosen format from every site that names a term, of the shape
   `tests/Feature/Finance/FeeScheduleTest.php`'s "one term is named the same string by all three
   screens that name it" already uses for the finance three — expected value built **literally**, not
   by calling the method under test.
