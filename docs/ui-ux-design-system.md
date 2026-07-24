# UI/UX Design System & Page Implementation Guide

**Status:** Living document · the single source of truth for designing and
building pages in this application. Every new page and every redesign is measured
against this guide.

This is not aspirational theory — it documents the patterns already shipped in the
codebase. When a rule and the code disagree, that is a bug in one of them; fix it,
don't fork the style.

## Canonical reference implementations

Read these before building anything. They are the worked examples this guide
generalises from — copy their structure rather than inventing a new one.

| Pattern | Canonical file |
| --- | --- |
| List page (hero + filters + table + pagination) | [`resources/js/pages/admin/students/index.tsx`](../resources/js/pages/admin/students/index.tsx) |
| Detail page (hero + stat cards + section tables) | [`resources/js/pages/admin/finance/statement.tsx`](../resources/js/pages/admin/finance/statement.tsx) |
| List page with KPI stat cards | [`resources/js/pages/admin/finance/index.tsx`](../resources/js/pages/admin/finance/index.tsx) |
| Reusable stat card | [`resources/js/components/finance/finance-stat-card.tsx`](../resources/js/components/finance/finance-stat-card.tsx) |
| Status badge | [`resources/js/components/finance/account-status-badge.tsx`](../resources/js/components/finance/account-status-badge.tsx) |

---

## Table of contents

1. [Design philosophy & visual principles](#1-design-philosophy--visual-principles)
2. [Design tokens](#2-design-tokens)
3. [Standard page layout & structure](#3-standard-page-layout--structure)
4. [Header & page title (the hero card)](#4-header--page-title-the-hero-card)
5. [Action bar placement & behaviour](#5-action-bar-placement--behaviour)
6. [Statistic cards](#6-statistic-cards)
7. [Filter & search section](#7-filter--search-section)
8. [Data table](#8-data-table)
9. [Forms](#9-forms)
10. [Modals & drawers](#10-modals--drawers)
11. [Cards & content sections](#11-cards--content-sections)
12. [Navigation & breadcrumbs](#12-navigation--breadcrumbs)
13. [Empty, loading, error & success states](#13-empty-loading-error--success-states)
14. [Status badges, labels & chips](#14-status-badges-labels--chips)
15. [Button hierarchy & action placement](#15-button-hierarchy--action-placement)
16. [Typography](#16-typography)
17. [Spacing, grid & alignment](#17-spacing-grid--alignment)
18. [Icons](#18-icons)
19. [Responsive behaviour](#19-responsive-behaviour)
20. [Dark mode](#20-dark-mode)
21. [Accessibility](#21-accessibility)
22. [Animation & micro-interactions](#22-animation--micro-interactions)
23. [Reusable component standards](#23-reusable-component-standards)
24. [Do's and don'ts](#24-dos-and-donts)
25. [Copy-paste page skeleton](#25-copy-paste-page-skeleton)

---

## 1. Design philosophy & visual principles

**Purpose.** Give every page one recognisable shape so a user who has learned one
screen already knows the next.

**Principles**

- **Calm, card-based surfaces.** Content sits on soft white cards
  (`bg-white dark:bg-card`) floating on a light-grey canvas (`bg-[#f5f7fb]
  dark:bg-background`). Depth comes from a single soft shadow, never heavy borders.
- **Quiet chrome, loud data.** Chrome (labels, headers, borders) is muted slate;
  the data (names, amounts, values) is the darkest, boldest text on the page.
- **One accent.** Indigo/violet is the only brand accent — primary actions, active
  states, focus rings, the icon tiles. Semantic colours (emerald/amber/red) are
  reserved for meaning, never decoration.
- **Density with air.** Tables are compact (`text-xs`, `py-2.5`) but every card
  and section is generously spaced (`space-y-5`, `gap-4`). Compact where scanning
  matters, roomy everywhere else.
- **The frontend only displays.** It never computes money or business figures —
  the API returns everything pre-computed. (Enforced by `bin/ci-money-lint.php`.)

**Common mistakes**

- Introducing a second accent colour "to make it pop".
- Bordering everything instead of using the soft shadow.
- Full-width raw content with no card wrapper.

---

## 2. Design tokens

Tokens live as CSS custom properties in [`resources/css/app.css`](../resources/css/app.css)
(`:root` for light, `.dark` for dark) and are consumed either through Tailwind
semantic utilities (`bg-card`, `text-muted-foreground`, `border-border`) or the
explicit palette classes below.

### Colour roles

| Role | Utility | Meaning |
| --- | --- | --- |
| Brand / primary | `indigo-600`, `bg-primary`, `text-primary` | Primary actions, links, active state, focus |
| Canvas | `bg-[#f5f7fb]` / `dark:bg-background` | Page background |
| Surface | `bg-white` / `dark:bg-card` | Cards, tables, modals |
| Primary text | `text-slate-900 dark:text-white` | Titles, key values |
| Body text | `text-slate-700 dark:text-slate-200` | Names, primary cell content |
| Muted text | `text-slate-500` / `text-slate-400` | Labels, subtitles, metadata |
| Hairline | `border-slate-100 dark:border-slate-800` | Dividers, card borders |

### Semantic colours (meaning only)

| Colour | Used for |
| --- | --- |
| **emerald** | Success, active, paid, credit held, positive |
| **amber** | Warning, outstanding, pending, attention |
| **red / destructive** | Danger, withdrawn, error, delete |
| **blue** | Informational, "issued", in-progress |
| **violet** | Credit notes / secondary financial documents |
| **slate** | Neutral, settled, void, inactive, disabled |

### Radius, shadow, gradient

- **Radius:** `rounded-2xl` (hero & stat cards) · `rounded-xl` (table/section cards)
  · `rounded-lg` (buttons, inputs, filter controls) · `rounded-full` (badges, avatars).
- **Card shadow:** `shadow-[0_8px_30px_rgb(0,0,0,0.04)]`. Hover lift (interactive
  cards only): `hover:shadow-[0_10px_40px_rgb(0,0,0,0.07)]`.
- **Icon tile gradient:** `bg-linear-to-br from-indigo-50 to-violet-50 ...
  ring-1 ring-black/5` (see [§18](#18-icons)).

---

## 3. Standard page layout & structure

**Purpose.** The outermost wrapper every page shares.

**Structure (top to bottom)**

```tsx
<Head title="Page name" />

<div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
    <div className="mx-auto max-w-7xl space-y-5">
        {/* 1. Hero card            — always */}
        {/* 2. Stat cards           — when the page has KPIs */}
        {/* 3. Filter + table card  — list pages */}
        {/*    …or content sections — detail pages */}
    </div>
</div>
```

**Design rules**

- Canvas: `bg-[#f5f7fb] dark:bg-background`, `min-h-screen`, `pb-24` bottom breathing room.
- Content column: `mx-auto max-w-7xl` capped and centred.
- Vertical rhythm between blocks: **`space-y-5`** (never mix ad-hoc margins).
- Horizontal page padding is responsive: `px-4 sm:px-6 lg:px-8`.
- The page is wrapped by the app shell (sidebar + top bar) automatically — you only
  author the inside of `max-w-7xl`.

**Common mistakes**

- Using `container` or a different max-width.
- Putting margins on children instead of `space-y-5` on the parent.
- Forgetting `<Head title>` (every page sets a document title).

---

## 4. Header & page title (the hero card)

**Purpose.** Identify the page and host its top-level actions.

**When to use.** Every page. It is the first child of `max-w-7xl`.

**Structure.** A `rounded-2xl` card, `px-6 py-4`, laid out as
`flex-col gap-4 lg:flex-row lg:items-center lg:justify-between`:

- **Left:** an icon tile (or avatar) + a title block (`h1` + one-line subtitle).
- **Right:** the [action bar](#5-action-bar-placement--behaviour).

```tsx
<div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
  <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div className="flex items-center gap-4">
      <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
        <Landmark className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
      </div>
      <div>
        <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">Finance accounts</h1>
        <p className="text-xs text-slate-500">Every student account with ledger activity in your school.</p>
      </div>
    </div>
    <div className="flex shrink-0 items-center gap-2">{/* actions */}</div>
  </div>
</div>
```

**Design rules**

- Title: `text-xl font-extrabold tracking-tight`. Subtitle: `text-xs text-slate-500`,
  one line, describing what the page is for.
- Icon tile is `size-12 rounded-xl` with the indigo/violet gradient; the icon is
  `h-6 w-6 text-indigo-600`. On detail pages use an `Avatar` with the subject's
  initials instead of a generic icon.
- Detail pages add a back-affordance: an `ArrowLeft` link to the parent list, placed
  just before the title.

**Common mistakes**

- Multi-line subtitles, or omitting the subtitle.
- Actions drifting below the title on desktop (they belong on the right).

---

## 5. Action bar placement & behaviour

**Purpose.** Expose the page's primary and secondary actions consistently.

**When to use.** Inside the hero card, right-aligned, for page-scoped actions
(Add, Import, Export, New invoice, Refresh). Row-scoped actions live in the table's
Actions column (see [§8](#8-data-table)).

**Structure & order.** `flex shrink-0 flex-wrap items-center gap-2`. Left-to-right:
**secondary (outline) actions → primary (filled) action last.** Exactly one primary
action per page.

```tsx
<div className="flex shrink-0 flex-wrap items-center gap-2">
  <Button size="sm" variant="outline" onClick={onImport}
    className="rounded-lg border-slate-200 font-semibold text-slate-700 …">
    <FileX className="mr-1.5 h-4 w-4" /> Import
  </Button>
  <Button size="sm" onClick={onAdd}
    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95">
    <UserPlus className="mr-1.5 h-4 w-4" /> Add Student
  </Button>
</div>
```

**Design rules**

- Every action button is `size="sm"`, `rounded-lg`, `font-semibold`, with a leading
  `h-4 w-4` icon and `mr-1.5` gap.
- Gate write actions behind permissions (`usePermissions().can(...)` /
  `<Can permission="…">`) — never render an action the user cannot perform.
- Async actions show progress in place (`disabled` + label swap:
  `{exporting ? 'Exporting…' : 'Export'}`).

**Common mistakes**

- More than one filled/primary button.
- Primary action placed left of secondaries.
- Showing actions the backend will 403.

---

## 6. Statistic cards

**Purpose.** Surface a page's headline metrics above the data.

**When to use.** When a page has 2–4 KPIs (totals, balances, counts). Sits between
the hero and the table/content. Use the shared
[`FinanceStatCard`](../resources/js/components/finance/finance-stat-card.tsx); create
a sibling only if a genuinely different card is needed.

**Structure.** A responsive grid of cards; each card is a `rounded-2xl` surface with
a label, a big value, an optional sub-text, and a tone-coloured icon tile on the right.

```tsx
<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
  <FinanceStatCard icon={TrendingUp} tone="amber" label="Total receivables"
    value={formatNaira(kpis.total_receivables)} subText="Owed across all accounts"
    loading={loading && !data} />
</div>
```

**Design rules**

- Grid: `grid gap-4 sm:grid-cols-2 lg:grid-cols-3` (2 KPIs → `sm:grid-cols-2`).
- Value: `text-2xl font-extrabold tracking-tight tabular-nums`. Label:
  `text-xs font-medium text-slate-500`. Sub-text: `text-xs text-slate-400`.
- Tone maps to meaning (`amber` receivable, `emerald` credit, `indigo` neutral total).
- Pass `loading` so the value renders an `animate-pulse` skeleton, not a layout jump.
- Money values go through `formatNaira`; the card never receives a raw number to format.

**Common mistakes**

- More than 4 KPIs (that is a table, not a KPI row).
- Doing arithmetic in the component (`a + b`) — the API returns the total.
- Colouring tiles decoratively instead of by meaning.

---

## 7. Filter & search section

**Purpose.** Narrow the table below it.

**When to use.** The top row inside a list page's table card.

**Structure.** A `border-b` row, `flex flex-col gap-3 px-5 py-3 sm:flex-row
sm:items-center`, containing: a search input (with a leading `Search` icon), zero or
more dropdown filters, then a right-aligned "Showing X of Y" count and a Clear button.

```tsx
<form onSubmit={applySearch} className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
  <div className="relative w-full sm:max-w-md sm:flex-1">
    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
    <Input placeholder="Search by name or admission number…"
      className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm … dark:border-slate-700 dark:bg-slate-900"
      value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
  </div>
  <div className="w-full sm:w-44">
    <Select value={status} onChange={…} placeholder="All accounts" options={STATUS_OPTIONS} />
  </div>
  <div className="flex items-center gap-2 sm:ml-auto">
    <span className="hidden text-xs font-medium text-slate-500 sm:inline">
      Showing <span className="font-bold text-slate-700 dark:text-slate-200">{rows.length}</span> of
      <span className="font-bold text-slate-700 dark:text-slate-200"> {total}</span>
    </span>
    {hasFilters && <Button size="sm" variant="ghost" onClick={clear}><X className="mr-1 h-3.5 w-3.5" />Clear</Button>}
  </div>
</form>
```

**Design rules**

- Dropdown filters use the shared `Select` (`@/components/ui/base-dropdown`), never a
  raw `<select>`.
- Only expose filters/sorts the **API actually supports** — never a client-side filter
  that silently disagrees with server pagination.
- Search: debounce-free "apply on submit" (wrap in a `<form onSubmit>`) OR live state;
  either way reset to page 1 on change.
- Clear button appears **only when a filter is active** and resets all filters + page.

**Common mistakes**

- Raw `<select>` / native inputs instead of the shared components.
- Filters that reorder/hide rows client-side while the count and pagination come from
  the server.
- Not resetting to page 1 when a filter changes.

---

## 8. Data table

**Purpose.** The primary way to present lists of records.

**When to use.** Any collection of rows. Use a styled semantic `<table>` (this is the
house pattern — there is no generic `<DataTable>` abstraction; consistency comes from
these classes).

**Structure**

1. **Card wrapper:** `overflow-hidden rounded-xl border-none bg-white
   shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card`.
2. **Filter row** ([§7](#7-filter--search-section)) with a bottom border.
3. **Scroll region:** `custom-scrollbar overflow-x-auto` wrapping the table.
4. **Table:** `w-full text-xs`.
   - `thead > tr`: `border-b bg-slate-50/50 dark:bg-slate-900/30`; each `th`:
     `px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase`.
     Numeric headers `text-right`.
   - `tbody`: `divide-y divide-slate-100 dark:divide-slate-800`; each `tr`:
     `transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30`; cells `px-4 py-2.5`.
5. **Footer:** `border-t bg-slate-50/30 px-5 py-3` hosting `<Pagination>`.

**Features & how each is done**

| Feature | Implementation |
| --- | --- |
| Identity cell | `Avatar` (initials via `useInitials`) + bold name link + muted secondary line (e.g. admission #) |
| Column sorting | Only for **server-sortable** columns: a header `<button>` toggling the API `?sort`, with an `ArrowDownWideNarrow` / `ArrowUpNarrowWide` indicator. Do **not** fake client-side sort. |
| Status | A [status badge](#14-status-badges-labels--chips) |
| Numeric columns | `text-right tabular-nums` |
| Row actions | Right-aligned Actions column: ghost `size="icon"` `h-7 w-7` buttons (`Eye`, `Edit`, `Trash2`) with `title=`, gated by permission. Use a dropdown menu only when there are 4+ actions. |
| Row selection | Only when the page has a **bulk action**. Read-only lists omit it. |
| Pagination | Shared `<Pagination meta={…} setPage={…} setLimit={…} />` — `meta` is `{ total, per_page, current_page, last_page }` |
| Loading / empty / error | A single full-width `<td colSpan>` row — see [§13](#13-empty-loading-error--success-states) |

**Design rules**

- Table text is `text-xs`; headers are `text-[10px] uppercase`. This density is
  intentional and uniform.
- Names link to the record's detail page and use `hover:text-primary hover:underline`.
- The whole table scrolls horizontally on small screens via the `overflow-x-auto`
  wrapper — never let the page body scroll sideways.

**Common mistakes**

- Unstyled `<table>` (raw borders, default fonts).
- Client-side sorting a column the server can't sort (misleads about total ordering).
- Row-action buttons larger than `h-7 w-7`, or ungated.
- Putting loading/empty/error anywhere but a spanning row (breaks column alignment).

---

## 9. Forms

**Purpose.** Create/edit records, almost always inside a modal.

**Structure**

- One logical field per row; related short fields may share a row via `flex …gap-2`.
- Field = `Label` (`@/components/ui/label`) above an `Input` / `Select`.
- Vertical rhythm `space-y-4`; a divider (`border-t pt-3`) separates a summary/footer.
- Submit lives in the modal footer, not inline (see [§10](#10-modals--drawers)); a
  form posts via `form="form-id"` on the footer button, or an `onClick` handler.

```tsx
<div className="space-y-4">
  <div>
    <Label>Description</Label>
    <Input value={value} onChange={…} placeholder="Tuition" />
  </div>
  {formError && <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">{formError}</p>}
</div>
```

**Design rules**

- Validation errors: field-level inline, and a form-level banner
  (`rounded-md bg-destructive/10 p-2 text-sm text-destructive`). Surface the server's
  422 message verbatim.
- Disable the submit button while submitting and swap its label
  (`{submitting ? 'Creating…' : 'Create invoice'}`).
- Money inputs convert through `nairaToMinor` (never `parseFloat * 100`); running
  totals use `sumMinor`. Both live in `@/lib/format`.

**Common mistakes**

- Free-floating submit buttons instead of the modal footer.
- Swallowing the API error message and showing a generic one.
- Doing money maths in the component.

---

## 10. Modals & drawers

**Purpose.** Focused create/edit/confirm flows without leaving the page.

**When to use which**

| Use | Component | For |
| --- | --- | --- |
| **Modal** | `@/components/ui/Modal` | Forms, confirmations, focused tasks (most cases) |
| **Drawer** | `@/components/ui/sheet` (`SheetContent side="right"`) | Contextual side panels / long secondary content (e.g. managing a record's relations) |
| **Confirm** | `useApiSweetAlertConfirmation` / `ConfirmDialog` | Destructive confirmation (delete) |

**Modal structure.** `<Modal isOpen onClose title size footer>`. Sizes:
`sm | md | lg | xl | 3xl | 4xl | 5xl | full` (`md` default; `lg` for forms; `4xl` for
import/wide). The footer holds a right-aligned `Cancel` (outline) + primary submit.

```tsx
<Modal isOpen={open} onClose={close} title="Add Student" size="lg"
  footer={
    <div className="flex justify-end gap-3">
      <Button variant="outline" onClick={close} disabled={processing}>Cancel</Button>
      <Button type="submit" form="student-form" disabled={processing}>
        {processing ? <Spinner className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
        Create Student
      </Button>
    </div>
  }
>
  <StudentForm … />
</Modal>
```

**Design rules**

- Always provide `onClose`; clicking the backdrop / Cancel / close icon all dismiss.
- Destructive confirmations never live in a plain modal — use the SweetAlert helper so
  the copy and danger styling are consistent.
- Drawers slide from the right, `w-3/4 sm:max-w-sm`, and are for context beside the
  page, not primary create flows.

**Common mistakes**

- Building a bespoke dialog instead of `Modal`/`Sheet`.
- Delete with no confirmation step.
- Oversized modals for a two-field form (use `md`/`lg`).

---

## 11. Cards & content sections

**Purpose.** Group related content on detail pages.

**Structure.** A `rounded-xl` surface card. For a titled section (e.g. "Invoices"),
add a header row (`flex items-center gap-2 border-b px-5 py-3`) with a small icon, a
`text-sm font-bold` heading, and an optional count chip; then the body (often a table).

```tsx
<div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
  <div className="flex items-center gap-2 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
    <FileText className="h-4 w-4 text-slate-400" />
    <h2 className="text-sm font-bold text-slate-700 dark:text-slate-200">Invoices</h2>
    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">{count}</span>
  </div>
  {/* body */}
</div>
```

**Design rules**

- Reuse the same shadow/radius as every other card — sections must feel like siblings.
- Count chips are neutral slate, `rounded-full`, `text-[10px]`.
- Extract a repeated card/section into a shared component (as
  `FinanceStatCard`/`AccountStatusBadge` were) rather than copy-pasting markup.

**Common mistakes**

- Section headers styled like page titles.
- Divergent shadows/radii across sections on the same page.

---

## 12. Navigation & breadcrumbs

**Purpose.** Show where the page sits and let the user step back up.

**Standard.** Every page declares its breadcrumbs via the page component's static
`layout` property; the app shell renders them in the top bar.

```tsx
FinanceAccountsIndex.layout = {
  breadcrumbs: [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
  ],
};
```

**Design rules**

- First crumb is always `Dashboard → /dashboard`; the last crumb is the current page.
- `href` values are real, navigable routes (prefer wayfinder helpers where available).
- Detail pages additionally offer an in-hero `ArrowLeft` back-link to their list.
- In-app navigation uses Inertia `<Link>`, never a raw `<a href>` (which full-reloads).

**Common mistakes**

- Breadcrumbs whose last crumb links elsewhere, or whose hrefs 404.
- Using `<a>` for internal navigation.

---

## 13. Empty, loading, error & success states

**Purpose.** Never show a blank or ambiguous screen while data is absent or failing.

**Loading**

- Table: a spanning row with a centred `Spinner` (`<td colSpan={n} className="py-12 text-center"><Spinner className="mx-auto" /></td>`).
- Stat cards / value slots: an `animate-pulse` skeleton (`h-7 w-28 rounded-md bg-slate-100 dark:bg-slate-800`).

**Empty** (loaded, zero rows) — icon-in-circle + title + description, and a Clear
action when filters are the cause:

```tsx
<div className="flex flex-col items-center gap-3 text-center">
  <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800"><Wallet className="h-6 w-6" /></div>
  <div>
    <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">No accounts to show</p>
    <p className="text-xs text-slate-500">{hasFilters ? 'No accounts match this view.' : 'Accounts appear here once students have ledger activity.'}</p>
  </div>
</div>
```

For page-level empties (not inside a table) use the shared
[`EmptyState`](../resources/js/components/ui/EmptyState.tsx).

**Error** — red `AlertCircle` in a circle + message + a **Retry** button that re-runs
the fetch. Errors are recoverable, never a dead end.

```tsx
<div className="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/20"><AlertCircle className="h-6 w-6" /></div>
…<Button size="sm" variant="outline" onClick={() => void load()}><RefreshCw className="mr-1.5 h-3.5 w-3.5" />Retry</Button>
```

**Success** — a `react-toastify` toast (`toast.success('Invoice created.')`). Success
is transient feedback, not a persistent banner. Errors from mutations also toast
(`toast.error(...)`), unless shown inline in a form.

**Design rules**

- Distinguish **empty** (no data) from **error** (fetch failed) — different icon,
  colour and copy. Never render one as the other.
- Empty copy adapts to whether filters are active.

**Common mistakes**

- Only a spinner and nothing for the error path (a failed load looks identical to an
  empty result).
- Persistent success banners.

---

## 14. Status badges, labels & chips

**Purpose.** Encode a record's state at a glance.

**Structure.** A `rounded-full` pill, `inline-flex items-center px-2 py-0.5
text-[10px] font-semibold`, coloured by the semantic map. Colour is paired with a
**text label** (never colour alone). Derive status from data, then map to style +
label — see [`AccountStatusBadge`](../resources/js/components/finance/account-status-badge.tsx).

```tsx
const STYLES = {
  outstanding: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  in_credit:   'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  settled:     'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};
```

**Colour map** (same as [§2](#2-design-tokens)): emerald = active/paid/credit ·
amber = warning/outstanding · red = withdrawn/error · blue = issued/info ·
violet = credit note · slate = settled/void/neutral.

**Design rules**

- Always `text-[10px] font-semibold`, `rounded-full`, with a `dark:` pair per colour.
- For a **read-only** badge use a `<span>`; only make it interactive (the `Select`
  pill on the Students table) when the user can change the status inline.
- Count chips are the neutral slate variant of the same pill.

**Common mistakes**

- Colour without a text label (fails colour-blind users — see [§21](#21-accessibility)).
- Ad-hoc colours outside the map.
- Square badges or inconsistent sizing.

---

## 15. Button hierarchy & action placement

**Purpose.** Make the most important action obvious and everything else quieter.

**Hierarchy** (component: `@/components/ui/button`)

| Level | Variant | Use |
| --- | --- | --- |
| Primary | `default` (indigo) | The one main action per context |
| Secondary | `outline` | Supporting actions (Import, Export, Cancel) |
| Tertiary | `ghost` | Low-emphasis / icon actions, Clear |
| Danger | `destructive` | Destructive confirms |
| Inline nav | `link` | Textual navigation |

**Sizes:** `default` = `h-9`, `sm` = `h-8`, `lg` = `h-10`, `icon` = `size-9`. Toolbar
and row actions are `sm`/`icon`; row-action icon buttons are shrunk to `h-7 w-7`.

**Placement**

- Page actions → hero action bar, primary last ([§5](#5-action-bar-placement--behaviour)).
- Row actions → table Actions column, right-aligned, ghost icons.
- Modal actions → footer, `Cancel` (outline) then primary.

**Design rules**

- Exactly one primary action per view.
- Buttons carry a leading icon (`h-4 w-4`, `mr-1.5`) when it aids scanning; icon-only
  buttons must set `title` / `aria-label`.
- Never a raw `<button>`/`<a>` styled as a button — always `<Button>` (use `asChild`
  to wrap a `Link`).

**Common mistakes**

- Two filled buttons competing.
- Destructive actions using the primary style.
- Icon-only buttons with no accessible name.

---

## 16. Typography

**Purpose.** A fixed scale so hierarchy reads the same everywhere.

| Role | Classes |
| --- | --- |
| Page title (`h1`) | `text-xl font-extrabold tracking-tight text-slate-900 dark:text-white` |
| Stat value | `text-2xl font-extrabold tracking-tight tabular-nums` |
| Section heading (`h2`) | `text-sm font-bold text-slate-700 dark:text-slate-200` |
| Table header | `text-[10px] font-bold uppercase tracking-wide text-slate-400` |
| Body / cell | `text-xs` (bold `font-semibold` for the identity/value cell) |
| Secondary line / metadata | `text-[11px]` / `text-xs text-slate-400` |
| Subtitle / helper | `text-xs text-slate-500` |

**Design rules**

- Numbers that align in columns or KPIs use `tabular-nums`.
- Weight carries hierarchy: `font-extrabold` (titles/values) › `font-bold`
  (headings) › `font-semibold` (emphasised body) › normal.
- Don't introduce sizes outside this scale (no `text-base`/`text-lg` body text).

**Common mistakes**

- Bumping a title to `text-2xl`/`3xl` to "make it stand out".
- Proportional figures in aligned numeric columns (jitter).

---

## 17. Spacing, grid & alignment

**Purpose.** One spacing language so alignment is automatic.

**Scale (Tailwind 4-px step).** Preferred values: `1.5` (6px), `2` (8px), `3` (12px),
`4` (16px), `5` (20px). Avoid arbitrary pixel gaps.

| Context | Spacing |
| --- | --- |
| Between page blocks | `space-y-5` |
| Stat-card grid | `grid gap-4 sm:grid-cols-2 lg:grid-cols-3` |
| Hero padding | `px-6 py-4` |
| Stat-card padding | `px-5 py-4` |
| Filter row padding | `px-5 py-3` |
| Table cell padding | `px-4 py-2.5` |
| Button group gap | `gap-2` |
| Form field rhythm | `space-y-4` |

**Alignment**

- Content column: `mx-auto max-w-7xl`.
- Numeric columns and their headers are both `text-right`.
- Vertically centre inline groups with `flex items-center`.

**Common mistakes**

- Arbitrary margins (`mt-[13px]`) instead of the scale.
- Mismatched header/cell alignment in numeric columns.

---

## 18. Icons

**Purpose.** Reinforce meaning; never decorate.

**Library.** [`lucide-react`](https://lucide.dev) exclusively (import per-icon).

**Sizes**

| Context | Size |
| --- | --- |
| Hero icon tile | `h-6 w-6` (tile `size-12`) |
| Stat-card tile | `h-5 w-5` (tile `size-11`) |
| Button icon | `h-4 w-4` (`mr-1.5`) |
| Row action / inline | `h-3.5 w-3.5` |
| Avatar | `size-7` (rows) / `size-12` (hero) |

**Icon tile** (hero & stat cards): `flex size-11 items-center justify-center
rounded-xl bg-linear-to-br from-<tone>-50 to-<tone2>-50 shadow-sm ring-1 ring-black/5`
with a `dark:` gradient pair; the icon uses the matching `text-<tone>-600`.

**Design rules**

- Choose icons semantically (`Landmark`/`Wallet` finance, `GraduationCap` students,
  `TrendingUp` receivables, `AlertCircle` error).
- Icon-only controls need `title`/`aria-label`.
- Match icon colour to context (muted `text-slate-400` in headers; tone colour in tiles).

**Common mistakes**

- Mixing icon libraries.
- Oversized icons in dense rows.
- Decorative icons with no meaning.

---

## 19. Responsive behaviour

**Purpose.** Work from ~360 px phones to wide desktops.

**Breakpoints** (Tailwind): `sm` 640 · `md` 768 · `lg` 1024 · `xl` 1280.

**Rules by pattern**

- **Page:** padding `px-4 sm:px-6 lg:px-8`; column capped at `max-w-7xl`.
- **Hero:** stacks `flex-col` on mobile → `lg:flex-row lg:items-center lg:justify-between`.
- **Stat grid:** `grid gap-4 sm:grid-cols-2 lg:grid-cols-3` (1 col on phones).
- **Filter row:** `flex-col gap-3 sm:flex-row`; inputs `w-full sm:w-44`.
- **Table:** wrapped in `overflow-x-auto` so it scrolls sideways within its card; the
  page body never scrolls horizontally.
- Secondary text hidden on mobile where space is tight uses `hidden sm:inline`.

**Common mistakes**

- Fixed pixel widths that overflow small screens.
- Letting the whole page scroll horizontally instead of the table.

---

## 20. Dark mode

**Purpose.** First-class dark theme, not an afterthought.

**Mechanism.** `.dark` class on `<html>`, toggled by
[`useAppearance`](../resources/js/hooks/use-appearance.tsx) (`light | dark | system`,
`system` follows `prefers-color-scheme`). Tailwind variant: `@custom-variant dark
(&:is(.dark *))`.

**Rules**

- **Every** colour utility needs a `dark:` counterpart. Prefer semantic tokens
  (`bg-card`, `text-muted-foreground`, `border-border`) that flip automatically; when
  using explicit palette classes, always pair them:
  `text-slate-700 dark:text-slate-200`, `bg-white dark:bg-card`,
  `border-slate-100 dark:border-slate-800`.
- Badge/tile tones follow the established pairs
  (`bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400`).
- Test both themes before shipping. A missing `dark:` shows as white-on-white / invisible text.

**Common mistakes**

- A light-only colour with no `dark:` variant (the most common bug).
- Hard-coded `#fff`/`text-black` instead of tokens.

---

## 21. Accessibility

**Purpose.** Usable by keyboard, screen reader, and colour-blind users.

**Requirements**

- **Semantic HTML:** real `<table>/<thead>/<th>/<tbody>`, `<h1>/<h2>`, `<label>` tied
  to inputs, `<button>` for actions.
- **Accessible names:** icon-only buttons set `title` and/or `aria-label`.
- **Focus:** keep visible focus rings (`focus-visible:ring-2`); never `outline-none`
  without a replacement. All interactive controls are keyboard reachable and operable
  (the `Select` closes on outside-click and is button-based).
- **Colour is never the only signal:** status is colour **plus** text label; errors
  are icon + text, not just red.
- **Contrast:** body text uses `slate-700`/`slate-900` (and light equivalents in dark
  mode) to stay above WCAG AA on the card surfaces.
- **Images/avatars:** `AvatarImage` has `alt`; initials fallback conveys identity.

**Common mistakes**

- Icon buttons with no label.
- Removing focus outlines.
- Encoding meaning in colour alone.

---

## 22. Animation & micro-interactions

**Purpose.** Feedback and polish — subtle, fast, never distracting.

**Standard interactions**

| Interaction | Implementation |
| --- | --- |
| Hover on rows / links | `transition-colors hover:bg-slate-50/60`, links `hover:text-primary hover:underline` |
| Primary button press | `transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95` |
| Stat-card hover | `transition-shadow hover:shadow-[0_10px_40px_rgb(0,0,0,0.07)]` |
| Loading spinner / refresh | `animate-spin` on the icon while fetching |
| Skeletons | `animate-pulse` on placeholder blocks |
| Dropdown chevron | `transition-transform` + `rotate-180` when open |

**Design rules**

- Keep durations default/short; transition specific properties (`transition-colors`,
  `transition-shadow`), not `transition-all` on large/expensive trees.
- Motion communicates state (loading, pressed, open) — don't animate for its own sake.
- Respect reduced-motion sensibilities: no large/looping motion on content.

**Common mistakes**

- Slow or bouncy transitions.
- Animating layout in a way that shifts content.

---

## 23. Reusable component standards

**Purpose.** Compose from shared building blocks; extract duplication early.

**Shared library** (`resources/js/components/ui` and feature `components/*`)

| Component | Import | Use |
| --- | --- | --- |
| `Button` | `@/components/ui/button` | All buttons (`asChild` to wrap a `Link`) |
| `Input` | `@/components/ui/input` | Text inputs |
| `Label` | `@/components/ui/label` | Field labels |
| `Select` (base-dropdown) | `@/components/ui/base-dropdown` | Filter & form dropdowns |
| `Badge` | `@/components/ui/badge` | Generic badges (cva variants) |
| `Modal` | `@/components/ui/Modal` | Dialogs |
| `Sheet` | `@/components/ui/sheet` | Drawers |
| `Pagination` | `@/components/pagination` | Table pagination |
| `Avatar` | `@/components/ui/avatar` | Identity |
| `Spinner` | `@/components/ui/spinner` | Loading |
| `EmptyState` | `@/components/ui/EmptyState` | Page-level empty |
| `Can` | `@/components/can` | Permission-gated UI |
| `FinanceStatCard` | `@/components/finance/finance-stat-card` | KPI/metric card |

**Hooks & utils:** `useInitials`, `usePermissions`, `useApiSweetAlertConfirmation`,
`cn` (`@/lib/utils`), money helpers `formatNaira`/`nairaToMinor`/`sumMinor` (`@/lib/format`).

**Standards**

- Prefer an existing component over new markup; if you copy a block twice, extract it
  (as `FinanceStatCard`/`AccountStatusBadge` were extracted for the Finance pages).
- New shared components live beside their peers, are typed, documented with a short
  purpose comment, dark-mode ready, and composed from `ui/*` primitives.
- Use `cn(...)` to compose conditional classes; never string-concatenate class names.

**Common mistakes**

- Re-implementing a dialog/dropdown/table that already exists.
- Third-per-page copy-pasted markup that should be one component.

---

## 24. Do's and don'ts

**Do**

- ✅ Wrap every page in the standard shell (`bg-[#f5f7fb] … max-w-7xl space-y-5`).
- ✅ Lead with a hero card; add stat cards for KPIs; put lists in the styled table card.
- ✅ Use the shared components and the semantic colour map.
- ✅ Pair every colour with a `dark:` variant and a text label.
- ✅ Handle loading, empty, **and** error (with retry) distinctly.
- ✅ Render money via `formatNaira`; gate write actions by permission.
- ✅ One primary action per view, placed last in the action bar.

**Don't**

- ❌ Ship a raw/unstyled `<table>`, `<select>`, `<button>`, or bespoke dialog.
- ❌ Add a second brand accent or ad-hoc colours.
- ❌ Do money/business arithmetic in the frontend (`bin/ci-money-lint.php` will fail CI).
- ❌ Fake client-side sorting/filtering that disagrees with server pagination.
- ❌ Omit `dark:` variants, focus rings, or accessible names.
- ❌ Let the page scroll horizontally (scroll the table instead).

**Worked before/after**

```tsx
// ❌ Don't — raw, colourless, no dark mode, page-scrolls
<table className="w-full">
  <tr><td>{student.name}</td><td>{'₦' + (amt/100)}</td></tr>
</table>

// ✅ Do — styled card table, formatNaira, dark-ready, scoped scroll
<div className="overflow-hidden rounded-xl bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
  <div className="custom-scrollbar overflow-x-auto">
    <table className="w-full text-xs">
      <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
        <tr className="transition-colors hover:bg-slate-50/60 dark:hover:bg-slate-900/30">
          <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">{student.name}</td>
          <td className="px-4 py-2.5 text-right tabular-nums">{formatNaira(balance)}</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
```

---

## 25. Copy-paste page skeleton

A minimal list page that already satisfies this guide. Fill in the fetch, columns,
and filters.

```tsx
import { Head, Link } from '@inertiajs/react';
import { RefreshCw, Search, Landmark, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import Select from '@/components/ui/base-dropdown';
import { Spinner } from '@/components/ui/spinner';
import { Pagination } from '@/components/pagination';

export default function ExampleList() {
  // …state, fetch (loading / error), filters, pagination…
  return (
    <>
      <Head title="Example" />
      <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
        <div className="mx-auto max-w-7xl space-y-5">

          {/* Hero */}
          <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div className="flex items-center gap-4">
                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                  <Landmark className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                </div>
                <div>
                  <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">Example</h1>
                  <p className="text-xs text-slate-500">One-line description of the page.</p>
                </div>
              </div>
              <div className="flex shrink-0 items-center gap-2">
                <Button size="sm" variant="outline" className="rounded-lg font-semibold">
                  <RefreshCw className="mr-1.5 h-4 w-4" /> Refresh
                </Button>
              </div>
            </div>
          </div>

          {/* Filter + table card */}
          <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
            <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-3 sm:flex-row sm:items-center dark:border-slate-800">
              <div className="relative w-full sm:max-w-md sm:flex-1">
                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <Input placeholder="Search…" className="h-9 rounded-lg pl-9 text-sm" />
              </div>
              <div className="w-full sm:w-44"><Select options={[]} placeholder="All" /></div>
            </div>

            <div className="custom-scrollbar overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                    <th className="px-4 py-2.5 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">Name</th>
                    <th className="px-4 py-2.5 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {/* loading → <Spinner/> row · error → retry row · empty → empty row · else rows */}
                </tbody>
              </table>
            </div>

            <div className="border-t border-slate-50 bg-slate-50/30 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/30">
              {/* <Pagination meta={meta} setPage={…} setLimit={…} /> */}
            </div>
          </div>

        </div>
      </div>
    </>
  );
}

ExampleList.layout = {
  breadcrumbs: [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Example', href: '/example' },
  ],
};
```

---

## Maintaining this document

- This guide and the code evolve together. When you establish a **new** cross-cutting
  pattern, document it here in the same PR.
- When you change a shared component's API, update its row/example here.
- Treat drift as a defect: if a shipped page diverges from this guide without a
  documented reason, reconcile it.
- Keep the [canonical references](#canonical-reference-implementations) pointing at the
  best current example of each pattern.
