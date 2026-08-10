# Three comments the single-column cutover left behind

**Raised by:** cold review of PR #235 (findings 3.4, 3.5 and 1.3). **Severity:** ticket — all three
are prose, none changes behaviour. Recorded rather than fixed because none is load-bearing and the
commit was already carrying eight remediation fixes; each is a small, safe edit for whoever is next
in these files.

All three verified against the repo before recording.

## 3.4 — a branch that cannot be reached, explained as though it can

`OpeningBalanceInterpretation::for()` keys the per-student netting as:

```php
$key = $row->student_id ?? 'admission:'.(string) $row->admission_number;
```

with a comment saying the fallback is there "so an unresolved row is still counted as its own family
rather than silently merged with every other unresolved row under a null key".

**An unresolved row never reaches this loop.** The query filters `status = Ok`
(`OpeningBalanceInterpretation.php:58-61`), and every path in
`OpeningBalanceFileValidator` that leaves `student_id` null also appends a finding —
`student_not_found` (`:479`), `ambiguous_admission_number`, or `blank_required_column` for an empty
key — and `$isRejected = $row['findings'] !== []` makes the row **Rejected**. So on an `Ok` row
`student_id` is non-null by construction. `PostOpeningBalanceBatch` relies on the same invariant hard
enough to throw a `LogicException` if it is ever false.

The `??` is therefore harmless defensive code, and the comment is the problem: it describes a case
that cannot arise, which teaches a reader that unresolved rows flow through the summary. They do not
— they are excluded entirely, which is a more important fact and is not currently stated anywhere.

**Fix, when taken:** keep the `??` as a belt (it costs nothing and mirrors the posting's own
assertion), and rewrite the comment to say what is true — `Ok` implies a resolved student, this is a
guard against that invariant breaking, and rejected rows are not summarised at all.

## 3.5 — `student_total_balance`'s docblock still describes the old format

`OpeningBalanceRow.php:24-27`:

> `student_total_balance` is the student's STATED total, repeated identically on every row of that
> student's group. It is L1's independent witness (§1): **without it the checksum degrades to "the
> lines sum to themselves"**.

That reads as a warning about a degradation the format prevents. After the single-column cutover
**the degradation IS the designed normal case**: the column is OPTIONAL, Brookstone's extract does
not carry it, L1 is *not-applicable* rather than a refusal when no total is stated, and L2's
left-hand side is deliberately DERIVED from the student's own balances for those students — which is
precisely "the lines sum to themselves", now chosen rather than suffered.

The sentence is not wrong about the mechanism; it is wrong about the disposition, and a reader
meeting it will think a conforming Brookstone file is a degraded one.

**Fix, when taken:** state that the column is optional, that its absence removes the check rather
than weakening it, that the batch control total remains the attestation either way, and that the
derived left-hand side is named on every run in the console report so nobody mistakes a computed
figure for the file's word.

## 1.3 — the report undercounts its own evidence (CORRECTED, not deferred)

`docs/handoff/reports/feat-cutover-single-money-column.md:114` reads **"Watched reds — four
mutations, each verified landed in the source before the run"** above a table with **five** rows: the
four original mutations plus the `naira()` 1/100 scaling added when the magnitude question was
settled. A sixth was added by the cold-review remediation (restoring the original false NOTE) and the
header was still not updated.

A report that undersells its own verification is a small thing, but the same carelessness with a
count is what produced the ÷100 and the stale citations in the same document.

**Fixed in the same commit that filed this ticket**, because the report was open anyway and a
knowingly-wrong count is not worth deferring. The count was dropped rather than corrected — the
number had already gone stale twice, and a heading that does not claim a total cannot go stale a
third time. Recorded here so the review's item is accounted for rather than silently absorbed.
