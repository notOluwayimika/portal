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
    /** Server-computed. Only a `validated` batch may be offered for approval — never inferred here. */
    can_submit: boolean;
    submitted_at: string | null;
    created_at: string | null;
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
