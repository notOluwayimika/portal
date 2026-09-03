# RBAC — post-production-deploy task inventory

Companion to `docs/runbooks/phase1-deploy.md`. Everything the RBAC stream has built
that can only *take effect* in production, in dependency order. Sourced from the
roadmap, `rbac-implementation-plan.md`, ADRs 0040/0042/0043/0044/0045, and the slice
handoffs — consolidated here so the sequence lives in one ordered place.

## Read this first — deploying is not "done"

Deploying the stack flips RBAC from **built + staging-verified** to **rolling out in
production under observe-mode**. It is the *start* of the rollout, not the end.

- **The functional finish line is §24 closing** — authorization actually *enforcing*
  in production (a request lacking a permission gets 403), which happens at **A4→A5**
  below, not at deploy. Until then, every restored check runs in observe-mode:
  it records a would-be denial and continues, blocking nothing.
- **"Fully implemented" is later still** — after enforcement is stable, the
  transitional scaffolding is torn down (Authz removed, the super_admin bypass
  removed, legacy `users.school_id`/`school_user` dropped). Several of these are
  gated on "one stable release cycle" in prod.

So: at deploy, RBAC is *live but not enforcing*. At §24 close, it *enforces*. At
teardown complete, it is *fully implemented*. See "Definition of done" at the foot.

Every one-way step keeps a STOP-for-review before it. Nothing below is a big-bang.

---

## Phase 0 — deploy-time steps (in the deploy itself)

Embedded in `phase1-deploy.md`; listed here so the inventory is complete.

### ⚠️ SUPERSEDED — THE DATE IS OFF, PENDING A BROOKSTONE DIRECTIVE

**As of 2026-09-03, 5 September (deploy) and 6 September (the read-half launch) are SUPERSEDED.
Decision: Segun.** Brookstone have not given the directive, and neither date stands until they do.

**THE DATE IS SUPERSEDED, NOT DELETED, AND EVERYTHING BELOW IT SURVIVES.** The reasoning for the
deploy, the backfill-ordering trap and the 23-step sequence are unchanged and none of them was
wrong — they were never arguments about *when*. What follows is struck through only in its dates.

- **The sequence stands as written.** When a date is set, it is run from **step 0.1**, not resumed
  part-way. The pending-migration list is measured ON THE DAY: this document records what was
  pending when it was written, and carrying that forward is precisely the mistake step 0.2 exists to
  stop. Everything else in it is date-independent.
- **The three `⚑ UNVERIFIED` hosting commands are still owed** — the backup, the restore, and the
  previous-release redeploy. They are worth filling in NOW rather than on the night, and the loss of
  a date is the argument for doing it while there is time, not against it.

---

#### THE CONSTRAINT THAT MEANS THE DATE COULD NOT SIMPLY HAVE BEEN KEPT

**Nobody in production holds `internal_auditor`.**

Run the bulk invoicing behind this migration and the term's bills are created with
`reviewed_at IS NULL` — correct, and exactly what the control is for — with **no seat able to
release any of them**. Parents would open the portal and see nothing. The withhold gate would be
working perfectly and the outcome would be indistinguishable from the portal being broken.

That is not an argument against the control or against the sequence. It is a **precondition nobody
had written down**: the release path needs a holder before the bills exist, and assigning one is a
Brookstone decision about who in their organisation performs Internal Audit.

**WHO MAY HOLD IT TEMPORARILY, AND WHO MAY NOT.** `finance.invoice.approve` is the checker side of a
maker-checker pair whose maker is `finance.invoice.generate`, declared in
`ApprovalAbility::MAKER_OVERRIDES` and enforced at grant time by `DutySeparation` — the grant is
refused wholesale, not warned about.

| Seat | May hold it | Why |
| --- | --- | --- |
| `admin` | **NO** | holds `finance.invoice.generate` — both sides on one role |
| `accounts_officer` | **NO** | holds `finance.invoice.generate` — both sides on one role |
| `head_of_school` | yes | holds neither side today |
| `executive_director` | yes | holds neither side today |

Re-derive that table before acting on it — it is read from `RbacSeeder::grantsMap()` as at this
commit, and a grant map edit moves it.

---

### THE DEPLOY DATE THAT WAS SET: ~~5 SEPTEMBER 2026~~ (superseded, above)

**Decided by Segun.** This is the "date of its own" Directive 1 of
[`launch-split-decision-31-august.md`](launch-split-decision-31-august.md) said the deploy needed,
and the dated act that closes [`open-findings.md`](open-findings.md) Finding 0 **for the read half**
— not for the pay half, whose migrations are on their own schedule.

**What it is for.** `2026_08_31_100000_finance_invoices_internal_audit_review.php` — the
`reviewed_at` / `reviewed_by_user_id` columns, the index, and the backfill. Without that migration
**on production**, the parent portal cannot withhold unreviewed bills. That is Directive 1 of the
launch split and Brookstone's ruling of 31 August (`brookstone-answers-31-august.md` §2, §6), and
Directive 1 already states the reason the merge is not the protection: *"Merging it does not protect
anyone. The migration running ON PRODUCTION does."*

**Why the date, and what it costs.** The read half opens **6 September**. That is **one day of
margin.** So:

- **Everything in the IA review slice must be MERGED by end of 4 September.**
- **The 5th is a deploy day, not a build day.** A branch that lands on the 5th has not been through
  the release gate on the tree that ships, and there is no second day to find out.

---

#### ⚠️ BACKFILL ORDERING — a trap, not a sequencing preference

**The resumption bulk invoicing run happens AFTER this migration, never before.**

The migration's backfill stamps `reviewed_at = created_at` on **every invoice that exists when it
runs**, with `reviewed_by_user_id` left **NULL**. Its own docblock defines that combination as
*"grandfathered: released because it predates the control"*, and says why the user is NULL: *"Nobody
reviewed them, and naming a user who did not would be a fabricated audit record — the one thing an
audit column must never contain."*

That reasoning is sound **for the book that predates the control**. Run the bulk invoicing first and
it silently extends to the new book: **every freshly-raised bill is stamped released, by nobody, and
goes straight to parents unreviewed.**

**This is the stopgap in disguise.** It does not look like skipping Internal Audit — it looks like
an ordering detail — and the result is indistinguishable from the state Brookstone ruled out on
31 August, except that the audit trail positively asserts the bills were releasable. Nothing in the
migration can catch it: from inside the backfill, a bill raised ten minutes ago and a bill raised in
July are the same row.

Order: **migration, then bulk run.** Bills raised after the migration carry `reviewed_at IS NULL`
and wait for a reviewer, which is the whole point of the control.

---

### THE SEQUENCE — tick it, do not read it

Every step has a verification beside it, because prose that cannot be ticked is not a runbook. The
numbers are the order; a step with no tick beside it was not done, whatever anybody remembers.

**THE COMMANDS BELOW ARE UNVERIFIED.** They are written for the shape this repository has, not run
against production — nobody has executed them, and the hosting-specific ones (§1.1, §5) are marked
where they need Segun's actual commands before the day. A prescribed command in this repository is
either one that has been run or one marked UNVERIFIED; there is no third state.

#### 0 · BEFORE ANYTHING — MEASURE, DO NOT ASSUME

- [ ] **0.1 List every PENDING migration on production.**

      ```bash
      php artisan migrate:status | grep -i pending
      ```

- [ ] **0.2 Compare that list against what you expect. If it contains ANYTHING beyond the 31 August
      withhold migration, STOP and read each one before continuing.**

      **Deploying `staging` runs all of them, not one.** This is the step the whole document exists
      for: the deploy is scheduled for one migration, and the deploy mechanism does not know that.
      `open-findings.md` Finding 0 is the standing warning, and its own counts are a 30 August
      measurement that must be re-derived rather than carried.

      ```bash
      # What this branch adds over what production last ran, as FILES — the thing a fresh
      # `migrate` would replay. Never `SHOW COLUMNS`: a dev schema carries columns from every
      # branch ever migrated on that machine and answers about the machine, not the branch.
      git diff --name-only <last-deployed-tag>...HEAD -- database/migrations
      ```

- [ ] **0.3 Record the 0.1 list in this document, with the date, as run.**

      Not in a terminal buffer. The next person to read this needs to know what actually ran, and a
      list that lives only in scrollback is a list nobody can check afterwards.

      > **Pending migrations at deploy, recorded on ______:**
      > _(paste the 0.1 output here before proceeding)_

#### 1 · BACKUP — AND PROVE IT RESTORES

- [ ] **1.1 Full production database backup, written OFF the production host.**

      `⚑ UNVERIFIED — needs Segun's hosting command.` A backup on the same host is not a backup
      against the failure modes that take a host.

- [ ] **1.2 Restore it somewhere and COUNT ROWS.**

      ```sql
      SELECT COUNT(*) AS invoices FROM finance_invoices;
      SELECT COUNT(*) AS activity  FROM activity_log;
      ```

      **A backup nobody has restored is a belief.** On 2 September the production COPY was destroyed
      by a test run and no backup of it existed — the search for one returned a single empty table.
      That was a copy. This is production.

- [ ] **1.3 Do not proceed until 1.2 passes.** Not "looks fine" — the counts are non-zero and
      plausible against production.

#### 2 · DEPLOY

- [ ] **2.1 Low-traffic window. Maintenance mode on.**

      ```bash
      php artisan down --render="errors::503"
      ```

- [ ] **2.2 Deploy the code.**

- [ ] **2.3 Run the migrations.**

      ```bash
      php artisan migrate --force
      ```

- [ ] **2.4 CLEAR THE COMPILED CACHES — by deleting the files, not with `artisan config:clear`.**

      ```bash
      rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php \
            bootstrap/cache/routes.php bootstrap/cache/events.php
      ```

      **A stale compiled config in production is the same class of fault that cost the copy on
      2 September**: a cached config never calls `env()`, so every environment override is inert and
      the application reads values frozen at cache time. `bin/quality` now deletes exactly these four
      before it runs, and by direct `rm` rather than `artisan config:clear` — artisan boots THROUGH
      the cache, so clearing it with the thing it corrupts is the wrong order.

      Re-cache afterwards only if this deploy normally does; if it does, the re-cache must come
      AFTER the migrations, or it freezes a schema-dependent config from before them.

#### 3 · VERIFY THE MIGRATION DID WHAT IT CLAIMS

- [ ] **3.1 The columns exist.**

      ```sql
      SHOW COLUMNS FROM finance_invoices LIKE 'reviewed%';
      ```

      Two rows: `reviewed_at`, `reviewed_by_user_id`.

- [ ] **3.2 Count the GRANDFATHERED book, and record the number.**

      ```sql
      SELECT COUNT(*) AS grandfathered FROM finance_invoices
      WHERE reviewed_at IS NOT NULL AND reviewed_by_user_id IS NULL;
      ```

      This is the backfill's work: every invoice that existed when the migration ran, stamped at its
      own `created_at` with no reviewer — which the migration's docblock defines as *"grandfathered:
      released because it predates the control"*. **It should equal the invoice count from 1.2.**

      > **Grandfathered count: ______** _(record it — 6.2 is read against it)_

- [ ] **3.3 Count the UNREVIEWED book. Before the bulk run this must be ZERO.**

      ```sql
      SELECT COUNT(*) AS unreviewed FROM finance_invoices WHERE reviewed_at IS NULL;
      ```

      **If it is not zero, STOP.** Something created invoices after the backfill and nobody has
      reviewed them — which means either the bulk run has already been started, or an invoice was
      raised during the deploy window. Both need reading before anything else happens.

#### 4 · VERIFY THE SEAT — the step that has never been tested in production

**If any of 4.1–4.4 fails, the deploy is TECHNICALLY FINE AND THE FEATURE IS UNUSABLE. Treat it as a
stop.** Every failure this slice has fixed was of exactly this shape: a page correct and unreachable,
an ability granted and invisible, a control present and inert. None of them broke anything; each of
them meant the thing did not work for the person it was for.

- [ ] **4.1 The internal auditor signs in COLD — from the login page, NOT from a link.**

      They land on the review queue. Cold matters: `redirect()->intended()` wins over the landing
      branch, so following a link would exercise a different path and prove nothing about the one
      that was broken.

- [ ] **4.2 The count renders, and reads `Awaiting sign-off: 0`.**

      Zero is correct here — 3.3 just asserted it. What is being verified is that the number renders
      at all, and that the screen shows the reassuring empty state rather than the red "could not
      load the queue" alarm. Those two look different on purpose.

- [ ] **4.3 Both sidebar entries are present: `Internal audit → Review queue`, and
      `System → Activity Log`.**

      Each was invisible to this seat until this release, for the same reason: an enclosing
      `can(...)` gate the seat does not satisfy.

- [ ] **4.4 They open the activity log and IT POPULATES.**

      Not just loads — shows rows. An empty feed here means `activity_log.view_all` did not land, and
      the seat is reading only its own acts.

#### 5 · EXIT

- [ ] **5.1 Maintenance mode off.**

      ```bash
      php artisan up
      ```

- [ ] **5.2 A parent signs in. They see their existing bills. NOTHING HAS CHANGED FOR THEM.**

      The grandfathered book is released by construction, so the parent portal must look exactly as
      it did before the deploy. A parent seeing FEWER bills than yesterday means the backfill did not
      cover the whole book, and that is a stop.

#### 6 · THEN, AND ONLY THEN — THE BULK RUN

- [ ] **6.1 Run the resumption bulk invoicing. AFTER the migration, never before.**

      The trap is written out above; the one-line version is that before the migration, the backfill
      stamps the new book as reviewed by nobody, and from inside it a bill raised ten minutes ago and
      one raised in July are the same row.

- [ ] **6.2 Count the unreviewed book. It should now equal the number of invoices the run created.**

      ```sql
      SELECT COUNT(*) AS unreviewed FROM finance_invoices WHERE reviewed_at IS NULL;
      ```

      > **Unreviewed after the run: ______**  ·  **invoices the run reported creating: ______**

      They must match. If unreviewed is HIGHER, something else raised bills. If LOWER, something
      released bills nobody signed off.

- [ ] **6.3 A parent signs in. They must NOT see the new bills.**

      This is the compliance gate itself, checked from the payer's side rather than from the
      database. It is the one assertion in this document that a query cannot make.

- [ ] **6.4 The auditor's queue count equals 6.2.**

      The screen and the database agree. If the screen shows fewer, the feed is filtered somewhere it
      must not be — the endpoint's docblock carries that warning about `pagination.total`.

#### ROLLBACK TRIGGERS — decided now, not at 2am

| Trigger | |
| --- | --- |
| **0.2** finds pending migrations nobody has read | do not deploy; read them first |
| **1.2** fails to restore | do not deploy; there is no way back |
| **3.2 / 3.3** returns a number that does not match | roll back |
| **4.1–4.4** any failure | roll back |
| **6.3** a parent can see an unreviewed bill | roll back |

**The action for every trigger below the first two:**

```bash
php artisan down --render="errors::503"
# restore the 1.1 backup                     ⚑ UNVERIFIED — Segun's hosting command
# redeploy the previous release              ⚑ UNVERIFIED — Segun's hosting command
php artisan up
```

Then reschedule. **A trigger with no action beside it is a note, not a plan** — which is why the two
hosting-specific lines are marked rather than guessed at: a wrong restore command at 2am is worse
than a blank one, because it will be run.

Note the asymmetry: the first two triggers are **do not deploy**, not roll back. Nothing has changed
yet at that point, and rolling back from a state you never entered is how a checklist teaches people
to skip its own early steps.

#### WHAT IS NOT IN THIS DEPLOY, stated so nobody looks for it

**The return-to-Finance path.** There is no way for an auditor to send a bill back with a reason;
`finance.invoice.reject` is deliberately not even declared, because a permission declared ahead of
its code is the `pending_emitters` mistake the activity catalogue already carries twice.

**⚠️ THIS DEFERRAL IS WITHDRAWN (2026-09-03).** Approve-only was a compromise bought against the
6th; the return path is now built IN FULL rather than deferred, because the reason for deferring it
was a date that no longer exists.

**PENDING BROOKSTONE, DO NOT RE-SEND:** they have been told the return path arrives 13 September.
That note is now also pending their directive and must not be repeated until a date exists — a
second date asserted while the first is withdrawn is worse than saying nothing.

- [ ] **PRE-DEPLOY — EVERY SCHOOL MUST HAVE AT LEAST ONE ACTIVE BANK ACCOUNT, or its bursar
      cannot record a payment at all.** `2026_08_10_120000_finance_bank_account_foreign_keys`
      makes `finance_payments.bank_account_id` required for portal-issued payments
      (CHECK, keyed on `origin`). A school with no active account has nothing to
      select, so every payment it tries to record is refused.

      **This fails at the OPERATOR, not at the gate**, which is why it is a
      pre-deploy step rather than a test. The two-commit split
      (`2026_08_10_100000` creates the table and the screen; this one adds the
      constraint) solves the CODE half — there is a way to create an account
      before one is required. It does not solve the DATA half: nothing makes an
      account EXIST. That is this line.

      ```sql
      -- Per school, ACTIVE accounts only. Any school showing 0 must have one
      -- created — through Finance → Bank accounts — BEFORE the migration runs.
      SELECT s.id   AS school_id,
             COUNT(b.id) AS active_bank_accounts
      FROM schools s
      LEFT JOIN finance_bank_accounts b
             ON b.school_id = s.id
            AND b.deactivated_at IS NULL
      GROUP BY s.id
      ORDER BY active_bank_accounts ASC, s.id;
      ```

      Zero rows for a school is the failure. Deactivated accounts do not count —
      a deactivated account cannot receive new money, which is the whole reason
      commit 1 chose deactivation over deletion.
- [ ] **CUTOVER — PASTE THE WCBS EXTRACT INTO THE TEMPLATE, AND GET THE FILENAME RIGHT ON
      THE FIRST UPLOAD.** Two facts that only bite the person actually running the
      cutover, and they compound.

      **The raw extract is refused.** Brookstone's file heads its columns
      `Admission Number`, `Balance`, `Last Amended On`. The reader lowercases and trims a
      header cell but does not fold spaces, so `Admission Number` does not match
      `admission_number` and the file is refused with
      `Missing required column(s): admission_number.` Download the template from
      **Finance → Opening balances → Import**, paste the rows into it, and upload that.
      (`Last Amended On` may be left in — an unknown column is read past. It is NOT a
      payment date and nothing maps it onto one.)

      **A refused upload still SPENDS the batch reference.** On the console the refusal
      happens before the batch row exists, so nothing is consumed. **On the screen it is
      the other way round**: the controller inserts the batch first — so the operator has
      something to poll and so §7's key is enforced by the engine — and only then does the
      queued job parse. The screen defaults `batch_reference` to the FILENAME. So an
      operator who tries the raw extract, sees it refused, fixes the header and re-uploads
      **the same filename** hits `unique(school_id, batch_reference)` and gets a **409**,
      on a file that is now correct.

      That is the guard working, not a bug — but it is unrecoverable-looking at the moment
      it happens. Either upload the templated file first, or type a fresh
      `batch_reference` (it is an editable field on the form, shown rather than applied
      invisibly) on the retry. There is no un-spend: a batch row is never deleted.
- [ ] **PRE-CUTOVER — THREE DECISIONS THE PERSON PREPARING THE ROSTER AND THE CSV MAKES, ALL ABOUT
      LEAVERS, NONE VISIBLE TO ANY CHECK.** Rulings of 2026-08-11; full text at
      `opening-balance-import-spec.md` R15, R17 and R18 — the first two are listed as procedural in
      that document's §11 because **nothing in the portal can hold them**, and the third is decided
      in its §7 and needs no decision here at all. A leaver is the one student
      whose correct handling is decided entirely before the file is uploaded.

      **1. A leaver carrying a balance must NOT be soft-deleted. Withdrawn or graduated is the
      correct state.** This is a roster instruction, and it is held before the extract is even
      prepared. Trashing the record does not retire the money, and it fails twice, silently: the
      import **rejects** them (soft-deleted students are outside the admission-number roster, so
      their arrears never arrive at all), and any balance they already hold **still counts in the
      /finance KPIs** while the row renders as `Student #<id>` with a null uuid and no linkable
      statement — a debtor the bursar cannot open. Nothing refuses the soft delete and nothing
      flags it afterwards.

      **The portal can only tell you WHO is trashed, never who among them owes money** — at cutover
      `finance_student_accounts` is empty, and the balance lives in WCBS. So this query is a
      **worklist to cross-check against the extract by hand**, not a check that passes or fails. A
      query that could go green here would be the false comfort §11 exists to refuse.

      ```sql
      -- Soft-deleted students in this school, with their admission numbers, so the person
      -- preparing the extract can look each one up in WCBS. Any that owes money must be
      -- RESTORED and set to withdrawn/graduated BEFORE the extract is prepared.
      -- Ids and admission numbers only; no names.
      SELECT s.id AS student_id, s.admission_number, s.deleted_at
      FROM students s
      WHERE s.school_id = ?
        AND s.deleted_at IS NOT NULL
      ORDER BY s.id;
      ```

      **2. A row already settled in cash outside the portal is ZEROED, NOT DELETED.** No internal
      disbursement record is built for pre-cutover payouts. **"Zero it" and "delete it" are not the
      same instruction and the import cannot tell them apart** — both post nothing. What differs is
      what survives: a zeroed row still stages, still counts toward the file's row count and the L1
      check, so **the file still reconciles against what Brookstone sent**; a deleted row takes that
      reconciliation with it and leaves no trace the student was ever in the extract. Set the
      balance to `0` and leave the line in the file.

      **And do not reach for a credit note instead.** `SubmitCreditNote::handle` takes an `Invoice`
      and `CreditNoteKind` defines both of its kinds as a post-issuance credit *against an invoice*.
      A migrated leaver credit has no invoice. The instrument does not exist for this case.

      **3. A leaver in ARREARS is imported and chased — that is the default, and it needs no
      decision.** Their charges post like anyone else's; withdrawal and graduation exclude a student
      from being *invoiced*, never from holding a balance (spec R14/R18). **A debt Brookstone has
      decided to write off is handled by decision 2's mechanism, before the upload** — zero those
      specific rows, leave them in the file. There is no write-off instrument at import time and
      none is coming. Two things to know before agreeing to a write-off list: it is **irreversible
      by any live path** (R3 — the correction for a wrong imported balance is a database restore
      inside the cutover window, nothing smaller), and once posted, the portal **cannot say how much
      of a leaver's outstanding is tuition** — the per-fee-type split exists only as narrated ledger
      rows dated D and stops being derivable at the first payment (spec §10). Whether Brookstone
      *prefers* chase-or-write-off is still outstanding with them (`finance-mvp-cut-brief.md` §7
      item 6); it changes nothing here, so do not wait on it.
- [ ] **PRE-DEPLOY — CONFIRM THE THREE FINANCE TABLES ARE EMPTY, or the migration will
      stop the deploy.** `2026_08_09_120000_finance_capture_columns_s2_s3` adds five
      **NOT NULL columns with no defaults** to `finance_payments`,
      `finance_ledger_transactions` and `finance_payment_allocations`. MySQL refuses to
      add such a column to a non-empty table, so a single pre-existing row aborts the
      migration mid-deploy.

      **That is the design, not a flaw.** The alternative — nullable columns, or NOT NULL
      with a default — would stamp a fabricated value onto real money rows that nobody
      observed, on three tables whose `_no_update` triggers mean it could never be
      corrected. A deploy that stops is recoverable; a fabricated received date is not.
      This step exists so the stop is anticipated rather than discovered at 2am.

      ```sql
      -- All three MUST be 0. Any other answer: STOP, do not run the migration, and take
      -- the result to the project lead — the columns' shape is a decision that changes
      -- once real rows exist.
      SELECT 'finance_payments' AS t, COUNT(*) AS rows_present FROM finance_payments
      UNION ALL SELECT 'finance_ledger_transactions', COUNT(*) FROM finance_ledger_transactions
      UNION ALL SELECT 'finance_payment_allocations', COUNT(*) FROM finance_payment_allocations;
      ```

      Verified `0 / 0 / 0` on the production copy on 2026-08-09. Production itself is
      unconfirmed, which is the whole reason this line exists.
- [ ] **PRE-DEPLOY — COUNT the authenticated principals with NO resolvable school
      context, BEFORE this goes out.** The finance transactional models are now
      fail-closed (`config/rbac.php`, `rbac.fail_closed_models`), and on the **twelve**
      finance routes that route-model-bind one of them, such a principal now receives
      **409** where they used to receive **403**. The reason it is not simply "a
      super_admin thing": `SubstituteBindings` sits in Laravel's middleware priority
      list **ahead of** `SetSchoolContext`, so the binding throws before the middleware
      that would have issued the 403 ever runs. Any authenticated principal without a
      resolvable school is affected, not only a super_admin.

      The local copy has **one** such user and it is the super_admin. **Production has
      not been counted.** If the number is larger than expected that is a decision for
      the owner *before* the deploy — after it, the same fact arrives as a support
      ticket. Counts only; no names, no emails.

      ```sql
      -- Principals who cannot resolve a school from the database alone.
      -- ActiveSchool::id() resolves in order: runFor() override, session('school_id'),
      -- token school_id, then users.school_id — and that last fallback is DENIED to
      -- super admins (app/Support/ActiveSchool.php:54). The session is runtime state and
      -- cannot be counted here, so these are the principals whose context depends
      -- ENTIRELY on having selected a school in the current session.
      SELECT 'super_admin holders (never get the own-school fallback)' AS bucket,
             COUNT(DISTINCT u.id) AS principals
      FROM users u
      JOIN model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = 'App\\Models\\User'
      JOIN roles r ON r.id = mhr.role_id AND r.name = 'super_admin'
      UNION ALL
      SELECT 'non-super-admin with users.school_id IS NULL', COUNT(DISTINCT u.id)
      FROM users u
      WHERE u.school_id IS NULL
        AND u.id NOT IN (
          SELECT mhr.model_id FROM model_has_roles mhr
          JOIN roles r ON r.id = mhr.role_id AND r.name = 'super_admin'
          WHERE mhr.model_type = 'App\\Models\\User')
      UNION ALL
      SELECT 'API tokens carrying no school_id', COUNT(*)
      FROM personal_access_tokens WHERE school_id IS NULL;
      ```

      **The doubled backslash in `'App\\Models\\User'` is required and is easy to get
      wrong in the other direction.** `model_has_roles` is polymorphic and the column
      holds `App\Models\User`; in **raw SQL** the SQL string parser consumes one level,
      so the literal must be doubled. Pass the doubled form to a **query builder
      binding** instead and it matches nothing and returns a confident **zero** — that
      mistake is exactly how the #225 report came to state there were no super_admin
      holders when there was one.

      **Do not expect a `finance.access` grant to narrow this list.** A super_admin
      reaches every finance route through the `Gate::before` bypass, not through a
      grant row, so no query over `role_has_permissions` / `model_has_permissions` will
      show them. Counting the principals is the check; filtering by ability is not.

      Expected on the copy, for comparison: `1 / 0 / 0`.
- [ ] Slice-(i) pre-flight: run the prod divergence query
  (`prod-divergence-and-cascade-queries.sql` §C), **list** offenders, remediate to
  zero, *then* migrate — the composite-FK migration aborts mid-deploy otherwise.
- [ ] `rbac:sync` after `migrate`, **before** traffic hits the swapped routes —
  skipping it is a 27-route-group lockout. (Wire an `rbac:verify` gate if not yet.)
  The production repair is **plain `rbac:sync`**, never `rbac:sync --fresh`:
  `--fresh` resets every grant to the seeder map and so **discards the C6 per-school
  runtime matrix edits** (the configurable per-school authority the lead has asked
  for). Do not lean on the `--fresh` confirm as the safety net: `RbacSync.php:20`
  fires it **only** when the environment is `production`, so any env not detected as
  such (a mislabelled `APP_ENV`, a prod-like staging) gets **no prompt at all**, and
  an operator at an interactive shell can just answer yes. (`--no-interaction` does
  fail *safe* here — the confirm defaults to no, so it aborts rather than wiping —
  but the instruction, not the prompt, is what protects the matrix.) `--fresh` on
  staging in PR #182 was safe only because staging carries no runtime matrix edits;
  production does.
  And `rbac:sync` **only ever adds** — and only permissions newly created in that
  run. A grant **removed** from `RbacSeeder::grantsMap()` never leaves an environment
  where the role already exists, so the map edit alone is half a policy and the half
  that ships is the permissive half. Every future seat change that takes authority
  **away** needs its own named revocation migration alongside the map edit (see
  `2026_08_02_100000_realign_finance_governance_grants.php` — narrow, audited,
  `down()` deliberately a no-op).
- [ ] Set `AUTH_GATE_BEFORE_SUPERADMIN=true` explicitly in prod env (intent visible,
  not resting on the config default).
- [ ] `audit:verify-immutability` after `migrate` — confirms the `activity_log`
  triggers survived the deploy.
- [ ] Set `MONITORING_ALERT_RECIPIENTS` in the prod env (comma-separated; see
  `config/monitoring.php`) **before `php artisan optimize` below**. Empty is a
  SUPPORTED state — with none set, the unconditional `Log::error` in
  `routes/console.php` is the whole channel and a failure is still findable, just not
  pushed. There is no default address on purpose: one that arrives somewhere nobody
  reads looks like coverage.
  **The ordering is not optional.** `optimize` runs `config:cache`
  (vendor `OptimizeCommand.php:62`), so a value set *after* it is invisible until
  `optimize` or `config:cache` is re-run — `schedule:run` being a fresh process per
  tick does not help, because a fresh process reads the cached config too. Set it
  first, or re-cache after.
- [ ] **Prove the alert channel, do not assume it.** Same rule as `phase1-deploy.md`
  Step 5: registration proves the wrong thing. Mail fires only on failure, so silence
  after setting the address is indistinguishable from a dead channel — the exact state
  that hid `finance:audit-duty-separation` exiting non-zero nightly from 2026-07-25 to
  2026-08-05. `php artisan schedule:test` runs the selected event through
  `Event::run()` → `finish()` → `callAfterCallbacks()`
  (vendor `ScheduleTestCommand.php:83`), so the onFailure hooks fire for real;
  `schedule:list` does not. Force a genuine failure by moving
  `duty-separation-baseline.txt` aside for the duration — the command then exits 1 down
  the `NOT AUDITED` path — and **move it back immediately after**, or the real 00:00 run
  alerts on a file you moved.
  PASS is all three: the mail arrives, `Scheduled detector failed` with `exit_code` 1 is
  in the application log, AND `storage/logs/schedule-*.log` now exists.

  That third one is not a bonus check, it is the disclosure. `emailOutputOnFailure`
  calls `ensureOutputIsBeingCaptured()` (vendor `Event.php:435`), so the mail BODY IS
  that file — you cannot have the mail content without the file on disk. Two surfaces,
  one switch, and structurally so; it is not a defect that can be fixed separately.
  `finance:check-staffing-readiness` prints school display names, so those names are now
  on the server's disk (five files, overwritten daily — `sendOutputTo`'s `$append`
  defaults to false, vendor `Event.php:375-382`) as well as in the inbox.
- [ ] Every FK-dropping migration's `down()` verified for **re-upgrade**, not just
  rollback (the found-once MySQL leftover-index bug).
- [ ] `npm ci && npm run build` **before** `artisan optimize` — `resources/js/routes`
  and the Vite manifest are gitignored, so a fresh checkout has neither. Skipping it
  500s every page whose entry is missing from the manifest.
- [ ] `php artisan optimize` — and treat it as a **check**, not a formality:
  `route:cache` is the only thing in the whole floor that rejects two routes sharing
  a name (see `clone-dress-rehearsal.md` § 4d).
- [ ] `php artisan queue:restart` after the code is in place — `QUEUE_CONNECTION=database`
  and workers hold the old code in memory until restarted.
- [ ] Browser click-through before promotion — the only gate that renders a page.

## Phase 1 — immediately after deploy (verification)

- [ ] Confirm the scheduler actually runs in prod (`authz:prune` fires) —
  registration ≠ execution.
- [ ] Production snapshot for the one-way slice-(i) migration, with a **named
  owner's written acknowledgement** at the moment of crossing.
- [ ] Confirm the RBAC stack's routes resolve (the 299-route oracle holds against
  the deployed tree).

---

## Track A — enforcement → §24 closure (the authorization finish line)

The reason the workstream exists. §24 closes only when all four hold: authz-lint = 0
(already ✅), `AUTHZ_ENFORCE=true` in prod, observation evidence reviewed, enforcement
verified live.

- [ ] **A3 (a wait, not a task).** Observe-mode evidence accrues on real prod traffic
  in `authz_observations`. The clock starts *at deploy*. Retention is 30 days,
  pruned; do not let observe-mode take prod traffic until the scheduler is verified
  (Phase 1).
- [ ] **Review the evidence (§24 condition 3).** Using `authz:observations
  --summarize` / the A2 tooling: classify every would-be denial as *expected* or a
  *legitimate-access regression*. The tooling `exit 1`s until every class is
  classified — that is the gate.
- [ ] **A4 — enforce in staging.** `AUTHZ_ENFORCE=true` in staging; drive every
  per-role flow; classify each denial; fix each legitimate-access regression as its
  **own reviewed change** (not a blanket revert).
- [ ] **A5 — enforce in prod (§24 condition 4).** `AUTHZ_ENFORCE=true` in prod; live
  403 verification (a real permission-less request receives 403, confirmed live).
  **This closes §24.** User-facing change — announce (⚑), jointly scheduled.
- [ ] **A6 — Authz teardown** (ADR 0043 §5, gated on A5 + **one stable release
  cycle**). In order, each step preserving authorization: migrate all 46 `Authz::`
  call sites to their permanent home (Policy / FormRequest `authorize()` / Gate /
  `abort_unless`) → assert no `Authz::` remains → delete `App\Support\Authz` → remove
  `AUTHZ_ENFORCE` + `config/authz.php` enforce key → drop `authz_observations` +
  `authz:prune`/`observations` + schedule entry → delete rollout-only tests, **keep**
  `AuthorizationOrderingTest` + `FortifyPostureTest`.

## Fail-closed rollout + Debt 7

- [ ] **Notice / Export — prod enablement**, after the staging soak proves the audit
  was complete against real traffic. Per-model env-list entry, independent revert.
- [ ] **Further B-waves** on Finance-read models (`Student`, `StudentCurriculum`,
  `Invoice`, `Payment`, …) — each gated on its own request-path audit **and** Finance
  coordination (⚑ I2: flipping a Finance-read model from read-unscoped to throwing is
  a coordination point), and only when Finance is not mid-churn on that model.
- [ ] **B-7 — close Debt item 7.** Remove the `auth()->check()` gate so the
  fail-closed throw is transport-agnostic (ADR 0042 direction); remove the residual
  `catch (\Throwable)` fail-open. Gated on enough B-waves that every job/command-
  touched model is audited (⚑ — widens the throw to Finance's future scheduled jobs).

## 0045-C — the super_admin de-bypass

The subtractive step of ADR 0045. **All build (B1/B2) is done; this is the flip.**

- [ ] **Gates, all required:** C2/C3 live and stable · B2 verified in prod · prod
  grant-set **by-name** parity (super_admin = canonical, `rbac.impersonate` present —
  a count check is insufficient) · the A5-reframed **pre-C usage audit** (every
  current super_admin domain action mapped to impersonation or a named break-glass
  command).
- [ ] Stand up the **break-glass artisan commands** (per-incident, named, under
  `runFor`, audited) as the sanctioned path for anything impersonation can't express.
  Authorization is operational (prod shell access), not app-enforced — stated, not
  implied.
- [ ] **The flip:** narrow `Gate::before` to the platform-admin set, remove the
  super-admin bypass, remove `AUTH_GATE_BEFORE_SUPERADMIN` + its guard test + the
  runbook line; live 403 regression (super_admin hits a school-scoped ability with no
  impersonation session → 403). Retires the flag pinned at deploy.

## S7 — remove `users.school_id` + `school_user`

Expires ADR 0042's recorded debt. Fully one-way at the end.

- [ ] Run the **prod divergence count** (the S7 SQL set) — the authoritative number;
  dev's 0/0 agrees by construction and proves nothing. Non-zero → a backfill
  decision (real access for real people), resolved before the flag.
- [ ] Enable `rbac.single_source_access`; run the **parity soak** — dual-compute both
  paths per decision, full coverage matrix (every user category, ≥2 Schools, HTTP +
  queue), zero unexplained mismatches.
- [ ] Repoint the three direct `school_user` readers (`GuardianService`, `Teacher`,
  `Guardian`) to `model_has_roles` **before** the pivot drop — they sit outside the
  flag.
- [ ] Rollback rehearsal → **STOP-for-review** → drop `users.school_id` +
  `school_user` (working `down()`, boundary-lint baseline 5→1). One-way.

---

## Human rulings — gather any time, not deploy-gated

- [ ] **A5 pre-C usage audit** — whoever operates super_admin: enumerate every
  super_admin domain action today; each needs a home (impersonation or a named
  break-glass command) before 0045-C.
- [ ] **Break-glass ruling ratification** — architecture owner: confirm ADR 0045 A4
  (no standing permission; per-incident audited artisan commands).
- [ ] **I6 (Finance-owned)** — Finance seeds its 4 roles, which unblocks tightening
  the interim `finance.access` and setting the Finance-role `two_factor_required`
  defaults (subject to the `rbac.two_factor_enforced` platform flag).

---

## Definition of done — three distinct milestones, not one

1. **Deployed** — the stack is in prod, authorization runs in **observe-mode**
   (records, never blocks). RBAC is *live but not enforcing*. `super_admin` still has
   the ambient bypass; legacy columns still present.
2. **§24 closed (A5)** — authorization **enforces** in prod (permission-less request →
   403), evidence reviewed, verified live. This is the functional finish line for
   *authorization*. Scaffolding still present.
3. **Fully implemented** — teardown complete: `Authz` and `AUTHZ_ENFORCE` gone (A6),
   the super_admin bypass gone and impersonation the sole domain path (0045-C),
   `users.school_id`/`school_user` dropped (S7), fail-closed enabled on its target
   models and Debt 7 closed (B-7). Several of these sit behind "one stable release
   cycle" after §24.

Between (1) and (2), if enforcement surfaces a legitimate-access regression, the fix
is its own reviewed change — not a rollback of enforcement. Between (2) and (3), the
system is fully *functional*; what remains is removing the transitional machinery so
no future reader mistakes scaffolding for the mechanism.
