# Two guards on the draft-edit path that no test can fail

**Raised by:** cold review of PR #234 (findings A2 and A3). **Severity:** ticket — neither is a
defect today, both are assertions that look load-bearing and are not. **Not fixed in #234**: each is
a decision about what a guard is *for*, not a bug to patch.

Both verified against the repo before recording.

## A2 — `SchoolContext::assertOwns` is unreachable from the route

`EditFeeScheduleDraft::handle()` opens with

```php
$schoolId = SchoolContext::assertOwns($schedule, 'fee schedule', 'edited');
```

which refuses a null context AND a record belonging to another School. **Over HTTP the ownership
half can never fire.** `$feeSchedule` arrives by route-model binding on `{feeSchedule:uuid}`, and
`FeeSchedule` carries `BelongsToSchool`, so a foreign uuid does not resolve and the binding answers
**404** before the Action is constructed. `EditFeeScheduleDraftTest` asserts exactly that 404.

So the ownership branch is held up by one thing: `bin/ci-boundary-lint.php:215` requires the call to
be *present* (`$guardStrong = 'SchoolContext::assertOwns('`). A lint that checks a call exists cannot
check it can fire.

That is not automatically wrong — `SubmitFeeScheduleChange` carries the same guard for the same
reason, and its own docblock already says the publish path's refusal is "by accident rather than by
intent" because the locked re-read goes through the scope. The guard's value is cover for the day the
binding stops going through `SchoolScope`, plus a sentence instead of a bare 404. What is missing is
that **nothing would notice** if that day came and the guard had been quietly weakened to
`SchoolContext::require(` — the lint permits the weak form in some places, and no arm distinguishes
them.

**Options, none obviously right:** call the Action directly with a foreign model in a test (proves
the guard, does not prove the route); accept it as documented-unreachable and say so in the Action's
docblock; or make the lint distinguish where the strong form is *required* rather than merely
*present*.

## A3 — the "nothing was written" half of the four state arms cannot fail

Each of the four non-draft state arms ends:

```php
$fresh = FeeSchedule::withoutGlobalScopes()->findOrFail($draft->id);
expect($fresh->label)->toBe('v1');
expect(FeeItem::withoutGlobalScopes()->where('fee_schedule_id', $draft->id)->count())->toBe(1);
```

There is no reachable state in which that fails. The Action refuses before the transaction opens; if
both Action checks were removed, the DB trigger aborts the transaction and the write rolls back —
still unchanged. Confirmed by the RED B run recorded in the report: with both checks gone the arms
failed on the **422 assertion**, and the "nothing written" lines were never reached.

The assertions are not harmful and they document intent. But they are the shape of a test that looks
like it covers partial-write risk and does not, and this repo's own standard is that a green nobody
can turn red proves nothing. Either find the mutation that reds them — a write between the checks
that the trigger permits — or label them as documentation rather than coverage.

## Not recorded here

The remediation brief also listed **C3** and **C4** as tickets, with no description. Their text was
not supplied and has not been guessed at.
