# Student registration spoofs every CREATE to `PATCH` and fails with a 400 nothing displays

**Found by:** the guardian-create drive on `fix/guardian-create-duplicates`, trying to
reach a rendered error on the registration screen. **Not that branch's defect** — it is
present at its base commit `e484a46` and `resources/js/components/students/student-form.tsx`
has no diff against it. Introduced by `6bfed87` ("feat: phase 1 finish").

**Severity: this is the highest-impact thing that drive found, and it is not in the
branch that found it.** Registering a student through the admin UI does not work.

## The mechanism

`student-form.tsx` builds one `FormData` for both create and edit, and appends the
method-spoofing field **unconditionally**:

```php
formData.append('_method', 'PATCH');

try {
    if (isEdit) {
        await axios.post(`/api/students/${student.id}`, formData);
    } else {
        await axios.post('/api/students', formData);      // ← spoofed to PATCH
    }
```

Laravel honours `_method`, so the create request arrives as `PATCH /api/students`.
Read from `php artisan route:list --path=api/students`, not assumed:

```
POST      api/students ................ StudentController@store
GET|HEAD  api/students ................ StudentController@index
PATCH     api/students/{student:uuid} .. StudentController@update
```

There is no `PATCH /api/students`. The request 400s with
`{"message":"HTTP method not allowed"}`.

## Why nobody sees an error

The same method's catch handles **one** status:

```php
} catch (err: any) {
    if (err.response?.status === 422) { … setErrors(flat); }
}
```

No else. A 400 — and a 403, a 419, a 500 — is caught and discarded, so the modal sits
open with every field still filled, no message, and the button re-enabled. Observed in
the drive exactly that way: `POST /api/students -> 400 {"message":"HTTP method not
allowed"}` in the network log, and `RENDERED ERROR TEXT: []` on screen.

That is the identical pair of defects `fix/guardian-create-duplicates` removed from
`add-standalone-guardian-modal.tsx` — a 422-only handler over a write that fails for
other reasons — sitting on a bigger screen.

## Blast radius

- The **edit** path is unaffected: `isEdit` posts to `/api/students/{uuid}`, where
  `PATCH` is the correct method, so the spoof is doing its intended job there.
- `StudentController::store` is therefore unreachable from this screen. Everything it
  does — the guardian entries, `processGuardianEntry`, the one-transaction guarantee —
  is exercised by tests and by no operator.
- Any test posting `POST /api/students` passes, because tests do not send `_method`.
  **The suite cannot see this**, which is why it has survived; it is precisely the
  class the drive exists for.

## What closing it looks like

1. Append `_method` only on the edit path.
2. Give the catch the same shape its siblings now have: 422 → field errors, 419 → a
   session message, anything else → a `_general` banner. A write that fails must say so.
3. An arm is not enough on its own here — the spoof is invisible to a JSON test — so
   whatever lands should be driven on the real screen before it is called done.
