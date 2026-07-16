# Brookstone Portal

A multi-School student information system (React 19 + Inertia on Laravel), being
hardened as the foundation for an Invoicing & Receivables financial control
module. Each School is an independent Brookstone School; `school_id` is the only
isolation boundary.

## Stack

- **Backend:** Laravel (PHP 8.3), MySQL, spatie/laravel-permission (teams mode,
  `school_id` as the team key), spatie/laravel-activitylog
- **Frontend:** React 19 + Inertia (TSX), Vite, **pnpm**
- **Tests:** Pest on **MySQL** (`portal_testing`) — never SQLite; the migrations
  use INFORMATION_SCHEMA and money must be tested on the production engine

## Setup

```bash
composer setup          # install, .env, key, migrate, npm install + build
composer dev            # serve + queue + vite
```

The test database is separate: create `portal_testing`, then

```bash
DB_DATABASE=portal_testing php artisan migrate --force
DB_DATABASE=portal_testing ./vendor/bin/pest
```

See [docs/testing.md](docs/testing.md).

## Documentation map

| Document | What it is |
|---|---|
| [CONTRIBUTING.md](CONTRIBUTING.md) | The **Architecture Constitution** (16 non-negotiables), workflow, and the CI gates |
| [docs/module-blueprint.md](docs/module-blueprint.md) | The exact shape every Module uses (`app/Finance/` will be the reference implementation) |
| [docs/adr/](docs/adr/README.md) | Architecture Decision Records — index + issued ADRs |
| [docs/roadmap.md](docs/roadmap.md) | Source-of-truth map: what governs architecture vs delivery, current status, approved deferrals |
| [docs/testing.md](docs/testing.md) | Test database setup and conventions |

The approved architecture specification (v10) and execution plan live outside
the repository (`plan_docs/`, untracked); [docs/roadmap.md](docs/roadmap.md)
records how they relate and what has landed.

## CI

Two workflows run on PRs to `staging` and `main`:

- **linter** (no DB): changed-file Pint/Prettier/ESLint, tsc **ratchet**,
  commented-authz lint, boundary lint (§17.2), architecture tests (§17.1),
  Larastan level 5 vs baseline
- **tests** (MySQL service): full Pest suite under a **failure ratchet** —
  known-failing tests are frozen in `tests/ratchet-baseline.txt`; CI fails only
  on a NEW failure

Every gate is a ratchet: baselines freeze pre-existing debt and may only
shrink. See CONTRIBUTING for the list of baselines and how to burn them down.

> Branch protection / required status checks are a GitHub settings concern and
> are **not yet confirmed as enabled** on this repository; until they are, the
> gates above are enforced by convention (PR review) rather than by the platform.
