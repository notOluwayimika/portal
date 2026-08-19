# TICKET — a failed guardian-import row writes the raw SQL, bindings included, into a downloadable spreadsheet

**Status:** open, not implemented. Pre-dates `feat/guardian-uniqueness-constraint` and is not caused
by it — but that branch makes it materially more likely to fire, which is why it is being recorded
now rather than left unnoticed.

## The path

`app/Services/GuardianImportService.php:140-143`:

```php
} catch (\Throwable $e) {
    Log::error('Guardian import: create failed', ['error' => $e->getMessage()]);

    return $this->failed('Failed to create guardian: '.$e->getMessage());
}
```

The logging on `:141` is correct and already captures the full exception. The problem is `:143`: the
same `getMessage()` becomes the row's `import_message`, and `import_message` is a column of
`app/Exports/GuardianImportResultExport.php` — a spreadsheet the operator downloads after an import.

When the throwable is a `QueryException`, that message is not a summary.
`Illuminate\Database\QueryException::formatMessage` (vendor,
`src/Illuminate/Database/QueryException.php`) builds it as:

```php
$sql.' (Connection: '.$connectionName.', SQL: '.Str::replaceArray('?', $bindings, $sql).')'
```

`Str::replaceArray('?', $bindings, $sql)` **interpolates the bindings into the SQL**. For a guardian
insert those bindings are the row: names, phone, WhatsApp number, emergency contact, address, id
number.

## Why it is newly likely

The failure has to be a database-level one to carry SQL, and until now the guardian create path had
no unique key to trip beyond `uuid`. `guardians_live_identity_unique` gives it one. Combined with the
null-email lookup defect recorded in
`docs/handoff/tickets/null-email-guardian-lookup-has-no-deploy-order-gate.md`, a bulk import of
email-less guardians is a plausible way to generate exactly this exception in bulk — one interpolated
row per failure, all in one file.

## Severity

Not ship-blocking for the uniqueness branch: the exposure is to the operator who ran the import and
who supplied the data in the first place, in a file scoped to their own import. It is still a
category of thing that should not exist — a downloadable artifact containing raw SQL with personal
data interpolated, produced automatically, with no redaction step and no expectation on the reader's
part that it would be there.

## The fix

Catch `QueryException` separately, before the `\Throwable` arm, and emit a fixed generic message. The
log line on `:141` already preserves everything an engineer needs, so nothing diagnostic is lost:

```php
} catch (QueryException $e) {
    Log::error('Guardian import: create failed (database)', ['error' => $e->getMessage()]);

    return $this->failed('Failed to create guardian: the database rejected this row.');
} catch (\Throwable $e) {
    // ...unchanged
}
```

Worth checking at the same time whether any other importer does the same thing — this is a shape, not
an instance, and `import_message` columns exist on more than one export.
