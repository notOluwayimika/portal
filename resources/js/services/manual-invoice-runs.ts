import {
    show as showRun,
    store as storeRun,
} from '@/actions/App/Finance/Http/Controllers/ManualInvoiceRunController';
import { index as rosterIndex } from '@/actions/App/Finance/Http/Controllers/ManualInvoiceRunStudentController';
import { manualInvoiceRun as runPage } from '@/routes/admin/finance';

/**
 * BULK MANUAL INVOICING — the selection screen's and the run report's transport.
 *
 * EVERY URL COMES OFF WAYFINDER, never a hand-written string, for the reason
 * `services/bulk-invoice-runs.ts` records: a literal is a second copy of a route, and the generated
 * module is derived from the router itself, so a rename breaks the build rather than the screen.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THERE IS NO "ALL MATCHING" SHAPE ON THIS WIRE, AND THAT IS THE POINT
 *
 * `store` takes `student_ids` — an array the caller names in full — and nothing else identifies who
 * is billed. There is no filter payload and no flag, so the guardians defect
 * (`guardians/bulk-action-bar.tsx` renders "Select all N matching", the browser holds one page of
 * ids, the action runs on those) is UNREPRESENTABLE here rather than merely avoided. In an export
 * that defect produces a short spreadsheet; here it would bill 25 families and report 240.
 *
 * If "invoice all N matching" is ever wanted, brief §1 rules it is resolved SERVER-SIDE from the
 * filter payload — a different endpoint, not a longer array assembled by this file.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ROSTER IS A PAGE, NEVER A SET
 *
 * `rosterUrl` asks for one page of students to tick. It has no "give me every matching id" mode and
 * must not grow one: an endpoint returning the whole id list hands the client exactly what the rule
 * above forbids it to hold, and the control that spends it appears the following week.
 */

/** A filter option as the page route hands it over. UUIDs — the roster matches on uuid, not id. */
export interface ClassLevelOption {
    id: string;
    name: string;
}

export interface ArmOption {
    id: string;
    label: string;
}

/** Which arms exist under which class level, so the arm select can narrow to the chosen level. */
export interface ClassLevelArmOption {
    class_level: string;
    arm: string;
    label: string;
}

export interface ScholarshipOption {
    uuid: string;
    name: string;
}

/**
 * The `scholarship` filter value meaning "students on no scheme at all", as opposed to the empty
 * value, which means "do not filter" and returns sponsored and unsponsored students alike.
 *
 * Mirrors `App\Services\StudentIndexFilters::NO_SCHOLARSHIP`. The server decides what it means; the
 * screen only has to send the same string. It cannot collide with a scheme because the other branch
 * matches a uuid.
 */
export const NO_SCHOLARSHIP = 'none';

/**
 * One student on the roster.
 *
 * `uuid`, `name` and `admission_number` are NULLABLE together, and never independently: they are
 * what the ACL port's `displayFor()` resolved, so all three are absent exactly when the port could
 * not display the student at all. Such a row is still rendered — dropping it would be the silent
 * omission this feature's report exists to end — and it cannot be ticked, because a null uuid is
 * not something `store` can be given.
 */
export interface RosterStudent {
    uuid: string | null;
    name: string | null;
    admission_number: string | null;
    /** The students index's own `student_class` accessor, so one class has one spelling. */
    class_label: string | null;
    scholarship: string | null;
}

/**
 * The six keys the shared `Pagination` component reads.
 *
 * THE TWO URLS ARE LOAD-BEARING, and they are typed rather than optional for that reason: that
 * component disables Prev and Next on `!meta.prev_page_url` / `!meta.next_page_url`, so a feed
 * without them renders both arrows dead on a multi-page roster while the numbered buttons still
 * work. The browser drive found exactly that; no assertion about this endpoint could see it.
 */
export interface RosterPagination {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface RosterPage {
    data: RosterStudent[];
    pagination: RosterPagination;
}

export interface RosterQuery {
    search?: string;
    class_level?: string;
    arm?: string;
    scholarship?: string;
    page: number;
    per_page: number;
}

/** A line every selected student is charged. One set of lines for the whole run — brief §5. */
export interface RunLineDraft {
    description: string;
    /**
     * INTEGER MINOR UNITS, or null while the field is empty or holds something the strict parser
     * will not commit to. `MoneyInput` owns the naira↔minor mapping (lib/format.ts is the only file
     * allowed to do it), so nothing on this screen performs money arithmetic except `sumMinor`.
     *
     * The server's rule is `integer|min:1` — every line is a CHARGE. A negative amount would be a
     * reduction with no policy to authorise it, which is a credit note's job.
     */
    amount_minor: number | null;
    /** REQUIRED on every line — S11. There is no default and no "unspecified". */
    bank_account_id: string;
}

export type ManualRunStatus = 'pending' | 'running' | 'completed' | 'failed';

/** The two states a run can still leave. Everything else is terminal and the poll stops. */
export const MANUAL_RUN_IN_FLIGHT: ManualRunStatus[] = ['pending', 'running'];

export type ManualRunOutcome = 'billed' | 'failed' | 'unplaceable' | 'claimed';

export interface ManualRunRowStudent {
    uuid: string;
    name: string;
    admission_number: string | null;
}

export interface ManualRunRow {
    uuid: string;
    outcome: ManualRunOutcome;
    /** Null when the port cannot display the student — the row is rendered anyway. */
    student: ManualRunRowStudent | null;
    enrollment_uuid: string | null;
    invoice_uuid: string | null;
    reason: string | null;
}

/**
 * One outcome's rows. `truncated` is announced rather than silent: a cut list that looks complete is
 * a false all-clear, and on the unplaceable bucket that is the one place this report can still hide
 * a name — from row 201
 * (docs/handoff/tickets/the-manual-run-report-truncates-at-200-and-the-unplaceable-can-hide.md).
 */
export interface ManualRunBucket {
    total: number;
    truncated: boolean;
    rows: ManualRunRow[];
}

export interface ManualRunLine {
    description: string;
    amount: { amount_minor: number; currency: string };
    bank_account: { uuid: string; label: string } | null;
    sort_order: number;
}

/**
 * The run's own alarm, computed SERVER-SIDE from two independent sources — the targets table (the
 * bursar's own number) and the rows table. Never re-derived here: a client that added these up
 * itself would be a third opinion, and the one on screen.
 *
 * `balances` IS NULL WHILE THE RUN IS NOT TERMINAL. Mid-run a shortfall is the normal state, so
 * reporting `false` there fires the alarm on every healthy run and teaches a bursar to ignore the
 * one signal standing where a second signature would otherwise be.
 */
export interface ManualRunReconciliation {
    accounted_for: number;
    balances: boolean | null;
    recorded_matches_rows: boolean | null;
}

export interface ManualInvoiceRunDetail {
    uuid: string;
    status: ManualRunStatus;
    started_by_name?: string | null;
    started_at?: string | null;
    finished_at?: string | null;
    created_at?: string | null;
    failure_reason?: string | null;
    /**
     * COUNTED FROM THE TARGETS TABLE — the list the bursar submitted — and NOT from the run's own
     * `target_count` column, which is the job's tally written at the end of the walk. It exists from
     * the moment the run is created, which is why a run still in flight can already state what was
     * selected.
     */
    target_count: number;
    counts: {
        billed: number;
        failed: number;
        unplaceable: number;
        /** SEPARATELY, and never a term of the equality. A claimed row is unaccounted-for. */
        claimed: number;
    };
    reconciliation: ManualRunReconciliation;
    lines: ManualRunLine[];
    buckets: Record<ManualRunOutcome, ManualRunBucket>;
}

export interface ManualInvoiceRunCreated {
    uuid: string;
}

export const manualInvoiceRuns = {
    /** One PAGE of students to tick. Never a set of ids — see the module docblock. */
    rosterUrl: (query: RosterQuery): string =>
        rosterIndex.url({
            query: {
                search: query.search || undefined,
                class_level: query.class_level || undefined,
                arm: query.arm || undefined,
                scholarship: query.scholarship || undefined,
                page: query.page,
                per_page: query.per_page,
            },
        }),

    storeUrl: (): string => storeRun.url(),

    showUrl: (uuid: string): string => showRun.url(uuid),

    /** The per-run REPORT PAGE — where submit lands, and the link the in-flight refusal names. */
    pageUrl: (uuid: string): string => runPage.url(uuid),
};
