# TICKET — the fail-closed allowlist is OPT-IN, so every new School-owned finance model ships fail-OPEN

**Status:** open, not implemented. Raised by the cold review of `feat/u6-bulk-run-screen` (U6 commit
4), which measured it on the two models that branch added — and then measured the same shape on three
endpoints that have nothing to do with that branch.

**Not fixed there.** U6 commit 4 added its own two models to the allowlist, because they were its own
defect. The three endpoints below are pre-existing and closing them is a read-isolation change with a
platform-wide blast radius; a screen commit is the wrong place for it.

---

## The mechanism

`App\Models\Scopes\SchoolScope::apply()` (`SchoolScope.php:49-64`) has three branches:

```php
if ($schoolId) {                                   // scoped — the normal path
    …->where($model->getTable().'.school_id', $schoolId);
} elseif (auth()->check() && $this->shouldFailClosed($model)) {
    throw new MissingSchoolContextException(…);    // refused
}
                                                   // …and otherwise: NOTHING.
```

The third branch is silent and unscoped. Which branch a model takes is decided by
`shouldFailClosed()`, which consults `config('rbac.fail_closed_models')` — **an allowlist**. A model
that nobody remembered to add takes the third branch.

**That is the whole finding: the default is fail-OPEN and the protection is a memory aid.** Every
School-owned finance model added from here on is unscoped-for-a-contextless-principal until somebody
edits a config file, and nothing fails when they don't. The allowlist is deliberately per-model — the
rollout was staged and reversible (roadmap Rollout Flags, Risk #14) — but the staging is finished for
the transactional set, and what remains is a default that points the wrong way.

## Measured, on U6's own models, before they were added

Signed in as `super@drive.test` — a platform super admin with **no school selected**. `super_admin`
bypasses AUTHORIZATION and never ISOLATION (ADR 0036); the bypass is what carries this seat past
`permission:finance.invoice.generate`, which is a maker ability and so is not excluded by ADR 0040.

```
GET /api/v1/finance/bulk-invoice-runs          → 200, 8 runs spanning BOTH drive schools
GET /api/v1/finance/bulk-invoice-runs/{uuid}   → 200 for either school's run
```

Reproduced in the suite by planting the two entries away
(`tests/Feature/Finance/BulkInvoiceRunScreenTest.php`, the `REFUSES a super admin with no school
selected` arm):

```
without the entries → 200  {"data":[{"uuid":"a28bc463-…","status":"completed","term_id":2,…}]}
with the entries    → 409  {"message":"No active school selected."}
```

`BulkInvoiceRun` and `BulkInvoiceRunRow` are now on the list and this half is closed.

## What is still open — three endpoints, same shape

The reviewer measured the same behaviour on three finance screens' data endpoints. All three are
pre-existing and none was touched by U6:

| Endpoint | Model | Ability the route asks for |
| --- | --- | --- |
| `GET /api/v1/finance/bank-accounts` | `App\Finance\Models\BankAccount` | `finance.bank-account.manage` |
| `GET /api/v1/finance/fee-schedules` | `App\Finance\Models\FeeSchedule` (+ `FeeItem` through the items relation) | the group's `finance.access` |
| `GET /api/v1/finance/discount-policies` | `App\Finance\Models\DiscountPolicy` | the group's `finance.access` |

For a contextless super admin each answers **200 with every School's rows** rather than refusing.
None of the four models is on the allowlist; that is the only reason.

**Two of these are already ticketed at the model level and this ticket does not replace them** — it
is the endpoint-level statement of the same cause, plus the generalisation:

- [`fee-item-and-discount-policy-not-fail-closed.md`](fee-item-and-discount-policy-not-fail-closed.md)
  — `FeeItem` and `DiscountPolicy`, raised for the WRITE path (uuid → id resolution).
- [`discount-policy-catalog-reads-across-schools-for-a-contextless-super-admin.md`](discount-policy-catalog-reads-across-schools-for-a-contextless-super-admin.md)
  — the READ half for `DiscountPolicy`, measured.
- [`fail-closed-catalog-batch.md`](fail-closed-catalog-batch.md) — `FeeSchedule` and `FeeItem`,
  recording the deliberate deferral of the catalog batch.

**`BankAccount` appears in none of them.** It postdates all three tickets, and it is the clearest
instance of this ticket's actual subject: a model that shipped after the deferral was written down and
therefore was never part of anybody's batch.

## What would close it

Two candidates, and the second is the one worth arguing for:

1. **Add the four models** — `BankAccount`, `FeeSchedule`, `FeeItem`, `DiscountPolicy`. Closes the
   measured cases and leaves the default pointing the wrong way for the fifth model nobody has written
   yet. This is what the previous three tickets each proposed for their own model.
2. **Invert the default for `App\Finance\Models\*`** — every model in the Finance namespace carrying
   `BelongsToSchool` fails closed, with an explicit DENYLIST for any that must not. Then a new finance
   model is protected on the day it is created, and turning that off is a written decision rather than
   an omission. There is already an arch rule asserting every Finance model uses `BelongsToSchool`
   (`tests/Arch/ArchitectureBoundaryTest.php:58-60`), so the set is already enumerable and already
   enforced.

Option 2 subsumes option 1 and removes the failure mode this ticket is named after. Option 1 is what a
hurry produces.

**Whichever is chosen, the change needs a rendering pass and not only a suite pass.** A model moving
from silent-unscoped to throwing turns some contextless read into a 409 somewhere, and the screens
that make those reads have not been driven for it. `FeeSchedule` in particular is read by the invoice
modal's prefill, the fee-schedules screen and the approvals queue.

## What this ticket is NOT

Not a claim that `super_admin` should not reach these endpoints. They should — with a school selected.
ADR 0036's split is the whole point: the bypass answers *may you*, `SchoolScope` answers *whose data*,
and only the second one is failing here.
