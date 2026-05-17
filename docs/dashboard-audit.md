# Dashboard Audit — Phase 0

Audited: 2026-05-17  
Branch: feature/admin-dashboard

## Summary

The current admin dashboard (`resources/js/pages/dashboard.tsx`) is a static UI prototype. All six components render 100% hardcoded data. No data is fetched from the backend. Every widget is completely disconnected from the school's actual state.

---

## Widget Inventory

### 1. StatCard (`stat-card.tsx`)

- **Description**: Displays a headline metric with a label, sub-text, and tone color
- **Data source**: None — hardcoded in `dashboard.tsx` render call
- **Hardcoded values**:
  - "Total students" → 1,240
  - "Total staff" → 98
  - "Fee debtors" → 34
  - "Results pending approval" → 7
- **Assumptions baked in**: School has students, staff, a fee module, and a results approval workflow
- **Computed fields**: None
- **Refresh strategy**: Never — static string
- **Disposition**: **REFACTOR** → `KpiCard` component accepting real data props (`value`, `trend`, `sparklineData`, `href`)

### 2. ActivityLog (`activity-log.tsx`)

- **Description**: Timeline of recent school activity with colored dots
- **Data source**: Hardcoded array of 5 items in the component file itself
- **Hardcoded values**:
  - "Mr. Obi entered scores for Yr 10A Maths"
  - "HoS approved Secondary EoT results (Yr 9)"
  - "Finance: 3 new debtors flagged, results locked"
  - "Admin impersonated Parent — Nneka Okafor" ← **PII RISK**: real-looking name embedded in source code
  - "New student enrolled: Chukwu, Emeka (Yr 7)" ← **PII RISK**
- **Assumptions**: Activity log module is active
- **Refresh strategy**: Never
- **Disposition**: **RETIRE** → replace with `ActivityFeedWidget` that queries real `activity_log` table via `ActivityLogQueryService`

### 3. StudentActivityTable (`student-activity-table.tsx`)

- **Description**: Table of recent student actions (downloads, payments, flags) with status badges
- **Data source**: Hardcoded array of 5 rows in the component
- **Hardcoded values**:
  - "Adeyemi, John" — Secondary, Year 10A
  - "Okafor, Amara" — Primary 5B
  - "Bello, Zara" — IFY Hybrid
  - "Nwosu, David" — Year 12A
  - "Ibrahim, Fatima" — Nursery 2
- **PII RISK**: All five rows contain fake but plausible student names — must not carry into the new system
- **Assumptions**: Multiple sections exist (Secondary, Primary, IFY), fee and attendance modules active
- **Refresh strategy**: Never
- **Disposition**: **RETIRE** — no clear query path in the current schema without a dedicated activity-per-student view; omit from v1, add in v2 when schema matures

### 4. QuickActions (`quick-actions.tsx`)

- **Description**: Button grid for rapid navigation to key admin tasks
- **Data source**: Hardcoded action list in the component
- **Actions**: Enter/approve results, Manage parents, Teacher dashboard, View fee debtors, School setup
- **Assumptions**: All linked pages exist and user has permission to all of them; fee module exists
- **Computed fields**: None
- **Refresh strategy**: Never
- **Disposition**: **REFACTOR** → `QuickActionsPanel` with real `href` links via Wayfinder route helpers; "View fee debtors" hidden when finance module is empty; context-aware additions based on data gaps

### 5. PopulationOverview (`population-overview.tsx`)

- **Description**: Horizontal bar chart showing student count per section
- **Data source**: Hardcoded array (Secondary 524, Primary 480, IFY Abuja 142, IFY PH 94)
- **Assumptions**: School has exactly four sections with these names; total is exactly 1,240
- **Computed fields**: Hardcoded percentage values (42%, 39%, 11%, 8%)
- **Refresh strategy**: Never
- **Disposition**: **REFACTOR** → `DistributionChart` querying real `students` table grouped by `class_level_id` or arm

### 6. ScoreEntryProgress (`score-entry-progress.tsx`)

- **Description**: Progress bars showing score entry completion percentage per section
- **Data source**: Hardcoded array (Secondary 72%, Primary 55%, IFY Abuja 88%, IFY PH 40%)
- **Assumptions**: Current term is "2025/2026 — First Term EoT" (hardcoded badge); assessment module is active
- **Computed fields**: Completion % is hardcoded, not computed from `scores` / `curriculum_subjects` counts
- **Refresh strategy**: Never
- **Disposition**: **REFACTOR** → accept real `scoreEntryData` prop derived from `scores` vs `student_subjects` count per section

---

## Risk Notes

| Risk | Severity | Detail |
|---|---|---|
| PII in source code | High | `activity-log.tsx` embeds "Nneka Okafor" and "Chukwu, Emeka" — real-looking names in production source |
| No tenant scoping | Critical | All components show the same hardcoded data regardless of which school is logged in |
| False operational picture | High | A school with 50 students sees "1,240" on their dashboard; creates confusion and distrust |
| No cache or refresh | Low | Static data, so no cache issues — but the replacement must cache properly |
| Missing fee module | Info | "Fee debtors" and "View fee debtors" assume a finance module that doesn't exist in the schema yet |
| Missing section model | Info | "Secondary/Primary/IFY" sections are school-specific arm names; the schema has `class_levels` + `arms`, not a "section" concept |

---

## Migration Plan

| Widget | Disposition | Replacement | Notes |
|---|---|---|---|
| StatCard | REFACTOR | `KpiCard` | Add `trend`, `sparklineData`, `href` props; wire to real counts |
| ActivityLog | RETIRE | `ActivityFeedWidget` | Use `ActivityLogQueryService`; real Spatie log entries |
| StudentActivityTable | RETIRE | (v2) | No clean query path in v1 schema |
| QuickActions | REFACTOR | `QuickActionsPanel` | Real routes; context-aware; hide finance links if module empty |
| PopulationOverview | REFACTOR | `DistributionChart` | Query students by class level arm |
| ScoreEntryProgress | REFACTOR | Updated component with real props | Compute from `scores` / `student_subjects` |

New widgets (no hardcoded equivalent):
- `DataGapsPanel` — surfaces orphaned students, unassigned enrollments
- `TrendChart` — activity or enrollment over last 30 days
- `DashboardOnboarding` — guided setup for new schools

---

## Constants and Magic Numbers to Remove

- `dashboard.tsx`: All four `<StatCard>` value/subText strings
- `activity-log.tsx`: The entire `items` array
- `student-activity-table.tsx`: The entire `rows` array
- `population-overview.tsx`: The entire `sections` array + hardcoded colors
- `score-entry-progress.tsx`: The entire `rows` array + hardcoded term badge
