import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, Plus, Receipt, Search, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { MoneyInput } from '@/components/finance/money-input';
import { selectableBankAccounts } from '@/components/finance/new-invoice-modal';
import { Pagination } from '@/components/pagination';
import Select from '@/components/ui/base-dropdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import { formatNaira, sumMinor } from '@/lib/format';
import {
    manualInvoiceRuns,
    NO_SCHOLARSHIP,
} from '@/services/manual-invoice-runs';
import type {
    ArmOption,
    ClassLevelArmOption,
    ClassLevelOption,
    ManualInvoiceRunCreated,
    RosterPage,
    RosterPagination,
    RosterStudent,
    RunLineDraft,
    ScholarshipOption,
} from '@/services/manual-invoice-runs';
import type { SelectableBankAccount } from '@/types/finance';

/**
 * BULK MANUAL INVOICING — the bursar picks their own list of students and charges every one of them
 * the same lines, as one run.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * IT BORROWS THE STUDENTS INDEX. IT DOES NOT BORROW THE GUARDIANS ONE, AND THAT DECIDES WHO IS BILLED
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `guardians/bulk-action-bar.tsx` renders "Select all N matching" and sets a flag, while the browser
 * holds only the ids the server sent for the CURRENT PAGE — so every action behind that bar runs on
 * those. The operator is told 240 and gets 25, the control confirms 240 back to them, and nothing
 * errors. In an export that produces a short spreadsheet. HERE IT WOULD BILL 25 FAMILIES AND REPORT
 * 240, on a path where each wrong invoice is undone by its own void request and a second person's
 * approval.
 *
 * So nothing in this file imports from that one, and the three properties that make the STUDENTS
 * index correct are inherited whole:
 *
 *   1. `selectedIds` is a Set of student uuids, and the footer acts on EXACTLY those ids.
 *   2. THE COUNT LIVES IN THE BUTTON LABEL, so the control's scope and its label cannot disagree.
 *   3. THERE IS NO SELECT-ALL-MATCHING FLAG, no matching-total count, and no client-side "all". The
 *      wire has no shape for one either — `store` takes `student_ids` and nothing else identifies
 *      who is billed — so the defect is unrepresentable rather than merely avoided.
 *
 *      The two identifiers are named here in PROSE and never spelled, because
 *      FinanceNavCoverageTest forbids the literals in this file outright: an absolute ban is a
 *      stronger guard than a count that permits one "legitimate" mention, and there is no
 *      legitimate use of either name on this screen. Writing them into this comment is what broke
 *      the first version of that arm.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * SELECTION IS PAGE-SCOPED, IT IS SAID ON SCREEN, AND THAT IS THE SCREEN'S CENTRAL COMPROMISE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Ticks are CLEARED on every filter change and every page change — the students index's own rule
 * (`pages/admin/students/index.tsx`, the effect that resets `selectedIds`), inherited deliberately.
 * Carrying them across a navigation is the alternative and it is refused: it rebuilds precisely the
 * condition the guardians defect lives in, a count in a footer that no longer describes what the
 * button would act on.
 *
 * THE HONEST HALF OF THAT IS SAYING SO, AT THE MOMENT IT MATTERS. A bursar billing ninety-one
 * students meets this the first time their filter returns more than one page, and losing forty ticks
 * to a page turn with no warning is the failure this whole feature was shaped to avoid. So whenever
 * the filtered result spans more than one page, this screen states in words that ticks are
 * page-scoped, and it escalates the wording the moment there are ticks to lose. The page-size
 * control is in the pagination bar directly beneath, and the banner names its ceiling — 100 — so a
 * cohort that fits on one page can be put on one page, and a cohort that cannot is told so rather
 * than discovering it forty ticks in.
 *
 * A SERVER-SIDE "EVERYONE MATCHING THIS FILTER" SCOPE IS THE REAL ANSWER FOR A COHORT ABOVE 100, and
 * it is NOT this commit. Brief §1 sanctions the shape — resolved server-side from the filter
 * payload, never from a client id list — and `POST /v1/finance/manual-invoice-runs` takes explicit
 * ids only. Building the client half first would mean assembling that list in the browser, which is
 * the thing the rule forbids.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE CONFIRMATION IS THE ONLY HUMAN CHECKPOINT THAT EXISTS
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Brookstone ruled on 30 August 2026 that this issues DIRECTLY — no maker-checker, no second
 * signature. So the dialog before submit is the last thing between a selection and real charges, and
 * it is written as a STATEMENT OF WHAT IS ABOUT TO HAPPEN rather than as "are you sure?": how many
 * students, what each of them will be charged, how many lines, and every destination account by
 * name. A bare confirmation is a dialog people learn to click through, which is how the one that
 * matters gets clicked through.
 *
 * IT IS A COURTESY, NEVER A CONTROL. The ability on the route, the School scope on the models and
 * `StoreManualInvoiceRunRequest`'s isolation refusal all hold against a client that never renders it.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THE SERVER REFUSES, AND HOW IT IS RENDERED
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * THE ONE-ACTIVE-RUN REFUSAL IS A 422, NOT A 409. `finance_manual_invoice_runs` carries a generated
 * `active_run_key` under a UNIQUE index, so a School's second non-terminal run is refused by the
 * engine with 1062 — which, untranslated, reaches a client as a 409 reading "Duplicate entry
 * detected." `ManualInvoiceRunController::translateActiveRunCollision()` catches it and throws a
 * ValidationException keyed `run`, so what actually arrives is a 422 whose message NAMES the run in
 * flight and hands over its uuid. Both are handled below: the 422 is the live path, and the 409 is
 * rendered rather than swallowed in case the translation is ever bypassed.
 *
 * PER-LINE ERRORS ARE PUT ON THE LINE. `lines.N.bank_account_id` is the 422 key S11 made possible,
 * and a destination error shown at the top of a form is one the operator has to count rows to
 * locate.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * AFTER SUBMIT, THE OPERATOR LANDS ON THE RUN REPORT — never on a toast
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The report is this feature's only oversight: the bursar's own target count against
 * billed / failed / unplaceable, with the unplaceable NAMED by admission number. A toast says none
 * of that, and there is no second signature anywhere that would.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * FEEDS AND PROPS
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The ROSTER is fetched from `GET /api/v1/finance/manual-invoice-runs/students` — a Finance-side
 * feed that had to be built for this screen, because `/api/students` carries `student.view` and the
 * bursar seat does not hold it (see ManualInvoiceRunStudentController).
 *
 * BANK ACCOUNTS are fetched from `GET /api/v1/finance/bank-accounts`, gated on
 * `finance.bank-account.manage`, which both roles holding this page's ability also hold —
 * checked in tests/Feature/Finance/ManualInvoiceRunPageTest.php, not assumed.
 *
 * CLASS LEVELS, ARMS AND SCHOLARSHIPS are PROPS: the only API listing them sits under
 * `academic_setup.manage`, an ability this seat does not hold. Same wall, same answer, as
 * fee-schedules and the opening-balance operator screen.
 *
 * A FAILED FETCH IS NOT AN EMPTY CATALOG, and the two are never allowed to share a sentence — an
 * empty account list rendered after a network error tells a bursar their school has configured no
 * destination, which is a different and possibly false statement.
 *
 * NO MONEY ARITHMETIC HERE. `sumMinor` and `formatNaira` are the sanctioned ops
 * (`bin/ci-money-lint.php` bans the rest), and the per-student total is the only sum on the page.
 */

interface Props {
    class_levels: ClassLevelOption[];
    arms: ArmOption[];
    class_level_arms: ClassLevelArmOption[];
    scholarships: ScholarshipOption[];
}

const EMPTY_LINE: RunLineDraft = {
    description: '',
    amount_minor: null,
    bank_account_id: '',
};

/**
 * The largest page the roster will serve, mirrored here so the banner can NAME the ceiling.
 *
 * It is `ManualInvoiceRunStudentController::MAX_PER_PAGE` and the pagination control's own top
 * option, and it is written as a literal rather than derived from the options array — a number
 * derived from the control it describes could only ever restate the control, and what the operator
 * needs told is the SERVER's limit.
 */
const MAX_PER_PAGE = 100;

export default function ManualInvoiceRunsIndex({
    class_levels: classLevels,
    arms,
    class_level_arms: classLevelArms,
    scholarships,
}: Props) {
    const [search, setSearch] = useState('');
    const [classLevel, setClassLevel] = useState('');
    const [arm, setArm] = useState('');
    const [scholarship, setScholarship] = useState('');
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(25);

    const [students, setStudents] = useState<RosterStudent[]>([]);
    const [loading, setLoading] = useState(true);
    const [rosterFailed, setRosterFailed] = useState(false);
    // The two URLs are part of the shape, not extras: the shared Pagination control disables Prev
    // and Next on their absence, so an initial state without them renders dead arrows on first paint.
    const [pagination, setPagination] = useState<RosterPagination>({
        total: 0,
        per_page: 25,
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
    });

    // ── SELECTION IS PAGE-SCOPED, DELIBERATELY ──────────────────────────────
    // STUDENT uuids, because that is what `store` takes and what the table row keys on. There is no
    // "select all matching" — see the file docblock, and do not add one here.
    const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());

    const [lines, setLines] = useState<RunLineDraft[]>([{ ...EMPTY_LINE }]);

    const [accounts, setAccounts] = useState<SelectableBankAccount[]>([]);
    const [accountsLoading, setAccountsLoading] = useState(true);
    const [accountsFailed, setAccountsFailed] = useState(false);

    const [confirming, setConfirming] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    /** Field errors from a 422, keyed exactly as the server keys them. */
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );

    /*
     * ── THE ACTION BAR IS PINNED TO THE VIEWPORT AND ALIGNED TO THE CONTENT COLUMN ──────────────
     *
     * `fixed inset-x-0` — what the students index's bar does — positions against the VIEWPORT, so
     * the bar spans the whole window and lies across the sidebar. `position: sticky` inside the
     * column is the obvious fix and DOES NOT WORK HERE, measured rather than assumed: the shell's
     * `<main data-slot="sidebar-inset">` computes `overflow: auto`, which makes it the bar's
     * scrollport, and it is sized by its content (`min-h-svh`, grows) so it never scrolls — the
     * document does. A sticky element whose scrollport never scrolls never engages, and the bar
     * simply sat below the fold.
     *
     * So the bar stays `fixed` and its horizontal box is COPIED FROM THE CONTENT COLUMN each time
     * that column's geometry changes. Nothing here knows the sidebar's width, its collapsed state
     * or its breakpoint: it tracks the column, and the column is already laid out correctly at
     * every size. That is what makes this responsive by construction rather than by a constant
     * somebody has to keep in step.
     */
    const columnRef = useRef<HTMLDivElement | null>(null);
    const [barBox, setBarBox] = useState<{
        left: number;
        width: number;
    } | null>(null);

    useEffect(() => {
        const el = columnRef.current;

        if (el === null) {
            return;
        }

        const measure = () => {
            const rect = el.getBoundingClientRect();
            setBarBox({ left: rect.left, width: rect.width });
        };

        measure();

        // The column moves for two different reasons — the viewport resizing and the sidebar
        // collapsing — and only the observer sees the second one.
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        window.addEventListener('resize', measure);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', measure);
        };
    }, []);

    const loadRoster = useCallback(async () => {
        setLoading(true);
        setRosterFailed(false);

        try {
            const { data } = await axios.get<RosterPage>(
                manualInvoiceRuns.rosterUrl({
                    search,
                    class_level: classLevel,
                    arm,
                    scholarship,
                    page,
                    per_page: limit,
                }),
            );
            setStudents(data.data ?? []);
            setPagination(data.pagination);
        } catch {
            setStudents([]);
            setRosterFailed(true);
        } finally {
            setLoading(false);
        }
    }, [search, classLevel, arm, scholarship, page, limit]);

    useEffect(() => {
        /*
         * SELECTION IS CLEARED ON EVERY FILTER OR PAGE CHANGE, and that is what makes "page-scoped"
         * TRUE rather than merely intended. Carrying ticks across a navigation would leave
         * `selectedIds` holding students no longer rendered, so the footer's count would say 65
         * while the operator can see 25 — a button whose label disagrees with what it would do,
         * which is the guardians defect rebuilt from the other end. Clearing is the honest half of
         * having no "select all matching".
         *
         * The banner above the table is the other half: an operator who is about to lose ticks is
         * told so BEFORE they turn the page, not after.
         */
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setSelectedIds(new Set());

        void loadRoster();
    }, [loadRoster]);

    /**
     * The destination catalog. `BankAccountController::index` takes no query parameters and returns
     * `{bank_accounts: [...]}` in display order, so `selectableBankAccounts` — shared with the
     * single-invoice modal rather than re-written — is the only narrowing: it drops deactivated
     * accounts, which is CONVENIENCE and not enforcement (nothing server-side refuses billing to a
     * retired account; see that function's docblock).
     */
    const loadAccounts = useCallback(async () => {
        setAccountsLoading(true);
        setAccountsFailed(false);

        try {
            const { data } = await axios.get<{
                bank_accounts: SelectableBankAccount[];
            }>('/api/v1/finance/bank-accounts');
            setAccounts(selectableBankAccounts(data.bank_accounts ?? []));
        } catch {
            setAccounts([]);
            setAccountsFailed(true);
        } finally {
            setAccountsLoading(false);
        }
    }, []);

    useEffect(() => {
        // A second effect rather than a branch of the first: the accounts do not depend on the
        // filters, and re-fetching them on every keystroke of the search box would be a request per
        // character.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void loadAccounts();
    }, [loadAccounts]);

    // When a class level is chosen, offer only the arms that exist for it; otherwise every arm, so
    // an arm-only filter still works. The students index's own narrowing.
    const availableArms = classLevel
        ? classLevelArms
              .filter((cla) => cla.class_level === classLevel)
              .map((cla) => ({ id: cla.arm, label: cla.label }))
        : arms;

    const toggleOne = (uuid: string) =>
        setSelectedIds((prev) => {
            const next = new Set(prev);

            if (next.has(uuid)) {
                next.delete(uuid);
            } else {
                next.add(uuid);
            }

            return next;
        });

    /*
     * The ids on THIS page that can be ticked at all. A row the ACL port could not display carries a
     * null uuid — it is rendered so the operator can see it exists, and it is not selectable,
     * because a null uuid is not something `store` can be given.
     */
    const pageIds = students
        .map((student) => student.uuid)
        .filter((uuid): uuid is string => uuid !== null);

    const allOnPageSelected =
        pageIds.length > 0 && pageIds.every((uuid) => selectedIds.has(uuid));

    const togglePage = () =>
        setSelectedIds((prev) => {
            const next = new Set(prev);

            if (allOnPageSelected) {
                pageIds.forEach((uuid) => next.delete(uuid));
            } else {
                pageIds.forEach((uuid) => next.add(uuid));
            }

            return next;
        });

    const selectedCount = selectedIds.size;

    const setLine = (index: number, patch: Partial<RunLineDraft>) =>
        setLines((prev) =>
            prev.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );

    const addLine = () => setLines((prev) => [...prev, { ...EMPTY_LINE }]);

    const removeLine = (index: number) =>
        setLines((prev) =>
            prev.length === 1 ? prev : prev.filter((_, i) => i !== index),
        );

    /**
     * Whether every line is complete. CONVENIENCE, NOT ENFORCEMENT: `StoreManualInvoiceRunRequest`
     * requires a description, an `amount_minor` of at least 1 and a `bank_account_id` on EVERY line,
     * and `finance_invoice_lines_destination_guard` stands behind the destination. This only decides
     * whether the confirm dialog opens, so an operator meets a missing destination here rather than
     * as a 422 after reading a confirmation that named it.
     */
    const linesComplete = lines.every(
        (line) =>
            line.description.trim() !== '' &&
            line.amount_minor !== null &&
            line.amount_minor >= 1 &&
            line.bank_account_id !== '',
    );

    // The one sum on this page. `sumMinor` is a sanctioned money op; nothing here does its own.
    const perStudentTotalMinor = useMemo(
        () => sumMinor(lines.map((line) => line.amount_minor ?? 0)),
        [lines],
    );

    const destinationLabels = useMemo(
        () =>
            lines.map(
                (line) =>
                    accounts.find(
                        (account) => account.id === line.bank_account_id,
                    )?.label ?? null,
            ),
        [lines, accounts],
    );

    /**
     * The in-flight refusal, in the SERVER'S OWN WORDS. Never re-worded here: a second wording of a
     * refusal is a second thing that can disagree with the server about why a run cannot happen.
     */
    const runRefusal = fieldErrors.run?.[0] ?? null;

    /**
     * The uuid of the run already under way, if the refusal named one — so the operator can go and
     * READ it rather than meeting a dead end. There is no index of past runs yet, so this link is
     * currently the only way back to a run whose uuid was not kept.
     *
     * IT IS A PARSE OF A SENTENCE, and that is stated rather than hidden. The server sends the uuid
     * inside prose (`ManualInvoiceRunController::refuseAsInFlight`), so a reworded message makes
     * this regex miss — and the failure is SAFE: the sentence itself still renders, which is exactly
     * what the operator had before this link existed. The honest fix is the server returning the
     * uuid as its own field, and that is a change to an endpoint this commit does not touch.
     */
    const inFlightUuid = useMemo(() => {
        const match = runRefusal?.match(
            /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i,
        );

        return match?.[0] ?? null;
    }, [runRefusal]);

    const clearFilters = () => {
        setSearch('');
        setClassLevel('');
        setArm('');
        setScholarship('');
        setPage(1);
    };

    const handleClassLevelChange = (next: string) => {
        setClassLevel(next);

        // Drop the chosen arm if it is not offered for the new class level.
        if (
            next &&
            arm &&
            !classLevelArms.some(
                (cla) => cla.class_level === next && cla.arm === arm,
            )
        ) {
            setArm('');
        }

        setPage(1);
    };

    const submit = async () => {
        setSubmitting(true);
        setFieldErrors({});

        try {
            const { data } = await axios.post<ManualInvoiceRunCreated>(
                manualInvoiceRuns.storeUrl(),
                {
                    student_ids: Array.from(selectedIds),
                    lines: lines.map((line) => ({
                        description: line.description.trim(),
                        amount_minor: line.amount_minor,
                        bank_account_id: line.bank_account_id,
                    })),
                },
            );

            setConfirming(false);
            // STRAIGHT TO THE REPORT. It is the only screen that can say what became of each
            // student, and with no second signature anywhere it is the only oversight this act gets.
            router.visit(manualInvoiceRuns.pageUrl(data.uuid));
        } catch (err: unknown) {
            setConfirming(false);

            if (axios.isAxiosError(err) && err.response?.status === 422) {
                // RENDERED, NOT SWALLOWED — including the in-flight refusal, which arrives here
                // keyed `run` because the controller translates the database's 1062.
                setFieldErrors(err.response.data?.errors ?? {});
                toast.error('Nothing has been billed. Read the message below.');
            } else if (
                axios.isAxiosError(err) &&
                err.response?.status === 409
            ) {
                /*
                 * THE UNTRANSLATED 1062. The controller's catch is keyed on the index name, so a
                 * duplicate raised by a DIFFERENT index — or a translation that stops running —
                 * reaches here as a bare 409 reading "Duplicate entry detected.", which names
                 * nothing. It is rendered verbatim rather than dressed up as an in-flight run: a
                 * wrong diagnosis stated confidently is worse than an unhelpful true one.
                 */
                setFieldErrors({
                    run: [
                        (err.response.data?.message as string | undefined) ??
                            'The database refused this run as a duplicate.',
                    ],
                });
                toast.error('Nothing has been billed. Read the message below.');
            } else {
                toast.error('Could not start the run.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    const filtersActive =
        search !== '' || classLevel !== '' || arm !== '' || scholarship !== '';

    /*
     * MORE THAN ONE PAGE OF MATCHES. This is the exact condition under which page-scoped ticking
     * becomes a thing the operator can be hurt by, so it is the condition the warning keys on.
     */
    const spansPages = pagination.last_page > 1;
    const fitsOnOnePage =
        pagination.total > 0 && pagination.total <= MAX_PER_PAGE;

    return (
        <>
            <Head title="Bulk manual invoicing" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-32 sm:px-6 lg:px-8 dark:bg-background">
                <div ref={columnRef} className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero Card ─────────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex items-center gap-4">
                            <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                <Receipt className="h-6 w-6 text-indigo-600" />
                            </div>
                            <div>
                                <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                    Bulk manual invoicing
                                </h1>
                                <p className="text-xs text-slate-500">
                                    Charge your own list of students the same
                                    lines, as one run. There is no approval step
                                    — the invoices are raised as soon as you
                                    start it.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* ── The refusal, when there is one ────────────────────── */}
                    {runRefusal !== null && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/20">
                            <div className="flex items-start gap-2">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-700 dark:text-amber-400" />
                                <div className="space-y-2">
                                    <p className="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                        {runRefusal}
                                    </p>
                                    {inFlightUuid !== null && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.visit(
                                                    manualInvoiceRuns.pageUrl(
                                                        inFlightUuid,
                                                    ),
                                                )
                                            }
                                        >
                                            Open that run’s report
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {fieldErrors.student_ids !== undefined && (
                        <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-800 dark:border-red-900/40 dark:bg-red-950/20 dark:text-red-300">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{fieldErrors.student_ids[0]}</span>
                        </div>
                    )}

                    {/* ── 1 · Who ───────────────────────────────────────────── */}
                    <div className="overflow-hidden rounded-xl border-none bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <div className="border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                            <h2 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                1 · Who is being charged
                            </h2>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Filter, then tick the students on this page.
                            </p>
                            {/* MEASURED ON THE DRIVE, NOT GUESSED AT. Three roster rows showed a
                                blank Class and one of them billed — a student can hold a current
                                enrolment whose curriculum names no class level, so `student_class`
                                is empty while the run places them perfectly well. The two questions
                                are decided by different reads (this column by the academic record,
                                billability by the ACL port at run time), and a bursar reading the
                                blank as "cannot be billed" would drop students who can be. Said
                                here rather than answered with a flag on the row: a flag computed at
                                pick time is a second answer to a question the run decides later,
                                and it is the one the operator would trust. */}
                            <p className="mt-1 text-xs text-slate-500">
                                A blank{' '}
                                <span className="font-semibold">Class</span>{' '}
                                does not mean a student cannot be billed.
                                Whether anyone can be is decided when the run
                                executes, and the run report names by admission
                                number anyone it could not place.
                            </p>
                        </div>

                        {/* Filters */}
                        <div className="border-b border-slate-100 dark:border-slate-800">
                            <div className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                                <div className="relative w-full sm:max-w-md sm:flex-1">
                                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                    <Input
                                        placeholder="Search by name or admission number…"
                                        className="h-9 rounded-lg border-slate-200 bg-white pl-9 text-sm dark:border-slate-700 dark:bg-slate-900"
                                        value={search}
                                        onChange={(e) => {
                                            setSearch(e.target.value);
                                            setPage(1);
                                        }}
                                    />
                                </div>

                                <div className="w-full sm:w-40">
                                    <Select
                                        value={classLevel}
                                        onChange={(val) =>
                                            handleClassLevelChange(
                                                val ? String(val) : '',
                                            )
                                        }
                                        placeholder="All class levels"
                                        options={[
                                            {
                                                label: 'All class levels',
                                                value: '',
                                            },
                                            ...classLevels.map((level) => ({
                                                label: level.name,
                                                value: level.id,
                                            })),
                                        ]}
                                    />
                                </div>

                                <div className="w-full sm:w-32">
                                    <Select
                                        value={arm}
                                        onChange={(val) => {
                                            setArm(val ? String(val) : '');
                                            setPage(1);
                                        }}
                                        placeholder="All arms"
                                        options={[
                                            { label: 'All arms', value: '' },
                                            ...availableArms.map((a) => ({
                                                label: a.label,
                                                value: a.id,
                                            })),
                                        ]}
                                    />
                                </div>

                                <div className="w-full sm:w-44">
                                    <Select
                                        value={scholarship}
                                        onChange={(val) => {
                                            setScholarship(
                                                val ? String(val) : '',
                                            );
                                            setPage(1);
                                        }}
                                        placeholder="All scholarships"
                                        options={[
                                            // Empty = do not filter, so sponsored and unsponsored
                                            // students alike. THIS FEATURE EXISTS PARTLY TO BILL
                                            // SPONSORED STUDENTS — nothing on this path excludes
                                            // them, and nothing here should start.
                                            {
                                                label: 'All scholarships',
                                                value: '',
                                            },
                                            {
                                                label: 'No scholarship',
                                                value: NO_SCHOLARSHIP,
                                            },
                                            ...scholarships.map((scheme) => ({
                                                label: scheme.name,
                                                value: scheme.uuid,
                                            })),
                                        ]}
                                    />
                                </div>

                                <div className="flex items-center gap-2 sm:ml-auto">
                                    <span className="hidden text-xs font-medium text-slate-500 sm:inline">
                                        Showing{' '}
                                        <span className="font-bold text-slate-700 dark:text-slate-200">
                                            {students.length}
                                        </span>{' '}
                                        of{' '}
                                        <span className="font-bold text-slate-700 dark:text-slate-200">
                                            {pagination.total}
                                        </span>
                                    </span>
                                    {filtersActive && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={clearFilters}
                                        >
                                            <X className="mr-1 h-3.5 w-3.5" />
                                            Clear
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* ── THE PAGE-SCOPED WARNING ────────────────────────
                            Rendered whenever the filtered result spans more than one page — which is
                            exactly when a page turn can cost the operator ticks — and worded harder
                            once there are ticks to lose. This is the honest half of clearing the
                            selection on navigation; without it a bursar pages away and finds forty
                            ticks gone with nothing having said it would happen. */}
                        {spansPages && (
                            <div
                                data-testid="page-scoped-warning"
                                className="border-b border-amber-100 bg-amber-50 px-5 py-3 dark:border-amber-900/40 dark:bg-amber-950/20"
                            >
                                <div className="flex items-start gap-2">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-700 dark:text-amber-400" />
                                    <div className="space-y-1 text-xs text-amber-800 dark:text-amber-300">
                                        <p className="font-semibold">
                                            {selectedCount > 0
                                                ? `Ticks apply to this page only. Turning the page or changing a filter will clear the ${String(selectedCount)} you have ticked.`
                                                : 'Ticks apply to this page only. Turning the page or changing a filter clears them.'}
                                        </p>
                                        <p>
                                            {pagination.total} student(s) match
                                            these filters, across{' '}
                                            {pagination.last_page} pages of{' '}
                                            {pagination.per_page}.{' '}
                                            {fitsOnOnePage
                                                ? 'They fit on one page — use the page-size control below to show them all, then tick.'
                                                : `The largest page available is ${String(MAX_PER_PAGE)}, so this filter cannot be put on one page. Narrow it further and run each part as its own run.`}
                                        </p>
                                    </div>
                                    {fitsOnOnePage && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="ml-auto shrink-0"
                                            onClick={() => {
                                                setLimit(MAX_PER_PAGE);
                                                setPage(1);
                                            }}
                                        >
                                            Show all {pagination.total} on one
                                            page
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Roster */}
                        <div className="custom-scrollbar overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-900/30">
                                        <th className="w-8 px-3 py-2">
                                            {/* Selects THIS PAGE, and its label says so. There is
                                                no cross-page select-all here by design. */}
                                            <input
                                                type="checkbox"
                                                aria-label="Select all students on this page"
                                                checked={allOnPageSelected}
                                                onChange={togglePage}
                                                className="size-3.5 cursor-pointer rounded border-slate-300"
                                            />
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Admission #
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Name
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Class
                                        </th>
                                        <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                            Scholarship
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {loading ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="py-10 text-center"
                                            >
                                                <Spinner className="mx-auto" />
                                            </td>
                                        </tr>
                                    ) : rosterFailed ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="py-10 text-center"
                                            >
                                                {/* A FAILED FETCH IS NOT AN EMPTY SCHOOL. */}
                                                <p className="text-xs font-semibold text-red-600">
                                                    Could not load the student
                                                    list.
                                                </p>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    This says nothing about who
                                                    is enrolled — only that this
                                                    page could not read it.
                                                </p>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="mt-3"
                                                    onClick={() =>
                                                        void loadRoster()
                                                    }
                                                >
                                                    Retry
                                                </Button>
                                            </td>
                                        </tr>
                                    ) : students.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="py-10 text-center text-xs text-muted-foreground"
                                            >
                                                No students match these filters.
                                            </td>
                                        </tr>
                                    ) : (
                                        students.map((student, index) => (
                                            <tr
                                                key={
                                                    student.uuid ??
                                                    `undisplayable-${String(index)}`
                                                }
                                                data-testid="roster-row"
                                                className="hover:bg-slate-50/60 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-3 py-2">
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Select ${student.name ?? 'this student'}`}
                                                        checked={
                                                            student.uuid !==
                                                                null &&
                                                            selectedIds.has(
                                                                student.uuid,
                                                            )
                                                        }
                                                        // A row the port could not display cannot be
                                                        // ticked: `store` has no id to be given.
                                                        disabled={
                                                            student.uuid ===
                                                            null
                                                        }
                                                        onChange={() =>
                                                            student.uuid !==
                                                                null &&
                                                            toggleOne(
                                                                student.uuid,
                                                            )
                                                        }
                                                        className="size-3.5 cursor-pointer rounded border-slate-300 disabled:cursor-not-allowed disabled:opacity-40"
                                                    />
                                                </td>
                                                <td className="px-3 py-2 font-mono text-slate-700 dark:text-slate-200">
                                                    {student.admission_number ??
                                                        '—'}
                                                </td>
                                                <td className="px-3 py-2 text-slate-700 dark:text-slate-200">
                                                    {student.name ?? (
                                                        <span className="text-slate-400">
                                                            Not in the live
                                                            directory — cannot
                                                            be billed here
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-slate-600 dark:text-slate-300">
                                                    {student.class_label ?? '—'}
                                                </td>
                                                <td className="px-3 py-2 text-slate-600 dark:text-slate-300">
                                                    {student.scholarship ?? '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="border-t border-slate-50 bg-slate-50/30 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/30">
                            <Pagination
                                meta={pagination}
                                setPage={setPage}
                                setLimit={setLimit}
                            />
                        </div>
                    </div>

                    {/* ── 2 · What ──────────────────────────────────────────── */}
                    <div className="rounded-xl bg-white p-5 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:bg-card">
                        <h2 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                            2 · What they are charged
                        </h2>
                        <p className="mt-0.5 text-xs text-slate-500">
                            One set of lines for the whole run — every student
                            you ticked is charged all of them, at full price.
                            {/* Stated because it is the thing a bursar is most likely to assume
                                otherwise: a scholarship covers termly fees and does not touch a
                                mid-term charge (Brookstone, 29 August). */}{' '}
                            A scholarship does not reduce a charge raised here.
                        </p>

                        {accountsFailed && (
                            <p className="mt-3 rounded-lg bg-red-50 p-3 text-xs font-semibold text-red-800 dark:bg-red-950/20 dark:text-red-300">
                                Could not load the accounts a charge is paid
                                into. This is not a statement that your school
                                has none — retry before deciding anything.
                            </p>
                        )}

                        {!accountsFailed &&
                            !accountsLoading &&
                            accounts.length === 0 && (
                                <p className="mt-3 rounded-lg bg-amber-50 p-3 text-xs font-semibold text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                                    This school has no active bank account, so
                                    no charge can name a destination and no run
                                    can be started. Add one under Bank accounts
                                    first.
                                </p>
                            )}

                        <div className="mt-4 space-y-3">
                            {lines.map((line, index) => {
                                const destinationError =
                                    fieldErrors[
                                        `lines.${String(index)}.bank_account_id`
                                    ]?.[0] ?? null;
                                const descriptionError =
                                    fieldErrors[
                                        `lines.${String(index)}.description`
                                    ]?.[0] ?? null;
                                const amountError =
                                    fieldErrors[
                                        `lines.${String(index)}.amount_minor`
                                    ]?.[0] ?? null;

                                return (
                                    <div
                                        key={index}
                                        data-testid="run-line"
                                        className="rounded-lg border border-slate-100 p-3 dark:border-slate-800"
                                    >
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                            <div className="flex-1">
                                                <label
                                                    className="text-[10px] font-bold tracking-wide text-slate-400 uppercase"
                                                    htmlFor={`mir-description-${String(index)}`}
                                                >
                                                    Description
                                                </label>
                                                <Input
                                                    id={`mir-description-${String(index)}`}
                                                    className="mt-1 h-9"
                                                    placeholder="e.g. Excursion — Term 1"
                                                    maxLength={255}
                                                    value={line.description}
                                                    onChange={(e) =>
                                                        setLine(index, {
                                                            description:
                                                                e.target.value,
                                                        })
                                                    }
                                                />
                                                {descriptionError !== null && (
                                                    <p className="mt-1 text-[11px] font-semibold text-red-600">
                                                        {descriptionError}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="w-full sm:w-44">
                                                <label
                                                    className="text-[10px] font-bold tracking-wide text-slate-400 uppercase"
                                                    htmlFor={`mir-amount-${String(index)}`}
                                                >
                                                    Amount (each)
                                                </label>
                                                <MoneyInput
                                                    id={`mir-amount-${String(index)}`}
                                                    className="mt-1 h-9"
                                                    value={line.amount_minor}
                                                    onChange={(amountMinor) =>
                                                        setLine(index, {
                                                            amount_minor:
                                                                amountMinor,
                                                        })
                                                    }
                                                />
                                                {amountError !== null && (
                                                    <p className="mt-1 text-[11px] font-semibold text-red-600">
                                                        {amountError}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="w-full sm:w-56">
                                                <label
                                                    className="text-[10px] font-bold tracking-wide text-slate-400 uppercase"
                                                    htmlFor={`mir-destination-${String(index)}`}
                                                >
                                                    Paid into
                                                </label>
                                                <div className="mt-1">
                                                    {/* REQUIRED, with no default. S11 made a
                                                        destination mandatory on every charge line
                                                        and finance_invoice_lines_destination_guard
                                                        is the authority; a pre-selected account
                                                        would be a destination nobody chose. */}
                                                    <Select
                                                        value={
                                                            line.bank_account_id
                                                        }
                                                        onChange={(val) =>
                                                            setLine(index, {
                                                                bank_account_id:
                                                                    val
                                                                        ? String(
                                                                              val,
                                                                          )
                                                                        : '',
                                                            })
                                                        }
                                                        placeholder="Choose an account…"
                                                        options={[
                                                            {
                                                                label: 'Choose an account…',
                                                                value: '',
                                                            },
                                                            ...accounts.map(
                                                                (account) => ({
                                                                    label: `${account.label} · ${account.bank_name}`,
                                                                    value: account.id,
                                                                }),
                                                            ),
                                                        ]}
                                                    />
                                                </div>
                                                {destinationError !== null && (
                                                    <p className="mt-1 text-[11px] font-semibold text-red-600">
                                                        {destinationError}
                                                    </p>
                                                )}
                                            </div>

                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-slate-400 hover:text-red-600"
                                                onClick={() =>
                                                    removeLine(index)
                                                }
                                                disabled={lines.length === 1}
                                                title={
                                                    lines.length === 1
                                                        ? 'A run needs at least one line'
                                                        : undefined
                                                }
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={addLine}
                            >
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                Add line
                            </Button>
                            <span className="text-xs text-slate-500">
                                Each student is charged{' '}
                                <span className="font-bold text-slate-800 dark:text-slate-100">
                                    {formatNaira({
                                        amount_minor: perStudentTotalMinor,
                                        currency: 'NGN',
                                    })}
                                </span>
                            </span>
                        </div>
                    </div>

                    {/* ── The footer: it acts on EXACTLY the ticked ids ───────
                        PINNED TO THE VIEWPORT, ALIGNED TO THIS COLUMN. See the measurement effect
                        above for why it is neither `fixed inset-x-0` (spans the window, lies across
                        the sidebar) nor `sticky` (its scrollport never scrolls, so it never
                        engages). `left` and `width` are the content column's own, so the bar can
                        never cross into the sidebar and needs no breakpoint of its own.

                        UNTIL THE FIRST MEASUREMENT it falls back to the full width, which is the
                        pre-existing behaviour rather than a broken one — and the effect measures on
                        mount, so that state does not survive a paint. */}
                    {selectedCount > 0 && (
                        <div
                            style={
                                barBox === null
                                    ? undefined
                                    : { left: barBox.left, width: barBox.width }
                            }
                            className="fixed bottom-4 z-40 rounded-xl border border-slate-200 bg-background/95 px-4 py-3 shadow-lg backdrop-blur max-sm:inset-x-4 max-sm:w-auto sm:px-6 dark:border-slate-800"
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="text-sm font-medium">
                                    {selectedCount} selected on this page
                                </span>

                                <button
                                    type="button"
                                    className="text-xs text-muted-foreground underline-offset-2 hover:underline"
                                    onClick={() => setSelectedIds(new Set())}
                                >
                                    Clear
                                </button>

                                {!linesComplete && (
                                    <span className="text-xs text-amber-600 dark:text-amber-500">
                                        Every line needs a description, an
                                        amount and an account before a run can
                                        start.
                                    </span>
                                )}

                                <div className="ml-auto flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        onClick={() => setConfirming(true)}
                                        disabled={!linesComplete || submitting}
                                    >
                                        <Receipt className="mr-1.5 h-3.5 w-3.5" />
                                        {/* THE COUNT LIVES IN THE LABEL: this button's scope is the
                                    selection, and saying so in the control is what makes scope and
                                    label unable to disagree. */}
                                        Invoice selected ({selectedCount})
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* ── THE CONFIRMATION — the last control that exists on this path ── */}
            <Modal
                isOpen={confirming}
                onClose={() => setConfirming(false)}
                title="Start this run? It cannot be undone."
                size="lg"
                footer={
                    <div className="flex justify-end gap-3">
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(false)}
                            disabled={submitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={() => void submit()}
                            disabled={submitting || !linesComplete}
                        >
                            {submitting ? (
                                <Spinner className="mr-2 h-4 w-4" />
                            ) : (
                                <Receipt className="mr-2 h-4 w-4" />
                            )}
                            {/* A SENTENCE ABOUT WHAT PRESSING IT WILL DO, naming the same count the
                                footer names and the same one the wire carries. */}
                            {submitting
                                ? 'Starting…'
                                : `Bill ${String(selectedCount)} student(s)`}
                        </Button>
                    </div>
                }
            >
                {/* NOT "ARE YOU SURE?" — a STATEMENT OF WHAT IS ABOUT TO HAPPEN. Brookstone ruled
                    this issues directly, so there is no second signature after this dialog: what it
                    fails to say, nobody says. */}
                <div className="space-y-3 text-sm text-slate-600 dark:text-slate-300">
                    <p>
                        This raises{' '}
                        <span className="font-bold text-slate-900 dark:text-white">
                            {String(selectedCount)}
                        </span>{' '}
                        invoice(s) — one for each student you ticked — and
                        charges every one of them{' '}
                        <span className="font-bold text-slate-900 dark:text-white">
                            {formatNaira({
                                amount_minor: perStudentTotalMinor,
                                currency: 'NGN',
                            })}
                        </span>{' '}
                        across{' '}
                        <span className="font-bold text-slate-900 dark:text-white">
                            {String(lines.length)}
                        </span>{' '}
                        line(s).
                    </p>

                    {/* EVERY LINE AND EVERY DESTINATION, BY NAME. Where the money is sent is the
                        thing a confirmation must not summarise: one wrong account is one wrong
                        account per student. */}
                    <div className="rounded-lg border border-slate-100 dark:border-slate-800">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50/50 text-left dark:border-slate-800 dark:bg-slate-900/30">
                                    <th className="px-3 py-2 font-semibold text-slate-500">
                                        Description
                                    </th>
                                    <th className="px-3 py-2 font-semibold text-slate-500">
                                        Each
                                    </th>
                                    <th className="px-3 py-2 font-semibold text-slate-500">
                                        Paid into
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((line, index) => (
                                    <tr
                                        key={index}
                                        className="border-b border-slate-50 last:border-0 dark:border-slate-800/60"
                                    >
                                        <td className="px-3 py-2 text-slate-700 dark:text-slate-200">
                                            {line.description}
                                        </td>
                                        <td className="px-3 py-2 font-semibold text-slate-800 tabular-nums dark:text-slate-100">
                                            {formatNaira({
                                                amount_minor:
                                                    line.amount_minor ?? 0,
                                                currency: 'NGN',
                                            })}
                                        </td>
                                        <td className="px-3 py-2 text-slate-600 dark:text-slate-300">
                                            {destinationLabels[index] ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                        There is no approval step and no undo. Reversing one of
                        these invoices takes a void request and a second
                        person’s approval, one at a time, for every student it
                        billed. Starting the same list twice bills everyone on
                        it twice.
                    </p>

                    <p className="text-xs text-slate-500">
                        A student with no current enrolment cannot be billed and
                        will be listed on the run report by admission number.
                    </p>
                </div>
            </Modal>
        </>
    );
}

ManualInvoiceRunsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        {
            title: 'Bulk manual invoicing',
            href: '/finance/manual-invoice-runs',
        },
    ],
};
