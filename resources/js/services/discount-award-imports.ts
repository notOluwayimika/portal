import {
    report as reportRoute,
    show as showRoute,
    store as storeRoute,
    template as templateRoute,
} from '@/actions/App/Finance/Http/Controllers/DiscountAwardImportController';

/**
 * The BSS discount-award operator screen's transport — the shape `services/opening-balance-imports.ts`
 * already has, because this IS that import flow applied to a scholarship list: template → upload →
 * queued job → poll → report.
 *
 * EVERY URL COMES OFF WAYFINDER, never a hand-written string, for the reason the sibling module gives:
 * a literal is a second copy of a route, and this repository has already shipped a template that was
 * reachable and linked from nowhere. A route rename breaks the build rather than the screen.
 */

/** What became of ONE row — `App\Finance\Enums\DiscountAwardImportOutcome`, by value. */
export type DiscountAwardOutcome = 'awarded' | 'already_awarded' | 'rejected';

/**
 * One row of the bursar's own sheet, answered.
 *
 * THE FIRST FOUR FIELDS ARE WHAT THEY TYPED, VERBATIM, whitespace included — never anything read back
 * out of the database. A trailing space they cannot see on screen is exactly the thing they need shown
 * back to them, which is why `admission_number` is a string echoed rather than a student.
 */
export interface DiscountAwardImportRow {
    line_number: number;
    admission_number: string;
    discount_percentage: string;
    discount_applies_to: string;
    outcome: DiscountAwardOutcome;
    reason: string;
}

/** One import as `DiscountAwardImportController::serialize` reports it. */
export interface DiscountAwardImportRecord {
    uuid: string;
    file_name: string;
    /** queued/processing = in flight. completed/failed are the two terminal states. */
    status: 'queued' | 'processing' | 'completed' | 'failed';
    total_rows: number;
    processed_rows: number;
    awarded: number;
    /**
     * Students already on exactly the policy their row named. NOT A FAILURE — the BSS list is a
     * spreadsheet held outside the system and it will be re-uploaded, and a report that cries wolf on
     * the second run is a report nobody reads on the third.
     */
    already_awarded: number;
    rejected: number;
    /** A fact about the FILE or about US, in words — never a row defect. Null unless status is failed. */
    error: string | null;
    has_report: boolean;
    /**
     * The per-row outcomes, or null when there are none to read: an import still running, one that
     * failed before any row was read, or one that ran before the job persisted them. Null and `[]` are
     * different facts and the screen says different things about them.
     */
    rows: DiscountAwardImportRow[] | null;
    started_at: string | null;
    completed_at: string | null;
}

/** The two states the screen polls THROUGH. Stated positively so a failed import cannot spin forever. */
export const DISCOUNT_AWARD_IMPORT_TERMINAL: DiscountAwardImportRecord['status'][] =
    ['completed', 'failed'];

export const discountAwardImports = {
    templateUrl: (): string => templateRoute.url(),
    storeUrl: (): string => storeRoute.url(),
    statusUrl: (uuid: string): string => showRoute.url(uuid),
    reportUrl: (uuid: string): string => reportRoute.url(uuid),

    /**
     * The upload body. ONE FIELD: the file. There is no control total and no reference — this import
     * has no attestation to make (the percentages were approved through the discount-policy flow before
     * the sheet was written) and no idempotency key (a re-upload is safe by design and reports every
     * already-awarded row as such).
     */
    formData: (file: File): FormData => {
        const body = new FormData();
        body.append('file', file);

        return body;
    },
};
