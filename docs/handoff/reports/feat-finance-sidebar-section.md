# Implementation report — Finance in the sidebar

## Headline

**Done.** Finance has its own sidebar section, composed by permission; every finance page is now
reachable from a menu or named as deliberately not one; and a coverage gate makes the next
unreachable finance page a red build.

Branch `feat/finance-sidebar-section`, base `1c40ba2` (`origin/staging`, #224 merged).

Three finance screens shipped across four pull requests reachable only by typing the URL. The
defect was invisible to every existing check, because a route file, a controller test and a page
test can all be green while nothing links to the page.

## Deviations from the brief

**None in substance.** Two corrections of fact, and one thing the drive could not cover:

1. **The approvals route derives on `.approve` AND `.reject`, not just `.approve`.** The brief says
   *"every `finance.*.approve`"*. The registered middleware is ten abilities — both checker
   segments — because the derivation at `routes/web.php:171-176` uses
   `ApprovalAbility::isExcludedFromSuperAdminBypass()`, which is terminal-segment based. The
   sidebar predicate matches the route: `startsWith('finance.') && (endsWith('.approve') ||
   endsWith('.reject'))`.
2. **The statement IS reachable, so it is not in this commit** — see below.
3. **super_admin could not be driven** — the local copy has no holder. Covered by an arm instead;
   see *The browser drive*.

## Verified before writing anything

| Claim | Result |
|---|---|
| `app-sidebar.tsx` has no finance entry | ✓ — one match, the comment at `:382` naming "the compose-by-permission pattern Finance's nav additions follow (I7)". A pattern named after additions that did not exist. |
| Four finance GET routes registered | ✓ — `/finance`, `/finance/approvals`, `/finance/opening-balances/import`, `/finance/students/{student}/statement` |
| Their abilities | ✓ — group `finance.access`; approvals + a 10-ability derived checker list; import + `finance.opening-balance.submit`; statement group-only |
| The Users item is the precedent | ✓ — `:380-394`, gating on `rbac.manage_users`, not `admin_area.access` |
| `usePermissions` exposes the effective set | ✓ — `permissions: ReadonlySet<string>` |
| `use-permissions.ts` warns off effective permissions | ✓ — and the warning is explicitly *"Do NOT gate sidebar **persona** menus on them"*, with the reason (super_admin's set is ~everything). Not applicable to a permission-gated module, which the Users item already demonstrates. A comment in the new block says so. |

### The statement is already linked — left alone

`resources/js/pages/admin/finance/index.tsx` links it **twice**: the row itself (`:402`) and a
"View statement" action (`:458`), both `/finance/students/${row.student.uuid}/statement`. It takes a
student uuid so it cannot be a menu item; it is the coverage gate's one named exemption, and a
dedicated arm asserts the accounts list really does carry that link — an unchecked "it is linked
from somewhere else" is how a page becomes unreachable while looking accounted for.

The fuse's stop condition (statement unreachable **and** fixing it means changing the accounts
list's data shape) did not fire.

### ADR 0040 and super_admin — checked, not assumed

`EffectivePermissions::for()` iterates the enum asking `can()`, so every ability resolves through
the full Gate and ADR 0040's checker exclusion folds in. Its docblock claims this; I measured it.
A seeded super_admin's effective finance set:

```
finance.access
finance.payment.record
finance.credit-note.submit
finance.invoice.void-request.submit
finance.invoice.generate
finance.invoice.reduction.apply
finance.fee-schedule.manage
finance.discount-policy.change.submit
finance.fee-schedule.change.submit
finance.opening-balance.submit

finance.access:                 true
holds ANY finance checker:      false
finance.opening-balance.submit: true
```

So a super_admin sees the Finance group and the Opening-balances item, and **correctly does not see
Approvals** — the route would refuse them too. The sidebar offering a screen its holder can never
act on is this commit's own defect in the other direction, so it is pinned by an arm.

## What changed

| File | What |
|---|---|
| `resources/js/components/app-sidebar.tsx` | The Finance group — Accounts, Approvals (derived), Opening balances. |
| `tests/Feature/Finance/FinanceNavCoverageTest.php` | **New.** 6 arms. |

The group sits after the admin working area and before the persona menus, because it is a module,
not a persona. It keys on `finance.access` — the permission the whole `/finance` route group
requires — so no item can render for someone the route would 403.

**The approvals item derives its predicate; it does not list abilities.** The route's list is built
from `Permission::cases()`, so a future `finance.refund.approve` joins the route the day the case
exists. A hard-coded array in TypeScript would not join with it, and the item would be hidden from a
checker the route happily admits — a live permission with no way in, which is
`approval-feeds.ts`'s original defect rebuilt in the nav. An arm asserts the predicate's shape *and*
that no ability name appears one-by-one.

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/FinanceNavCoverageTest.php
{"tool":"pest","result":"passed","tests":6,"passed":6,"assertions":12}
```

### bin/quality — raw, unedited (ANSI colour codes stripped; nothing else removed)

```
quality gate — base 1c40ba2

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

## The watched red

```
RED 1 — a FIFTH finance page registered with no nav entry
  FAILED: every finance page is reachable from the sidebar, or is named here as not a nav destination
    A finance page is registered, permission-gated and reachable from NO menu: /finance/write-offs.
    Add it to the Finance group … or add it to FNC_NOT_NAV in this file WITH THE REASON …

RED 2 — the Finance group deleted from the sidebar entirely
  FAILED: every finance page is reachable from the sidebar …
  FAILED: gates the Finance group on the permission its routes require

RED 3 — the derived predicate replaced by a hard-coded ability list
  FAILED: DERIVES the approvals item from the checker convention rather than listing abilities

RED 4 — EffectivePermissions stops routing through the Gate
  FAILED: a super_admin sees the Finance group but NOT Approvals, because ADR 0040 denies them the route
    A super_admin now holds a finance CHECKER ability, so the sidebar would offer them an Approvals
    item onto a screen ADR 0040 makes the backend refuse.
```

**RED 3 did not fire on the first attempt, and that is worth recording.** My mutation was an exact
multi-line string replace, and Prettier had wrapped the predicate across an extra line, so the
replacement silently matched nothing and the run reported "no failure". A watched red that is
reported without checking the mutation landed is worth exactly as much as no red at all. Re-applied
against the real formatting, it fires.

## The browser drive

Two seats already granted in the copy — `user#3451` (accounts_supervisor, maker) and `user#3452`
(executive_director, checker). Each item was **clicked through**, because a label is not a link
until it lands:

```
=== MAKER   (user#3451, accounts_supervisor) ===
  sidebar shows a Finance group: true
  group labels: ["FINANCE"]
  finance links in the sidebar:
      {"label":"Accounts","href":"/finance"}
      {"label":"Opening balances","href":"/finance/opening-balances/import"}
  /finance                             clicked -> 200  "Finance - Laravel"
  /finance/approvals                   NOT in sidebar
  /finance/opening-balances/import     clicked -> 200  "Opening balances — import - Laravel"

=== CHECKER (user#3452, executive_director) ===
  sidebar shows a Finance group: true
  group labels: ["FINANCE"]
  finance links in the sidebar:
      {"label":"Accounts","href":"/finance"}
      {"label":"Approvals","href":"/finance/approvals"}
  /finance                             clicked -> 200  "Finance - Laravel"
  /finance/approvals                   clicked -> 200  "Finance — pending approvals - Laravel"
  /finance/opening-balances/import     NOT in sidebar
```

Maker sees Import and not Approvals; checker sees Approvals and not Import; every visible item
returns 200 on the page it claims to open.

**super_admin was NOT driven.** The local copy has **zero** super_admin holders, and minting a
platform authority into a production copy to take a screenshot is a larger act than the screenshot
is worth. It is covered by an arm that asserts the thing the screenshot would have shown, closer to
its cause — see *ADR 0040 and super_admin* above, and RED 4.

## Not done

- **super_admin's sidebar was not seen in a browser** — reason above.
- **No arm asserts the group's POSITION** (after the admin area, before the personas). That is
  ordering inside one `useMemo` and a source assertion on it would pin formatting rather than
  behaviour.
- **The drive's link scrape caught page-body links as well as sidebar ones** — the checker output
  originally showed a third entry, "Pending approvals", which is a link on the `/finance` page, not
  a sidebar item. Harmless, and stated so nobody reads the raw output as three menu entries.
- **The gate covers `finance*` web GET routes only.** `api/v1/finance/**` is the data surface the
  pages fetch, not something a human navigates to; requiring nav entries for it would be nonsense.
  Written into the helper so the boundary reads as a decision.

## Findings raised, not fixed

- `resources/js/components/app-sidebar.tsx:489` has a pre-existing `tsc` error —
  `Property 'uuid' does not exist on type 'Teacher'`. Confirmed pre-existing by stashing this
  branch's changes and re-running; it is inside the tsc baseline. Not touched. **ticket.**
- The nav-coverage idea generalises beyond finance: **any** permission-gated web page can ship
  unreachable, and nothing checks the others. This gate is scoped to `finance*` because that is
  where the defect was found and where its exemption list can be argued. A repo-wide version is a
  bigger decision — it would need an exemption list for every detail page that takes an id.
  **ticket.**
