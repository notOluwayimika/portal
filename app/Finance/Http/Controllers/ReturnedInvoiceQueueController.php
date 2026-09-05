<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FINANCE'S SIDE OF THE RETURN — the bills Internal Audit has sent back, and nothing else.
 *
 * ─── WHY THIS EXISTS, AND IT IS A DEFECT WE SHIPPED ─────────────────────────────────────────────
 *
 * Phase A gave Internal Audit a verb — return a bill with a reason — and gave Finance no way to see
 * that it had happened. Until this endpoint, the ONLY place a returned bill was visible anywhere in
 * the system was `counts.returned_to_finance` on the AUDITOR's own queue
 * (`InvoiceReviewController::pending()`), whose class docblock says so in as many words and names
 * this commit as the thing that would close it.
 *
 * So a write path shipped whose reader did not exist, and the state was visible only to the person
 * who created it. That is the shape this repository keeps calling a control with no enforcement,
 * one layer over: an act with no audience is an act nobody can act on.
 *
 * ─── READ SIDE ONLY, AND THAT IS A DECISION RATHER THAN A FIRST SLICE ───────────────────────────
 *
 * No correction, no resubmission, no state change. What Finance DOES with a returned bill is an
 * open question with Brookstone (asked 31 August, re-asked 5 September) and the answer changes the
 * schema — whether a correction clears `returned_at`, whether it stamps a second column, whether it
 * is a new bill entirely. Building a verb now would be building the wrong one confidently.
 *
 * ─── THE GATE IS THE MAKER'S ABILITY, AND NO NEW PERMISSION IS MINTED ───────────────────────────
 *
 * `finance.invoice.generate`. The seat that RAISES a bill is the seat that CORRECTS it, so the maker
 * ability already names exactly the right people: `admin` (RbacSeeder:248) and `accounts_officer`
 * (RbacSeeder:407), and nobody else.
 *
 * A NEW `finance.invoice.correct` WAS CONSIDERED AND REFUSED. It would create a grant nobody holds,
 * needing a seeder change and a convergence migration, in order to gate a read that the maker seat
 * already justifies — a permission whose only property on the day it ships is that it must be
 * granted to the two roles that already hold the one above it.
 *
 * EXPLICITLY NOT `finance.invoice.reject`. That is the AUDITOR's ability, and gating Finance's own
 * screen on it would hand the auditor the correction desk and lock the bursar out of it — the same
 * defect `routes/endpoints/internal-audit.php` caught when a route inherited the group's `approve`
 * and would have passed every test because one seat happened to hold both.
 *
 * ─── OLDEST RETURNED FIRST ──────────────────────────────────────────────────────────────────────
 *
 * `ORDER BY returned_at ASC`, and newest-first was refused rather than not considered. The bill that
 * has sat longest is the most urgent, and a newest-first queue buries it at the bottom of the last
 * page — which is the OMISSION failure the auditor's queue was itself designed against, reproduced
 * on the other side of the same act.
 *
 * ─── THE TWO NUMBERS, AND THE SECOND ONE IS THE INSTRUMENT ──────────────────────────────────────
 *
 * `counts.returned_total` is the size of the queue. `counts.oldest_waiting_days` is whether the
 * process is working at all, and it is the load-bearing one.
 *
 * A COUNT OF 4 LOOKS FINE WHETHER THOSE FOUR ARRIVED THIS MORNING OR THREE WEEKS AGO. A queue that
 * is worked drains and refills, and its size oscillates around a small number; a queue that has been
 * abandoned ALSO sits at a small number, permanently, and the two are indistinguishable from the
 * count alone. Age separates them in one glance, and it is the only field here that can: nothing
 * else on this screen changes when a returned bill is simply left.
 *
 * This is the Finance-side equivalent of what `pagination.total` does on the auditor's page, and the
 * argument is deliberately the same one. There, the silent failure is a bill nobody reviews, it
 * emits no activity row at any severity, throws nothing, and looks exactly like a quiet week — so
 * the only thing that will ever reveal it is a number growing. Here the silent failure is a bill
 * nobody CORRECTS, it emits nothing for the same reason, and the number that reveals it is an age
 * rather than a count.
 *
 * IT IS COMPUTED ON THE SERVER'S CLOCK, not sent as a timestamp for the browser to subtract. A
 * client-side age is measured against whatever the operator's machine believes the time is, so a
 * skewed laptop would render a reassuring "1 day" over a bill that has waited a month — and the one
 * field on this screen whose whole job is to be alarming would be the one field a wrong clock can
 * silence.
 *
 * IT IS DERIVED FROM THE WHOLE QUEUE, NOT FROM THE PAGE. `min('returned_at')` runs over every
 * returned bill in the school; reading it off `data[0]` would be correct on page 1 and wrong on
 * every other page, and wrong in the reassuring direction.
 *
 * ─── THE BILL BY ITS NUMBER, THE RETURNER BY THEIR NAME ─────────────────────────────────────────
 *
 * NO UUID AND NO `user#<id>` CROSSES THIS WIRE. Both are internal identifiers an operator cannot act
 * on: a bursar cannot look up `user#7` to ask what they meant, and cannot find a bill by a uuid that
 * appears on no document. The invoice number is what the bill is called everywhere else in the
 * system and on paper, and it is unique within a school, so it is also this payload's row identity —
 * the page needs no uuid and is therefore not given one.
 *
 * A BOUNDARY, STATED SO IT IS NOT READ AS A GENERAL FIX: this is about what a NEW screen renders. It
 * did NOT touch the uuids inside `ApproveInvoice`'s and `ReturnInvoice`'s refusal sentences, which
 * was left as its own commit — `fix/refusals-name-the-bill-and-the-person`, since landed. Those
 * sentences now carry `Invoice::displayNumber()` and a name from
 * `App\Finance\Services\ActorName` — named in prose rather than as a `{@see}` link, so Pint does
 * not add an import this class never calls — and
 * `tests/Arch/FinanceRefusalsNameNoInternalIdentifiersTest` is what stops the next one being
 * written the old way.
 *
 * IF THE RETURNER CANNOT BE RESOLVED, `returned_by` IS NULL AND THE SCREEN SAYS SO. It cannot happen
 * today — the pairing trigger from `2026_09_04_100000` refuses a `returned_at` without a
 * `returned_by_user_id`, and `returned_by_user_id` is a LOOKUP rather than an FK so nothing cascades
 * — but a deleted user row would otherwise render an empty cell, which reads as "nobody" rather than
 * as "we cannot tell you". Distinguishing those is the same rule the queue's own `failed`/`empty`
 * split follows.
 *
 * ─── THE REASON IN FULL ─────────────────────────────────────────────────────────────────────────
 *
 * `return_reason` is passed whole and untruncated. It is the ENTIRE payload of the act — the auditor
 * typed it precisely to say what Finance must correct — and a reason you have to click to read is a
 * reason Finance will not read. It is capped at `ReturnInvoice::REASON_MAX` characters at the point
 * it is written, which is what makes rendering it in full a bounded decision rather than an open one.
 */
class ReturnedInvoiceQueueController extends Controller
{
    /** Default page size — the auditor's queue's, so the two sides of one act page alike. */
    private const PER_PAGE = 25;

    /**
     * CLAMPED, never validated — `InvoiceReviewController`'s shape and its reason: "a client asking
     * for more should get the most it may have, not an error in the middle of a selection". 100 is
     * the top of the shared control's `LIMITS` (resources/js/components/pagination.tsx), so the
     * control cannot offer an option this server refuses.
     */
    private const MAX_PER_PAGE = 100;

    /** GET /api/v1/finance/invoices/returned */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', (string) self::PER_PAGE), self::MAX_PER_PAGE));

        // A FACTORY, NOT A SHARED BUILDER — `InvoiceReviewController`'s shape and its reason: each
        // call returns a fresh query, so the page and the two counts cannot contaminate one another
        // with a predicate meant for a sibling.
        //
        // `excludingVoid()` on every arm, for the reason the auditor's three counts give: an arm
        // that counted void bills while another did not would disagree for a reason that has nothing
        // to do with the return axis.
        //
        // BelongsToSchool scopes this to the active school; the explicit school_id is not a second
        // filter but the statement of intent the boundary lint asks for on a finance read.
        $returned = fn (): Builder => Invoice::query()
            ->where('school_id', ActiveSchool::getOrFail()->id)
            // STILL UNRELEASED — AND THIS IS A BELT, NOT A WORKING EXCLUSION. Measured while the
            // arms were written: the combination `reviewed_at IS NOT NULL AND returned_at IS NOT
            // NULL` cannot be reached through the real actions in EITHER direction. `ApproveInvoice`
            // refuses to release a returned bill (Phase A commit 3's approve-over-a-return ruling)
            // and `ReturnInvoice` refuses to return a released one. So nothing this filter could
            // exclude currently exists, and saying it "excludes bills that were released after being
            // returned" would be a justification for a state the system cannot produce.
            //
            // IT STAYS BECAUSE IT COSTS NOTHING AND SURVIVES A WRITER THAT DOES NOT CARRY THOSE TWO
            // GUARDS — an off-request job, a future resubmission path, a backfill. The claim is kept
            // honest by an arm: `ReturnedInvoiceQueueEndpointTest` asserts BOTH refusals, so if
            // either guard is relaxed the state becomes reachable and that arm reds rather than this
            // filter quietly starting to matter with nobody aware it had not.
            ->whereNull(Invoice::RELEASE_STAMP_COLUMN)
            ->whereNotNull('returned_at')
            ->excludingVoid();

        $page = $returned()->orderBy('returned_at')->paginate($perPage);

        // OVER THE WHOLE QUEUE, not the page — see the class docblock.
        $oldest = $returned()->min('returned_at');

        // ONE QUERY FOR THE WHOLE PAGE. A per-row lookup is 25 queries for a queue whose returners
        // are usually one or two people. NOT a `static` on a helper: this controller is resolved
        // per request today, but a static would survive into the next request under a long-running
        // worker and serve one school's names to another — an isolation bug that no test on a
        // request-per-test suite could ever observe.
        $names = $this->returnerNames($page->getCollection());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Invoice $invoice) => [
                // THE NUMBER IS THE ROW IDENTITY. No uuid is sent, deliberately.
                'number' => $invoice->number,
                // THE PAYER BY NAME, for the reason the class docblock gives about uuids: a
                // `student_id` is an internal identifier a bursar cannot act on either. This is the
                // snapshot `billed_to_name` taken at billing — the name every DOCUMENT for this
                // bill already renders — so the queue and the paperwork agree by construction.
                'billed_to' => $invoice->billed_to_name,
                'kind' => $invoice->kind->value,
                'total' => $invoice->total,
                'issued_at' => $invoice->created_at->toIso8601String(),
                'returned_at' => $invoice->returned_at?->toIso8601String(),
                'returned_by' => $names[$invoice->returned_by_user_id] ?? null,
                'return_reason' => $invoice->return_reason,
            ])->all(),
            'pagination' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'counts' => [
                // THE SIZE.
                'returned_total' => $returned()->count(),
                // THE INSTRUMENT. NULL when the queue is empty — there is no oldest, and 0 would
                // claim there is one that arrived today.
                'oldest_waiting_days' => $oldest === null
                    ? null
                    : (int) Carbon::parse($oldest)->diffInDays(Carbon::now()),
            ],
        ]);
    }

    /**
     * Display names for the page's returners, keyed by user id. A missing key means the id resolved
     * to no user row.
     *
     * NULL RATHER THAN A BLANK, and the caller renders the difference. It cannot happen today — the
     * pairing trigger from `2026_09_04_100000` refuses a `returned_at` without a
     * `returned_by_user_id` — but `returned_by_user_id` is a LOOKUP and not an FK, so nothing stops
     * a user row being removed underneath one. An empty cell would read as "nobody returned this";
     * the absence of a name is "we cannot tell you who", and those are different sentences.
     *
     * @param  Collection<int, Invoice>  $rows
     * @return array<int, string>
     */
    private function returnerNames($rows): array
    {
        $ids = $rows->pluck('returned_by_user_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => trim($user->first_name.' '.$user->last_name)])
            ->all();
    }
}
