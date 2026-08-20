# TICKET — a session set on an `/api/*` request is silently inert, and this is the fourth time it has been written down

**Status:** open, not implemented. The remedy proposed below — a `statefulJson()` helper in
`tests/Pest.php` — is deliberately **not** added in the branch that files this ticket. It needs its own
branch and a test that proves a session-only call reads the wrong school, or it is the fifth note.

## This is a rediscovery, not a discovery

The mechanism was written down **three times before this ticket**, twice in test comments and once in a
report. All three are correct. None of them changed anything, because a correct note with no mechanism
behind it is a wish.

### a. `tests/Feature/Notifications/NotificationFeedTest.php:94-104`

Eleven lines of comment, immediately above the `$stateful` helper at `:105`. It predicts this exact
failure in its own words:

```php
    // THE `Referer` IS LOAD-BEARING, and worth knowing about. statefulApi() is
    // enabled, so the SPA's /api calls carry the session — but Sanctum only
    // applies the session middleware to requests from a stateful domain, which it
    // reads from Origin/Referer. `getJson()` sends neither, so without this header
    // the request is treated as a pure token call, has no session at all, and
    // ActiveSchool falls through to `users.school_id`. The test would then read
    // school A twice and pass for entirely the wrong reason.
    //
    // (That fallback is pre-existing ActiveSchool behaviour for genuine token
    // clients — ADR 0042 — and is why /api/switch-school also stamps the school
    // onto the token. It is not introduced here.)
    $stateful = fn () => $this->actingAs($user)->withHeader('Referer', config('app.url'));
```

### b. `tests/Feature/Notifications/ResultReadyFeedRowTest.php:114-124`

The `rrf_feed()` helper. **Line numbers corrected against the tree** — the brief for this ticket placed
the comment at `:117-119` and the header at `:120`; both are one line later:

```php
114	function rrf_feed(User $user, int $schoolId)
115	{
116	    return test()
117	        ->actingAs($user)
118	        // statefulApi() only applies the session middleware to a request from a
119	        // stateful domain, and getJson sends no Origin/Referer — without this the
120	        // request has no session and ActiveSchool falls through to users.school_id.
121	        ->withHeader('Referer', config('app.url'))
122	        ->withSession(['school_id' => $schoolId])
123	        ->getJson('/api/notifications');
124	}
```

That an off-by-one crept into a citation of a file about citations being load-bearing is its own small
joke; see `docs/handoff/tickets/stale-path-line-citations.md`.

### c. `docs/handoff/reports/feat-rbac-fail-closed-finance.md`, the "Arm 3a — the BYPASS" passage

Located by heading, at `:157-162` in the current tree:

> **Arm 3a — the BYPASS.** super_admin with a school selected records (201). Establishing that context
> needed a real mechanism, not a fixture trick: `api/*` only gets session middleware when Sanctum's
> `EnsureFrontendRequestsAreStateful` judges the request to come from the frontend, from
> `Referer`/`Origin` against `sanctum.stateful`. Without that header `ActiveSchool::id()` never sees
> the session's `school_id`, and a super_admin is explicitly denied the own-school fallback
> (`ActiveSchool.php:54`). The new helper sends the header, which is exactly the transport the SPA uses.

### The finding

**Both a. and b. live under `tests/Feature/Notifications/`.** Nothing under `tests/Feature/Finance/`
points at either. No skill a finance change loads mentions either. Three authors hit the same wall,
three wrote it down where they hit it, and the fourth had no way to find any of the three.

And nothing named the pattern, so nothing could be reused:

```
$ grep -rn statefulJson tests/ app/
(zero hits)
```

Every author rediscovered the header and wrote their own one-line lambda for it.

## The mechanism, from the code rather than from the notes

`bootstrap/app.php:52` enables it:

```php
$middleware->statefulApi();
```

`Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful` then applies the session middleware
**conditionally** — `handle()` at `:19-28` sends the request through `frontendMiddleware()` only when
`fromFrontend()` returns true:

```php
public function handle($request, $next)
{
    $this->configureSecureCookieSessions();

    return (new Pipeline(app()))->send($request)->through(
        static::fromFrontend($request) ? $this->frontendMiddleware() : []
    )->then(function ($request) use ($next) {
        return $next($request);
    });
}
```

`frontendMiddleware()` at `:48-65` is where `Illuminate\Session\Middleware\StartSession::class` lives.
And `fromFrontend()` at `:73-92` is a pure header check:

```php
public static function fromFrontend($request)
{
    $domain = $request->headers->get('referer') ?: $request->headers->get('origin');

    if (is_null($domain)) {
        return false;
    }
    ...
    $stateful = array_filter(config('sanctum.stateful', []));

    return Str::is(Collection::make($stateful)->map(...)->all(), $domain);
}
```

`Referer` first, `Origin` as fallback, and **`null` short-circuits to `false`** before
`config('sanctum.stateful')` is even read. `getJson()` sends neither header, so `StartSession` never
runs, so `$request->hasSession()` is false, so `App\Support\ActiveSchool::id()` skips its session
branch (`app/Support/ActiveSchool.php:42-44`) and falls through:

```php
42	        if ($request->hasSession() && ($id = $request->session()->get('school_id'))) {
43	            return (int) $id;
44	        }
...
54	        if (! $user->isSuperAdmin() && $user->school_id) {
55	            return (int) $user->school_id;
56	        }
```

`:54` is the fallback the report cites. **`withSession(['school_id' => ...])` on an `/api/*` route is
silently inert**, and the school actually used is `users.school_id`.

### Why nothing is red today

Because the two almost always name the same school. `tests/Feature/Isolation/SchoolContextRouteAccessTest.php:76-88`
is the shape, and it is a positive control that cannot fail for the reason it was written:

```php
it('allows the same School-scoped route once an active School is present', function () {
    $school = al_makeSchool();
    setPermissionsTeamId($school->id);
    $admin = al_makeUser($school->id);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/notices')
        ->assertOk();
});
```

`al_makeUser($school->id)` sets `users.school_id` to the same school the session names. The session is
inert; the fallback supplies the identical value; the test is green. It would be green with the
`withSession()` line deleted.

**A super_admin is the exception, and it is the exception that hides the problem rather than exposing
it.** `:54` denies the own-school fallback to super admins, so a super_admin `/api` call with an inert
session gets *no* school and 409s loudly — which is why the super-admin arms in that same file pass
`withSession([])` and assert 409. The failure mode is confined to non-super-admin actors, where it is
silent by construction.

## The measurement — re-derived, and it does not reproduce the earlier figure

A prior count put **236 of 375 tests across 36 files** as resolving their School through
`users.school_id` rather than the session they set. That figure was marked unverified in the brief for
this ticket, and it **did not reproduce**. Published below is what a script gets, with the script.

**The rule, stated so the number is re-derivable:**

- A *block* is a top-level `it(` / `test(` in a `tests/**/*Test.php` file.
- A block **sets a session school_id** if it matches `withSession\(\s*\[[^]]*['\"]school_id['\"]`,
  literally or via a file-local `function` whose body does.
- A block **hits the API** if it matches a `get|post|put|patch|deleteJson\(` on a `/api/` URI, or
  `->json('VERB', '/api/…')`, literally or via a file-local helper.
- A file **sets a header** if `withHeaders?\(\s*\[?\s*['\"](Referer|Origin)['\"]` appears anywhere in it.
- **AT RISK** = sets a session school_id AND hits the API AND the file sets no header anywhere.
- **Denominator** = sets a session school_id AND hits the API, header or not.

```python
#!/usr/bin/env python3
import re, pathlib

ROOT    = pathlib.Path('tests')
SESSION = re.compile(r"withSession\(\s*\[[^]]*['\"]school_id['\"]")
APICALL = re.compile(r"(?:get|post|put|patch|delete)Json\(\s*[\"'`]/api/|->json\(\s*['\"][A-Z]+['\"]\s*,\s*['\"]/api/")
HEADER  = re.compile(r"withHeaders?\(\s*\[?\s*['\"](?:Referer|Origin)['\"]", re.I)
BLOCK   = re.compile(r"^(?:it|test)\s*\(", re.M)
FUNCDEF = re.compile(r"^function\s+(\w+)\s*\(", re.M)

def split_blocks(src):
    idx = [m.start() for m in BLOCK.finditer(src)]
    return [src[s:(idx[i+1] if i+1 < len(idx) else len(src))] for i, s in enumerate(idx)]

def helper_names(src, pattern):
    names, defs = set(), [(m.group(1), m.start()) for m in FUNCDEF.finditer(src)]
    for i, (name, s) in enumerate(defs):
        e = defs[i+1][1] if i+1 < len(defs) else len(src)
        if pattern.search(src[s:e]):
            names.add(name)
    return names

at_risk_blocks = denom_blocks = 0
risk_files, denom_files = set(), set()

for path in sorted(ROOT.rglob('*Test.php')):
    src         = path.read_text(encoding='utf-8', errors='replace')
    has_header  = bool(HEADER.search(src))
    sess_help   = helper_names(src, SESSION)
    api_help    = helper_names(src, APICALL)
    for blk in split_blocks(src):
        sets_session = bool(SESSION.search(blk)) or any(h + '(' in blk for h in sess_help)
        hits_api     = bool(APICALL.search(blk)) or any(h + '(' in blk for h in api_help)
        if not (sets_session and hits_api):
            continue
        denom_blocks += 1
        denom_files.add(str(path))
        if not has_header:
            at_risk_blocks += 1
            risk_files.add(str(path))

print(f"blocks setting a session school_id AND calling /api/*      : {denom_blocks}")
print(f"  ... in files that set NO Referer/Origin anywhere (AT RISK): {at_risk_blocks}")
print(f"files in the denominator                                   : {len(denom_files)}")
print(f"files at risk                                              : {len(risk_files)}")
for f in sorted(risk_files):
    print("  " + f)
```

Raw output:

```
blocks setting a session school_id AND calling /api/*      : 357
  ... in files that set NO Referer/Origin anywhere (AT RISK): 329
files in the denominator                                   : 53
files at risk                                              : 48

AT-RISK FILES:
  tests/Feature/Academics/ClearCategoricalResultTest.php
  tests/Feature/Academics/KeyStageCoordinatorCommentTest.php
  tests/Feature/Academics/TermCalendarTest.php
  tests/Feature/Academics/WithdrawCurriculumSubjectTest.php
  tests/Feature/Academics/WithdrawnStudentTest.php
  tests/Feature/Finance/AccountPaymentTest.php
  tests/Feature/Finance/ApprovalsQueueRendersEveryTypeTest.php
  tests/Feature/Finance/BackstopGuardsTest.php
  tests/Feature/Finance/BankAccountTest.php
  tests/Feature/Finance/BulkInvoiceRunScreenTest.php
  tests/Feature/Finance/ByStudentRouteIsolationTest.php
  tests/Feature/Finance/CaptureColumnsTest.php
  tests/Feature/Finance/CreditNoteTest.php
  tests/Feature/Finance/DiscountPoliciesScreenTest.php
  tests/Feature/Finance/DiscountPolicyTest.php
  tests/Feature/Finance/EditFeeScheduleDraftTest.php
  tests/Feature/Finance/FeeScheduleChangeTest.php
  tests/Feature/Finance/FeeScheduleTest.php
  tests/Feature/Finance/FeeSchedulesScreenTest.php
  tests/Feature/Finance/FinancePrefillRoundTripTest.php
  tests/Feature/Finance/FixedAmountReductionTest.php
  tests/Feature/Finance/InvoiceReductionAuditTest.php
  tests/Feature/Finance/InvoiceWireIdsTest.php
  tests/Feature/Finance/MoneyCurrencyValidationTest.php
  tests/Feature/Finance/MultiLineInvoiceTest.php
  tests/Feature/Finance/NestedCurrencyValidationTest.php
  tests/Feature/Finance/OpeningBalanceDecisionSurfaceTest.php
  tests/Feature/Finance/OpeningBalanceOperatorScreenTest.php
  tests/Feature/Finance/OpeningBalanceSingleColumnTest.php
  tests/Feature/Finance/PaymentCurrencyGuardTest.php
  tests/Feature/Finance/PaymentReceiptTest.php
  tests/Feature/Finance/PercentageReductionTest.php
  tests/Feature/Finance/ReductionEnforcementTest.php
  tests/Feature/Finance/ReductionPreCheckTest.php
  tests/Feature/Finance/WalkingSkeletonTest.php
  tests/Feature/GuardianAuditTest.php
  tests/Feature/GuardianCrossSchoolImportTest.php
  tests/Feature/GuardianRegistrationTest.php
  tests/Feature/IncompleteResultsTest.php
  tests/Feature/Isolation/SchoolContextRouteAccessTest.php
  tests/Feature/MultiSchool/MultiSchoolAccessTest.php
  tests/Feature/PrincipalApprovalTest.php
  tests/Feature/Rbac/AuthzObserveModeTest.php
  tests/Feature/Rbac/RestoredCommentedGuardsTest.php
  tests/Feature/Rbac/TwoFactorEnrollmentTest.php
  tests/Feature/Students/PromotionLinkClosureTest.php
  tests/Feature/Students/WithdrawSoftEndTest.php
  tests/Feature/TeacherAssignmentTest.php

FILES IN DENOMINATOR THAT DO SET A HEADER (i.e. correct today):
  tests/Feature/Finance/PaymentRecordGateTest.php
  tests/Feature/Notifications/NotificationActionEndpointTest.php
  tests/Feature/Notifications/NotificationFeedTest.php
  tests/Feature/Notifications/ResultReadyFeedRowTest.php
  tests/Feature/Rbac/ImpersonationHttpTest.php
```

**329 of 357 blocks, across 48 of 53 files.** The earlier 236/375-across-36 figure was **not
reproducible**; publish this one and treat that one as withdrawn. The definitions differ — this script
resolves file-local helpers, which the earlier count may not have — so the two are not necessarily
measuring the same thing, and there is no way to tell now.

**This is an upper bound on inertness, not a bug count.** Every one of the 329 passes today. What the
number says is: for 329 test blocks, the line that *looks* like it establishes school context does
nothing, and the school actually under test is whatever `users.school_id` happens to hold. Where those
two agree — which is nearly always, because the same helper makes both — the test is green and proves
less than it reads as proving.

Only five files in the entire suite get this right. Three of them are the `tests/Feature/Notifications/`
files where the mechanism was discovered and written down; the other two are
`tests/Feature/Finance/PaymentRecordGateTest.php` and `tests/Feature/Rbac/ImpersonationHttpTest.php`.
`PaymentRecordGateTest` is the file the "Arm 3a" passage above was written about — that passage sits
under the heading `RULING 1 — PaymentRecordGateTest, split, and both halves bite`. Every correct usage
in the suite traces back to an author who had just been bitten by this.

## The remedy proposed

A named helper in `tests/Pest.php`'s functions section, alongside `al_makeSchool` / `al_makeUser`,
which sends the header and the session **together** so the two cannot be separated by accident:

```php
/**
 * A JSON request to /api/* that actually carries the session.
 *
 * statefulApi() is enabled (bootstrap/app.php:52), so Sanctum's
 * EnsureFrontendRequestsAreStateful applies StartSession only when the request
 * looks like it came from the frontend — which it decides from Referer/Origin
 * against config('sanctum.stateful'). getJson() sends neither header, so a bare
 * ->withSession(['school_id' => N])->getJson('/api/...') has NO session at all
 * and ActiveSchool falls through to users.school_id (ActiveSchool.php:54).
 *
 * The test then reads whatever school the actor happens to belong to and passes
 * for entirely the wrong reason. Sending both together is the point of this
 * helper: they are not separable.
 */
function statefulJson(User $user, int|string $schoolId)
{
    return test()
        ->actingAs($user)
        ->withHeader('Referer', config('app.url'))
        ->withSession(['school_id' => $schoolId]);
}
```

**DO NOT add this in the branch that files the ticket.** The helper is worth nothing without a test
that fails when the header is removed — a test that asserts a session-only `/api` call reads the
*wrong* school. Without that, the fifth author to hit this will find a fourth correct note.

Shape for that test: two schools, an actor whose `users.school_id` is school A, a session naming
school B, an `/api/*` read of a School-owned collection. Assert it returns **A's** rows — the wrong
ones — and then assert `statefulJson()` returns B's. The first arm is the bite-proof; without it the
helper is decoration.

## Not decided here

Whether the 329 blocks get migrated to the helper, in one branch or incrementally; whether a lint
should refuse a `withSession(['school_id' => …])` that reaches an `/api/` URI without a header;
whether `ActiveSchool`'s `users.school_id` fallback should survive at all (ADR 0042 baselines it with
an expiry). The one claim this ticket makes is that the mechanism has been correctly documented three
times in places nobody looking for it would find, that nothing named it, and that 329 blocks currently
depend on the fallback they appear to be overriding.
