# TICKET — `ScholarshipController` does not follow the house request pattern

**Status:** open. Opened by the commit that added the `scholarships.kind` writer, which deliberately
worked _within_ this controller's existing shape rather than fixing it.

## The fact

`app/Http/Controllers/ScholarshipController.php` is the only writer for a field that decides whether
a cohort is invoiced, and it is built on none of the patterns the rest of the codebase uses:

- **No FormRequest.** It takes a bare `Illuminate\Http\Request` and calls `$request->validate([...])`
  inline, in two methods, with the `name` rule duplicated between them. Compare
  `app/Finance/Http/Requests/SubmitCreditNoteRequest.php`, where the rules, their reasoning and the
  authorization live in one class the route can be read against.
- **`catch (\Throwable)` around the whole body**, returning `500` with a fixed English string and
  `\Log::error($th->getMessage())` — message only, no stack, no context, no exception. Every distinct
  failure (a missing School context, a duplicate key, a DB error) arrives at the client as the same
  sentence, and arrives in the log as one line with nothing to trace it by.
- **`\Log::` written as an inline FQN** rather than imported, so the file's dependencies cannot be
  read off its `use` block. (`\App\Support\ActiveSchool::` was the same until the `kind` commit;
  Pint's `ordered_imports`/`fully_qualified_strict_types` fixers hoisted it while formatting the file,
  which is also why that commit's diff touches `index()`, a method it otherwise does not change. The
  file was ALREADY Pint-dirty at `825eae0` on those same three fixers, verified by stashing the
  change and re-running `pint --test` against HEAD — so the formatting churn is a pre-existing debt
  the first commit to touch this file had to pay, not churn that commit introduced.)
- **`store()` and `update()` return different envelopes for the same resource.** `store()` returns
  `response()->json(new ScholarshipResource($s), 201)`, which serialises the resource as-is;
  `update()` returns the bare `ScholarshipResource`, which Laravel wraps in `data`. So a client
  reading `response.kind` after a create and after an update gets the field in one case and `null` in
  the other. This cost a test assertion on the branch that opened the ticket, which is the cheapest
  possible way to find it.
- **The duplicate-name check is a read-then-write**, `->where('name', …)->first()` followed by
  `->create()`, with no unique index behind it. Two concurrent creates both pass the check. The 409
  it returns is real for the sequential case and decorative for the concurrent one.

## What it has already cost, measured

`ValidationException` **is** a `Throwable`. Until the `kind` commit, the `catch (\Throwable)` in
`store()` swallowed it, so a create with a missing `name` answered:

```
status => 500
body   => {"error": "Failed to create scholarship"}
```

— measured on branch `feat/scholarship-kind-writer` before the change, by posting `[]` to
`/api/scholarships` as an authorised admin. No `errors` payload, no field named, and a client that
cannot tell a blank field from a broken server. That is not a theoretical defect of the pattern; it
was the live behaviour of this endpoint for as long as it has existed.

## Why it was left

The `kind` commit exists to unblock BSS: `scholarships.kind` had a reader
(`AwardStudentDiscount::assertScholarshipAllows()`) and a hard guard
(`ProcessBulkInvoiceRun`) and **no writer at all**, so every scholarship row on the production copy
sits at `NULL` and no student can be given a discount. That is a cutover blocker and it is nine days
out.

Rewriting a live endpoint — the one the Scholarships tab and the student admission form both depend
on — is a separate risk with a separate blast radius. Mixing it into the same diff as the field that
unblocks BSS makes both unreviewable: a reviewer cannot tell which of the two changes moved a
behaviour, and there is no version of this branch that ships one without the other.

So the commit did the smallest thing that the new tests require and nothing else: it moved the two
`validate()` calls **outside** the `try`, so a 422 escapes as a 422. The `catch (\Throwable)` is
untouched, the FQNs are untouched, the read-then-write is untouched.

## What closes it

One commit, off `staging`, that does not also change behaviour:

1. `StoreScholarshipRequest` and `UpdateScholarshipRequest` carrying the rules and the reasoning.
2. Replace `catch (\Throwable) → 500` with the framework's own handling, or a catch that logs the
   exception rather than its message and lets `bootstrap/app.php` map the driver codes it already
   maps (1062 → 409, and the duplicate-name case becomes that once the index exists).
3. A `UNIQUE (school_id, name)` index on `scholarships`, which is what the 409 currently pretends to
   be. Check for existing duplicates in the same migration before adding it.
4. An import for `Log`.
5. One envelope for both write methods. Whichever is chosen, the Scholarships tab reads
   `response.data.data` from `index` today, so `index` is the shape to match.

Items 1, 2 and 4 are mechanical. **Item 3 is a schema change on a live table and should be reviewed
as one** — it is the only part of this ticket that can fail against production data.
