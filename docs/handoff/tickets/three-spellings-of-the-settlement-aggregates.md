# The settlement aggregates are spelled in three places, and the arithmetic over them in three more

**Raised by** the cold review of `feat/u7-invoice-list-and-detail`, against a claim that branch made
about its own work. `InvoiceReadModel::settlementSums()` called itself "THE TWO SETTLEMENT
AGGREGATES, IN ONE PLACE" and the implementation report repeated it. **That was false**, and the
claim has been corrected in both places on that branch. This ticket is what remains.

## The sites, re-derived rather than carried

`grep -n "withSum('allocations as allocated_minor'" -r app/` and
`grep -n "getAttribute('allocated_minor')" -r app/`, at `945aedc` plus that branch's fix commit:

**The `withSum` pair — three spellings.**

| | |
| --- | --- |
| `app/Finance/Services/InvoiceReadModel.php:88-90` | `settlementSums()`, used by `forStudent()` and `withSettlement()` |
| `app/Finance/Services/AllocationProposal.php:188-189` | `openInvoices()` |
| `app/Finance/Console/DriveFinanceStates.php:497-498` | `openInvoiceCount()`, the drive fixture's count-table reader |

**`total − Σ(allocations) − Σ(approved credit notes)` — three spellings.**

| | |
| --- | --- |
| `app/Finance/Services/InvoiceSettlement.php:57-63` | floored at zero for display; the wire's `outstanding` |
| `app/Finance/Services/AllocationProposal.php:206-211` | `outstandingKobo()`, floored, with a docblock on why |
| `app/Finance/Console/DriveFinanceStates.php:499-503` | inline in a `filter()`, unfloored |

**The review named five sites and the third arithmetic spelling makes six.** Re-derived here rather
than copied from the review: `DriveFinanceStates` spells BOTH expressions, and it is a fixture
counter rather than a surface a bursar reads — which is a fair reason to weigh it less and not a
reason to leave it off the list.

**They agree today.** The reviewer compared them character by character; so did this ticket.

## The sharp part

`AllocationProposal::openInvoices()`' own docblock, at `app/Finance/Services/AllocationProposal.php:175-178`:

> The two withSum aggregates mirror `InvoiceReadModel::forStudent` exactly, because
> `InvoiceSettlement` is what reads them and this screen's outstanding must be the same number the
> statement shows for the same invoice. **A second spelling of that sum is how two surfaces come to
> disagree about what a student owes.**

It is the second spelling. It says so, correctly, about itself — and it could not have avoided being
one: `settlementSums()` is **private**, so `AllocationProposal` cannot call it even if someone tried.
The comment names the hazard and the visibility guarantees it.

## Why this is a ticket and not part of that branch

Converging them is its own change with its own arms, and none of the three obvious moves is free:

- **Make `settlementSums()` public** and call it from `AllocationProposal`. That widens a primitive
  for a consumer that already has working code and its own tests — front-loading, and it puts a
  private read-model detail on `App\Finance\Services`' public surface without a caller asking for it.
- **Extract a shared scope or trait on `Invoice`.** Plausible, and it changes what three tested
  surfaces query. `AllocationProposal` is merged code (U10) with arms of its own; U7's branch is not
  the place to re-prove them.
- **Leave the `withSum` pair and converge only the arithmetic.** The two floored spellings already
  agree and each carries a docblock explaining its flooring choice; the unfloored one is a fixture
  counter. This is the smallest move and it is not obviously the right one.

Whoever takes it should decide between those with the arms in front of them, and should pin the
result — an arm that fails when the spellings diverge is the only thing that makes "they agree" a
fact rather than an observation. **Nothing enforces the agreement today.** That is the ticket.

## Not to be done under this ticket

Changing `AllocationProposal`'s behaviour, or its visibility, to make the convergence tidier. The
three spellings agree; this is a duplication finding, not a defect report.
