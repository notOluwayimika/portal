# The queue link and the queue gate disagree

**Raised by:** cold review of `feat/u13-u14-decided-approvals` at `ffbae04` (2026-08-22). Noticed
while checking whether that branch's own defect — a "Pending approvals" button offered to a seat the
route refuses — existed anywhere else. It does not. The finance index has the **opposite** disagreement,
and it is pre-existing: this branch neither introduced nor touched it.

## What is true today

`/finance/approvals` derives its gate from the approval-ability convention over the whole permission
catalog (`routes/web.php:410-418`):

```php
$financeCheckerAbilities = implode('|', array_values(array_filter(
    array_map(fn (Permission $case) => $case->value, Permission::cases()),
    fn (string $ability) => str_starts_with($ability, 'finance.')
        && ApprovalAbility::isExcludedFromSuperAdminBypass($ability),
)));

Route::get('/finance/approvals', …)->middleware('permission:'.$financeCheckerAbilities)
```

The link to it on the finance index is gated on a **hardcoded pair**
(`resources/js/pages/admin/finance/index.tsx:147-150`):

```tsx
<Can
    permissionAny={[
        'finance.credit-note.approve',
        'finance.invoice.void-request.approve',
    ]}
>
```

**Re-derive the sets before trusting the counts below** — they move whenever a permission is added:

```bash
php artisan tinker --execute="
\$d = array_values(array_filter(array_map(fn(\$c) => \$c->value, App\Enums\Permission::cases()),
  fn(\$a) => str_starts_with(\$a,'finance.') && App\Support\ApprovalAbility::isExcludedFromSuperAdminBypass(\$a)));
sort(\$d); print_r(\$d);"
```

Derived 2026-08-22 — **ten** abilities admit the route; **two** of them show the link:

| Ability | Route admits | Index links |
| --- | --- | --- |
| `finance.credit-note.approve` | ✓ | ✓ |
| `finance.invoice.void-request.approve` | ✓ | ✓ |
| `finance.credit-note.reject` | ✓ | — |
| `finance.invoice.void-request.reject` | ✓ | — |
| `finance.fee-schedule.change.approve` | ✓ | — |
| `finance.fee-schedule.change.reject` | ✓ | — |
| `finance.discount-policy.change.approve` | ✓ | — |
| `finance.discount-policy.change.reject` | ✓ | — |
| `finance.opening-balance.approve` | ✓ | — |
| `finance.opening-balance.reject` | ✓ | — |

## The failure mode is LINK-ABSENT, not present-and-403 — and the direction matters

**The hardcoded pair is a strict subset of the derived set.** Verified rather than assumed: every
member of the pair appears in the derived ten, and eight derived abilities are outside the pair.

That containment fixes the direction of the disagreement. A seat holding *any* listed ability is
admitted by the route, so the link can never be shown to somebody the route refuses. What happens
instead is the mirror: **a seat holding only one of the eight unlinked abilities is admitted by the
route and offered no link from the finance hub.** A holder of `finance.fee-schedule.change.approve`
and nothing else can reach the queue by typing the URL and by no other means from this page.

**It cannot produce a present-and-403 for `super_admin` either**, which is the one case worth ruling
out explicitly because `super_admin` is where bypass reasoning usually goes wrong. ADR 0040 excludes
checker abilities from the `Gate::before` bypass and `EffectivePermissions` resolves through the Gate,
so a `super_admin`'s effective set holds **no** finance checker ability. `<Can permissionAny>` reads
that same effective set, so the link is hidden — and the route refuses them too. Absent and refused
agree.

**Not present-and-403 does not make it harmless.** It is the failure `FinanceNavCoverageTest` exists
for, one level in: that test asks whether a page is reachable from *any* menu, and `/finance/approvals`
is in the sidebar, so it passes. The sidebar item is itself derived
(`resources/js/components/app-sidebar.tsx:429-434`), which is what keeps those eight seats from being
locked out entirely — the index is the only surface that disagrees, and its disagreement is
invisible to every existing test.

## The sweep — no other affordance disagrees

Every link to the queue in the application:

```bash
grep -rn "'/finance/approvals'" resources/js --include=*.tsx --include=*.ts \
  | grep -v '^resources/js/actions/\|^resources/js/routes/'
```

| Site | Gate | Verdict |
| --- | --- | --- |
| `resources/js/components/app-sidebar.tsx:439` | derived predicate over the viewer's effective set | agrees |
| `resources/js/pages/admin/finance/decisions.tsx:199` | same derived predicate | agrees |
| `resources/js/pages/admin/finance/index.tsx:158` | hardcoded pair | **disagrees — this ticket** |
| `resources/js/pages/admin/finance/approvals.tsx:582` | breadcrumb on the queue itself | n/a — the seat is already on the page |
| `resources/js/hooks/use-notifications.ts:51` | deep link for `approval.requested`; gated by who RECEIVES the notification, not by an ability list | **not examined** — a different mechanism, and whether the recipient set matches the route's admitted set is an open question this ticket does not answer |

## The fix, when it is taken

Apply the predicate `app-sidebar.tsx` and `decisions.tsx` already apply — derive from the viewer's
own effective set rather than listing abilities:

```tsx
const { permissions } = usePermissions();
const isFinanceChecker = [...permissions].some(
    (ability) =>
        ability.startsWith('finance.') &&
        (ability.endsWith('.approve') || ability.endsWith('.reject')),
);
```

Three copies of that derivation is itself a smell; extracting it to one exported predicate is the
better shape and is a larger change than the fix.

**And pin it.** `FinanceNavCoverageTest` grew an arm for the decisions page's version of this
(`the decisions page offers the queue link only to a checker, on a DERIVED predicate`), which counts
`isFinanceChecker` and asserts the convention's shape. The same arm applied to `index.tsx` is the
enforcement; without it, the next copy drifts the same way and nothing says so.

## Why it was not fixed on `feat/u13-u14-decided-approvals`

Out of that branch's scope, and it is not the same defect: the branch's own was present-and-403 (a
control that fails when pressed), this one is link-absent (a control that never appears for a seat
that is entitled to it). Fixing it edits a file that branch does not otherwise touch.
