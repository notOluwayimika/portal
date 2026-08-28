import {
    index as indexRuns,
    preview as previewRun,
    show as showRun,
    store as storeRun,
} from '@/actions/App/Finance/Http/Controllers/BulkInvoiceRunController';
import { bulkInvoiceRun as runPage } from '@/routes/admin/finance';

/**
 * The bulk-invoice-run screens' transport (U6 commit 4) — the shape
 * `services/opening-balance-imports.ts` already has, because this IS that flow with a form instead of
 * a file: coordinates → preview → queued job → poll → report.
 *
 * EVERY URL COMES OFF WAYFINDER, never a hand-written string, for the reason that file records: a
 * literal is a second copy of a route, and the generated module is derived from the router itself, so
 * a route rename breaks the build rather than the screen.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * EVERY COUNT ON THIS WIRE IS `number | null`, AND THE NULL IS THE POINT.
 *
 * The nine figures are NULL until the run reconciles. A `pending` run has not been picked up, a
 * `running` run is mid-cohort, and a run stopped by a per-run condition never reached the
 * reconciliation at all — `writeFailure()` names three columns and none of them is a count. Typing
 * them as `number` and letting the server's null arrive anyway is how "this run has not said" renders
 * as "this run says zero": the §26 state-collapse defect, which this project has shipped five times.
 *
 * The type is what makes the check unforgettable, so the nulls are declared rather than smoothed
 * away, and `has_figures` is the SERVER's answer to whether any of them may be rendered — never
 * re-derived here from nine separate null tests.
 */

/** A term as the page route hands it over. The API listing terms is not reachable by this seat. */
export interface TermOption {
    id: number;
    label: string;
}

export interface ClassLevelOption {
    id: number;
    name: string;
}

/** The version a run pinned — or would pin. DISPLAY, never a choice: `active` admits exactly one. */
export interface RunFeeSchedule {
    uuid: string;
    label: string;
    status: string;
}

/**
 * THE RUN'S OWN ACCOUNTING plus the one school-wide residual, exactly as the table stores them.
 *
 * `outside_coordinates` IS NOT "STUDENTS MISSED", and mis-wording it is the single most likely defect
 * on this screen. It is the billable students this run did not ENUMERATE because they are priced at
 * other coordinates — on a single-level run in a seven-level school that is roughly six-sevenths of
 * the roster, on EVERY successful run. It is scope, not a miss, and it under-reports by a known
 * amount besides: student-less episodes collapse to one group in SQL, so it indicates that such
 * episodes exist without counting how many.
 */
export interface RunCounts {
    cohort: number | null;
    billed: number | null;
    already_billed: number | null;
    failed: number | null;
    /**
     * Cohort members the run DELIBERATELY did not bill: their scholarship is a sponsored scheme, so
     * an outside organisation pays for them, once a session, by hand and off platform. NOT a
     * failure and NOT a miss — a term of the cohort equality, counted from the rows like the three
     * above it.
     */
    sponsored: number | null;
    unplaceable_listed: number | null;
    unplaceable: number | null;
    billable: number | null;
    outside_coordinates: number | null;
}

/**
 * The run's own alarm, server-computed from the figures beside it. Null when there are no figures.
 *
 * Each equality has a persisted-rows side and a walked-list side, so either can genuinely fail — a
 * per-student row that could not be written is a per-student fault the run survives, and the
 * imbalance is the only thing that says so. There is no flag column by design.
 */
export interface RunReconciliation {
    /** billed + already_billed + failed + sponsored === cohort */
    cohort_balances: boolean;
    /** unplaceable === unplaceable_listed */
    unplaceable_balances: boolean;
}

export type RunStatus = 'pending' | 'running' | 'completed' | 'failed';

/** The two states a run can still leave. Everything else is terminal and the poll stops. */
export const RUN_IN_FLIGHT: RunStatus[] = ['pending', 'running'];

export interface BulkInvoiceRunRecord {
    uuid: string;
    status: RunStatus;
    term_id: number;
    class_level_id: number;
    term_label: string | null;
    class_level_label: string | null;
    fee_schedule: RunFeeSchedule | null;
    started_by_name: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
    /** PER-RUN only. A student who could not be billed carries their reason on their own row. */
    failure_reason: string | null;
    /**
     * The server's answer to "has this run reported its figures". NOT `status === 'completed'`: the
     * nobody-billed rule writes all nine counts and THEN marks the run `failed`, so one of the five
     * routes into `failed` is fully counted and its figures are the whole diagnosis.
     */
    has_figures: boolean;
    counts: RunCounts;
    reconciliation: RunReconciliation | null;
}

export type RunOutcome =
    | 'billed'
    | 'already_billed'
    | 'failed'
    | 'unplaceable'
    | 'sponsored';

/**
 * The student a row is about, resolved through the ACL port — Finance owns no name and no student
 * uuid. NULL for two different reasons, and the screen says which it cannot tell apart: the episode
 * has no `student_id` at all (schema-legal), or the id no longer resolves. The row renders either
 * way, carrying its enrollment uuid.
 */
export interface RunRowStudent {
    uuid: string;
    name: string;
    admission_number: string | null;
}

export interface BulkInvoiceRunRow {
    uuid: string;
    enrollment_uuid: string;
    outcome: RunOutcome;
    /** Non-null ONLY on `failed`. Never the run's own failure_reason, which is a different fact. */
    reason: string | null;
    student: RunRowStudent | null;
}

/**
 * One outcome's rows. `total` is counted from the ROWS THAT EXIST, which is a different fact from
 * the run's `counts` — while a run is still going the counts are null and these are what has been
 * written so far, and on a finished run a disagreement between the two IS the reconciliation alarm.
 */
export interface RunBucket {
    total: number;
    /** Announced, never silent — a cut list that looks complete is a false all-clear. */
    truncated: boolean;
    rows: BulkInvoiceRunRow[];
}

export interface BulkInvoiceRunDetail extends BulkInvoiceRunRecord {
    buckets: Record<RunOutcome, RunBucket>;
}

/**
 * What a run WOULD do. Creates nothing and dispatches nothing.
 *
 * `refusal` is the sentence the run would fail with, in the job's and the mapper's OWN words — the
 * no-active-schedule sentence verbatim from ProcessBulkInvoiceRun, or the BusinessRuleException
 * message from FeeScheduleLineMapper. It is never re-worded on this side; a second wording of a
 * refusal is a second thing that can disagree with the job about why a run cannot happen.
 */
export interface BulkInvoiceRunPreview {
    term_id: number;
    class_level_id: number;
    term_label: string | null;
    class_level_label: string | null;
    schedule: (RunFeeSchedule & { mandatory_item_count: number | null }) | null;
    refusal: string | null;
    cohort_size: number;
    /**
     * How many of that cohort are on a SPONSORED scheme — an outside organisation pays for them,
     * off platform — so the run records them and does not bill them. Reported rather than silently
     * netted off `would_bill`: a bursar who reads "520 to bill · 91 sponsored, billed by hand" can
     * sanity-check the figure, where one who reads only "520" has to trust it.
     */
    sponsored: number;
    /**
     * How many of that cohort already carry an active scheduled invoice and would not be re-billed.
     * DISJOINT FROM `sponsored`, in the job's own order: the run settles the scheme first and never
     * asks the invoice question of a sponsored student.
     */
    already_billed: number;
    /**
     * How many invoices the run would actually raise — cohort minus the two buckets above, computed
     * SERVER-SIDE from the same predicates the job applies. The confirm button is a sentence about
     * this number and no other. The screen used to subtract `cohort_size - already_billed` itself,
     * which overstated by every sponsored student in the cohort.
     */
    would_bill: number;
}

export const bulkInvoiceRuns = {
    previewUrl: (termId: number, classLevelId: number): string =>
        previewRun.url({
            query: { term_id: termId, class_level_id: classLevelId },
        }),

    listUrl: (): string => indexRuns.url(),

    storeUrl: (): string => storeRun.url(),

    showUrl: (uuid: string): string => showRun.url(uuid),

    /** The per-run REPORT PAGE — the link every row on the list carries. */
    pageUrl: (uuid: string): string => runPage.url(uuid),
};
