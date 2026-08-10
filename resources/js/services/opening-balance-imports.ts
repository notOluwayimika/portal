import {
    index as indexBatches,
    report as reportUrl,
    show as showBatch,
    store as storeBatch,
    submit as submitBatch,
    template as templateRoute,
} from '@/actions/App/Finance/Http/Controllers/OpeningBalanceBatchController';
import type { Money } from '@/types/finance';

/**
 * The operator screen's transport (§9 step 5b-iii / spec §2's U12b) — the shape
 * `services/guardian-imports.ts` already has, because this IS the guardian import flow applied to a
 * WCBS extract: template → upload → queued job → poll → report.
 *
 * EVERY URL COMES OFF WAYFINDER, never a hand-written string. `guardian-imports.ts` writes its paths
 * as literals and that is the older pattern; a literal is a second copy of a route, and this feature
 * has already paid for a second copy of a route once (the template that was reachable and linked from
 * nowhere). The generated module is derived from the router itself, so a route rename breaks the
 * build rather than the screen.
 */

/** One batch as the maker's endpoints report it — see OpeningBalanceBatchController::serialize. */
export interface OpeningBalanceBatchRecord {
    uuid: string;
    batch_reference: string;
    filename: string;
    /** draft = queued or running. The job moves it to validated or rejected; a checker moves it on. */
    status: 'draft' | 'validated' | 'submitted' | 'rejected' | 'posted';
    row_count: number;
    file_row_count: number;
    control_total: Money | null;
    cutover_date: string | null;
    findings: OpeningBalanceFinding[];
    /**
     * What the batch says its own file means, computed server-side from the staged rows on every
     * read. NOT a finding — findings are defects, and this is a correct batch stating its reading so
     * a human can disagree with it before approval.
     *
     * It exists because no arithmetic can catch an INVERTED sign convention: the control total is the
     * sum of the same column, so an inverted file matches its own total perfectly, and a posted batch
     * can never be un-posted. Counts and batch aggregates only — the privacy rule below applies here
     * too, and this object holds no student.
     *
     * NULL WHILE THE BATCH IS `draft` — no row is staged yet, and a summary computed over an empty
     * set states "the two sides cancel exactly … refuse it if that is wrong" about a file nobody has
     * read. The server decides this, not the page; the type is nullable so the check cannot be
     * forgotten here.
     */
    interpretation: OpeningBalanceInterpretation | null;
    /** Server-computed. Only a `validated` batch may be offered for approval — never inferred here. */
    can_submit: boolean;
    submitted_at: string | null;
    created_at: string | null;
}

/**
 * The batch's own reading of its file, in the words the sign convention was agreed in.
 *
 * Every student is classified by their NET position across all of their rows, which is what a bursar
 * means by "in credit". `credit_total` and `arrears_total` are POSITIVE magnitudes; `net` carries the
 * only sign, and it is the school's position.
 *
 * THE NET IS A STATEMENT ABOUT THE ACCOUNT BALANCE, NOT ABOUT WHAT POSTS. An earlier version of this
 * comment said the classification matched the posting because the posting nets a student's credits.
 * It does not net credits against CHARGES — it works per row and skips only a row that is itself
 * zero — which is what `offsetting_students` below exists to report.
 */
export interface OpeningBalanceInterpretation {
    students: number;
    credit_students: number;
    credit_total: Money;
    arrears_students: number;
    arrears_total: Money;
    /** Students whose EVERY row is zero. These genuinely post nothing. */
    square_students: number;
    /**
     * Students whose rows CANCEL to a zero net — e.g. +5,000 Tuition against −5,000 Bus. They are
     * not square: the posting works per row and skips only a row that is itself zero, so both a
     * charge and a migrated payment are written for them. Reported apart from `square_students`
     * because collapsing the two is how the summary came to say "will post nothing" about a student
     * for whom two ledger rows were about to be written.
     */
    offsetting_students: number;
    net: Money;
    /** The convention verbatim, from the server constant — never re-worded on this side. */
    convention: string;
    /** The claim, as one paragraph the operator either agrees with or refuses. */
    sentence: string;
}

/** A batch- or row-level finding. `code` is stable and machine-readable; `message` is for a human. */
export interface OpeningBalanceFinding {
    code: string;
    message: string;
}

/**
 * A rejected staged row, as the status payload carries it.
 *
 * THE PRIVACY DISCIPLINE IS VISIBLE IN THIS TYPE, and that is deliberate. Line number, admission
 * number, findings — and no name, no per-student total. The import command's report has carried that
 * rule since §9 step 4a; the screen is the same report reaching a wider audience, which is exactly
 * when the discipline starts to matter. A field added here is a field on every operator's screen.
 */
export interface OpeningBalanceRejectedRow {
    line_number: number;
    admission_number: string | null;
    findings: OpeningBalanceFinding[];
}

export interface OpeningBalanceBatchDetail extends OpeningBalanceBatchRecord {
    rejected_rows: OpeningBalanceRejectedRow[];
    /** True when the list above was cut. Announced, never silent — see the controller's constant. */
    rejected_rows_truncated: boolean;
}

export interface OpeningBalanceUpload {
    file: File;
    /** Naira, signed, as typed. Parsed server-side by Money::fromNaira — never by JavaScript. */
    controlTotal: string;
    closingTermId: number;
    asAt: string;
    batchReference: string;
}

export const openingBalanceImports = {
    /** The template the PLATFORM issues (R13). Linked from the screen — see the page's docblock. */
    templateUrl: (): string => templateRoute.url(),

    reportUrl: (uuid: string): string => reportUrl.url(uuid),

    storeUrl: (): string => storeBatch.url(),

    statusUrl: (uuid: string): string => showBatch.url(uuid),

    listUrl: (): string => indexBatches.url(),

    submitUrl: (uuid: string): string => submitBatch.url(uuid),

    /**
     * The upload, as multipart. The control total travels as the STRING the operator typed: it is
     * their attestation, and turning it into a JavaScript number on the way would put a float in
     * front of the one figure in this feature that no code derived.
     */
    formData(upload: OpeningBalanceUpload): FormData {
        const form = new FormData();

        form.append('file', upload.file);
        form.append('control_total', upload.controlTotal);
        form.append('closing_term', String(upload.closingTermId));
        form.append('as_at', upload.asAt);
        form.append('batch_reference', upload.batchReference);

        return form;
    },
};
