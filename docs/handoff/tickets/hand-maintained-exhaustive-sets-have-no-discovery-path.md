# Hand-maintained exhaustive sets have no discovery path

**Status:** open. **Two distinct sets bit on 30 August**, one across branches and one inside a
single commit. Neither was a defect in the test, and neither author could reasonably have known the
enumeration existed.

## The shape

Several places in this repository assert a **complete set** with a closed literal — `toBe([...])`
over every member — precisely so that adding a member is a deliberate act rather than a silent one.
That design is correct and this ticket does not propose changing it.

The problem is the other half: **an author who adds a member has no way to discover that an
enumeration of it exists somewhere else.** The set lives in one file; the assertion lives in
another; nothing at the site you edit tells you the second copy is there.

## The two instances, both measured

**`CheckConstraintsAsTriggersTest`, across two branches.** S11's manual-invoice migration added
`finance_manual_invoice_run_lines_amount_currency_shape`; #330 shipped a closed list of every CHECK
on a `finance_` table. Each branch's gate was green. The merge was red, and staging went down with
it. Neither branch could see the other.

**`FinanceFailClosedBatchTest`, inside one commit.** The manual-invoice selection commit added four
models to `config/rbac.php`'s `fail_closed_models` — correctly; a model left off falls to
`SchoolScope`'s silent-unscoped branch, which is how the bulk run once answered a super admin with
eight runs spanning two Schools. The commit did not update the arm that asserts the shipped default,
because nothing at the config told it to.

## Why the duplication is right, and this is the sentence to keep

A test that derived its expectation from the config would assert only that **the file equals
itself**, and every addition would land silently — which is the whole failure the closed list
exists to prevent. Typing it twice is what makes an addition deliberate.

**The duplication was never the problem. The missing thing is any way to discover the second copy.**

## What closes it

A pointer at the site the author edits, naming the file that asserts the set. One comment. It is
what `config/rbac.php` now carries, and it costs nothing:

> This list is asserted VERBATIM by `FinanceFailClosedBatchTest`'s default-batch arm. Adding a model
> here means adding it there, in the same order.

- [ ] Sweep for other closed-set assertions over `finance_`-adjacent lists and give each one a
      pointer at the site being enumerated. Candidates seen today: the CHECK-constraint list, the
      trigger-set list in `CheckConstraintsAsTriggersTest`, `approval-feeds.ts` and its coverage
      test, the route oracles.
- [ ] Do NOT add reverse pointers in the tests. They already explain themselves; the gap is
      one-directional.

**Not a lint.** A lint that could find these would need to know which literals are sets and which
are examples, and would be tuned against today's tree — the failure the citation-lint docblock
already warns about. A pointer written by the person who understands the set is worth more than a
rule that guesses.
