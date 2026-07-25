# The drive environment — look at the application in five minutes

The acceptance tests prove the HTTP stack and are **structurally blind to rendering**: a 200 with an
empty list, a 200 with the right list, and a 200 rendering an error where a list should be are the
same assertion. Two of Finance's defects were found by a human loading a page. This is how you
become that human without the standing tax (2FA + a dev-bound server) that made every visual drive a
deferral.

## What it is

A **second, throwaway app instance** (`APP_ENV=drive`, its own database, port 8001) seeded by one
committed command — `php artisan finance:seed-drive-fixture` — that produces **every Finance state by
executing the real Actions**, never by writing rows. Every state you see is therefore a state the
system can actually reach, and `finance:reconcile-accounts` runs clean on the result.

It never touches your dev database: the command **refuses** unless `APP_ENV` is exactly `drive`, and
refuses again if the connected database name looks like a real one (`brookstone`, `staging`, `prod`,
`portal_testing`).

## 2FA is satisfied honestly, not bypassed

No authentication code is touched. In a non-production env `rbac.two_factor_enforced` is off by design
(`env('RBAC_TWO_FACTOR_ENFORCED', APP_ENV === 'production')`), exactly as your own local already is,
and the seeded users carry no 2FA secret — so plain email/password login reaches the page. (If a
future pilot-demo turns enforcement on, seed the users a fixed TOTP secret and compute the code at
login — but a drive env does not need it.)

## Stand one up from nothing

```bash
# 1. A throwaway database (any name WITHOUT brookstone/staging/prod/portal_testing).
mysql -e "CREATE DATABASE portal_drive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. A drive env file. Copy the template and fill in your local MySQL creds + an app key.
cp .env.drive.example .env.drive
php artisan key:generate --env=drive        # writes APP_KEY into .env.drive
#   (.env.drive is gitignored — it holds local secrets.)

# 3. Seed it (refuses anywhere that is not APP_ENV=drive).
APP_ENV=drive php artisan finance:seed-drive-fixture

# 4. Serve it on 8001 alongside the running vite dev server (5173).
APP_ENV=drive php artisan serve --port=8001
#   Open http://localhost:8001 — vite (npm run dev) serves the assets.
```

Re-run step 3 any time — it is idempotent (it `migrate:fresh`-es first), so you always get the same
fixture with no duplication.

## The cast (password for every user: `drive-password`)

| Sign in as | Email | Drives |
|---|---|---|
| Maker (`accounts_officer`) | `maker@drive.test` | the statements + submitting credit notes / voids |
| Full checker (`finance_director`) | `checker@drive.test` | the unified approvals queue (both feeds) |
| Void-only checker (`void-request.approve`, **no** `credit-note.approve`) | `void-checker@drive.test` | the per-feed 403-tolerant queue |
| Super admin | `super@drive.test` | the bypass exclusion (cannot approve) |
| School B bursar | `school-b@drive.test` | cross-School isolation |

## The states waiting for you (Drive School A)

Open `/finance` and click a student, or go straight to `/finance/students/{uuid}/statement`:

| Student | State |
|---|---|
| Ursula Unpaid | invoice unpaid — all three actions available |
| Paula Part | part-paid — outstanding shown, void blocked |
| Sam Settled | settled by payment — Record Payment suppressed, credit note still offered, void disabled-with-reason |
| Cara Credited | settled entirely by an approved credit note (never paid) |
| Oscar Overcredit | settled then credit-noted — account sits in credit |
| Pat Pending | a pending credit note **and** (2nd invoice) a pending void — the two-axis badge + both queues |
| Otto Onlyvoid | his only invoice is void — advance-payment edge |
| Emma Empty | no invoices at all — advance-payment edge |
| Bola (School B) | isolation — invisible from School A |

Drive records (screenshots + observations) live under `docs/handoff/drives/<date>/`.
