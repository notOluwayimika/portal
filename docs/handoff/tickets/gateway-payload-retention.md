# Gateway payload retention — the policy, and the surface `redacted_at` cannot reach

**Raised:** 2026-08-28, from the cold review of `feat/gateway-transaction-table`.
**Owner:** unassigned — this needs the data owner, not a developer.
**Severity:** ticket, and it becomes live the day the webhook handler writes its first row.

## What exists

`finance_gateway_transaction_events` stores raw payment-provider deliveries verbatim. A Paystack
payload routinely carries the customer's **email**, often a **name**, the card **BIN and last four**,
and sometimes an **IP**. The table is append-only: `_no_delete` denies DELETE, and the update guard
admits exactly one UPDATE per row — a redaction, which must set `redacted_at` and clear `payload` to
NULL together, and may change nothing else.

So the mechanism works and is bite-proven, including on a real MySQL 5.7.23. What follows is what it
does **not** cover.

## 1 · The policy itself is not written

There is no schedule, no command, and no stated retention period. The migration ships the ABILITY to
redact so that setting a policy is a code change against a schema that already permits it. Nothing
redacts anything today, and nothing will until someone decides:

- how long a raw payload is kept — and whether the answer differs for a settled transaction (where
  the money question is closed) and a failed one (where a dispute may still open);
- what triggers redaction — an age, a case closing, an explicit request;
- who runs it, and how the run is evidenced.

**Until that is decided, the effective policy is "keep everything for ever".** That is the outcome
this table was built to avoid arriving at by silence, and shipping the door does not by itself change
it — it only makes changing it cheap.

## 2 · Redaction reaches one row in one database — the larger surface is untouched

This is the part that matters most and it is the part the mechanism cannot answer.

`redacted_at` is **row-level and forward-only**. A payload redacted on production remains in:

- **every `mysqldump` taken before the redaction**, wherever those dumps are kept;
- **the binlog**, for its retention window;
- **the production copy on a developer machine.** This project's ordinary working method derives
  findings against a copy of live (`CLAUDE.md`, `finance-context`), so payer PII landing on a laptop
  is the expected case, not an accident. One such copy was taken on 2026-08-26.

None of those is reachable from a trigger, and no amount of in-database redaction touches them. Any
retention position that names only `redacted_at` is narrower than it sounds — which is the same
defect class as a stated rule with no lint behind it, one level up.

**What is owed here:** a decision on dump handling and copy hygiene, and whether the finance tables
should be excluded or scrubbed when a production copy is made. That reaches well past this table and
past this workstream.

## 3 · `redacted_at` records WHEN and nothing else

No actor, no reason. On a table where redaction is the **only** evidence-destruction path that exists
— `_no_delete` closes the other — "who did this and why" is most of the value of the control. A
`redacted_by` / `redaction_reason` pair is a decision deliberately not taken in the shipping commit
rather than an oversight, because it should be decided together with §1 and by the same person.

Adding them later is an `ADD COLUMN` plus a trigger swap, and `installTrigger()` performs that swap
idempotently. Cheap — but only if someone notices, which is what this ticket is for.

## Why this is a ticket and not a build

Each of the three is a policy decision with a compliance dimension (NDPA), not an engineering choice
with a right answer. Building a redaction schedule before the period is decided would produce a
schedule enforcing a number nobody chose — the same shape as a control with no enforcement, inverted.

## Related

- `database/migrations/2026_08_27_100000_create_finance_gateway_transactions.php` — the RETENTION
  paragraph in `createEventsTable()`'s docblock, and the guards.
- `app/Finance/Models/GatewayTransactionEvent.php`
- `docs/handoff/reports/feat-gateway-transaction-table.md` — where the decision was taken and the
  reasoning that produced it, including the premise that was corrected.
