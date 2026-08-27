# TICKET — `protected static $logName` is declared on sixteen models and spatie never reads it

**Status:** open. Found while adding `LogsActivity` to `App\Models\Scholarship`; that model works
around it, nothing else does.

## The fact

Two idioms for naming a log bucket sit side by side in `app/Models`. One works and one does nothing,
and the one that does nothing is used four times as often.

Twenty-three models declare their bucket as a static property:

```php
protected static $logName = 'academics';   // 16 models
protected static $logName = 'results';     //  6 models
protected static $logName = 'setup';       //  1 model  (MarkingComponent)
```

Spatie does not read that property. `LogsActivity::getLogNameToUse()`
(`vendor/spatie/laravel-activitylog/src/Traits/LogsActivity.php:130-137`) is the whole of the
resolution:

```php
public function getLogNameToUse(): ?string
{
    if (! empty($this->activitylogOptions->logName)) {
        return $this->activitylogOptions->logName;
    }

    return config('activitylog.default_log_name');
}
```

`$this->activitylogOptions` is the `LogOptions` object each model returns from
`getActivitylogOptions()`, and its `logName` is set by `LogOptions::useLogName()` — the other idiom,
which **five models do use** and which is why the property looks like it must work:

```
app/Models/Teacher.php:56       ->useLogName('teacher')
app/Models/Guardian.php:46      ->useLogName('guardian')
app/Models/Student.php:68       ->useLogName('student')
app/Models/Role.php:32          ->useLogName('rbac')
app/Models/Permission.php:31    ->useLogName('rbac')
```

Those five land in the bucket they name. The twenty-three that declare a property do not: each one
resolves to `config('activitylog.default_log_name')`, which is `'default'`
(`config/activitylog.php:24`). Nothing distinguishes the two groups on the screen, in the schema, or
in any test.

`App\Finance\Models\StudentDiscountAward` carries the trait with neither idiom, so it also writes
`default` — correct today only by accident of there being no third choice.

## The measurement

On the production copy, the distinct `log_name` values in `activity_log` are:

```
auth, authentication, default, guardian, rbac
```

There is no `academics` row, no `results` row and no `setup` row in the table — three declared
buckets, on twenty-three models, with not one entry between them. `guardian` is present because
`Guardian` uses `useLogName()`; `rbac`, `auth` and `authentication` are written by explicit
`activity('…')` calls. `default` is where the twenty-three ended up. (`teacher` and `student` are
absent for an unrelated reason: those two models simply have not been edited on this copy.)

## Why it matters

Three consumers key on `log_name`, and all three are affected:

- **The Activity Log screen's filter.** `ActivityLogController` builds the log-name multi-select from
  `->distinct()->pluck('log_name')` (`:144-145`), so the buckets an operator can filter by are the
  buckets that were actually written. `academics` has never been offered because it has never
  existed. An operator narrowing to "academics" to find who changed a class level finds nothing.
- **The severity map.** `config/activity_log_severity.php` matches on `"{log_name}.{event}"`. A
  future entry written as `academics.deleted` would be inert for the same reason
  `admin.user_impersonated` was inert until it was corrected — that dead-key defect is already
  documented in that file's own comments (`:20-26`), and this is the same defect at model scale.
- **Anything auditing by bucket.** `RbacOverview` and `EnsureTwoFactorEnrolled` both filter
  `where('log_name', 'rbac')`; that one works because it is written by an explicit `activity('rbac')`
  call. A reviewer reading `protected static $logName = 'academics'` on a model has every reason to
  believe the same querying works there. It does not.

This is the "rules without enforcement are wallpaper" shape with the sign flipped: not a rule with no
mechanism, but a **setting that looks applied and changes nothing**, in a file where the reader has
no reason to doubt it — least of all because the working idiom is five files away and neither form
carries a note saying which is which.

## What closes it

Replace the property with the call, on each model, in one commit:

```php
return LogOptions::defaults()
    ->useLogName('academics')
    ->logOnly([...])
    ->logOnlyDirty();
```

Two things make it more than a mechanical sweep, and they are why it is a ticket rather than part of
the `kind` commit:

1. **It changes what existing filters return.** Every entry written after the fix lands in a new
   bucket while every entry written before it stays in `default`, so a screen filtered to `default`
   silently stops showing new academics activity. Whether the historical rows should be migrated to
   their intended buckets — a `log_name` backfill keyed on `subject_type` — is a data decision, and
   it is the reason this needs a human rather than a rename.
2. **`setup` is a bucket of one.** `MarkingComponent` is the only model declaring it. If the sweep
   makes it real it creates a bucket with one member; folding it into `academics` at the same time is
   probably right and is a judgement, not a refactor.

A regression test belongs with the fix: assert the resolved bucket
(`$model->getLogNameToUse()`) rather than the declared property, or the next model to copy the
sibling form reintroduces this silently.
