<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Models\StudentAccount;
use App\Finance\Services\AccountReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The bursar's front door: a paginated index of student accounts for the active School,
 * with the two School-wide KPI totals (receivables owed to the school, credit the school
 * owes). Read-only — no Action, no transaction, no DB facade.
 *
 * The boundary discipline is the whole point of this controller. Finance owns the account
 * rows (balance, dates) but NOT the student's name/admission number — those are resolved
 * through the ACL port ({@see BillableEnrollmentProvider}) at this edge, never re-joined
 * from a Finance query. Search is the mirror image: the port turns a name term into the
 * matching student_ids and the read model filters its own column to them.
 */
class FinanceAccountController extends Controller
{
    private const PER_PAGE = 20;

    private const STATUSES = [
        AccountReadModel::STATUS_OUTSTANDING,
        AccountReadModel::STATUS_IN_CREDIT,
        AccountReadModel::STATUS_SETTLED,
    ];

    public function index(Request $request, AccountReadModel $accounts, BillableEnrollmentProvider $directory): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $sortDir = $request->query('sort') === 'asc' ? 'asc' : 'desc';
        // Page size is a VIEW concern only — the KPIs below are School-wide regardless, so
        // per_page never changes them. Clamped so a client can't request an unbounded page.
        $perPage = max(1, min((int) $request->query('per_page', self::PER_PAGE), 100));

        // Search crosses the boundary through the port: name/admission → matching ids, then
        // the read model filters its accounts to them. A blank search is null (no filter);
        // a search that matches nobody is an empty id list → an empty page, never "all".
        $studentIds = $search === '' ? null : $directory->matchingStudentIds($search);

        $page = $accounts->paginate($studentIds, $status, $sortDir, self::PER_PAGE);

        // One batch call resolves live display for exactly the ids on THIS page.
        $display = $directory->displayFor(
            $page->getCollection()->pluck('student_id')->map(fn ($id) => (int) $id)->all()
        );

        $rows = $page->getCollection()->map(function (StudentAccount $account) use ($display) {
            $info = $display[$account->student_id] ?? null;

            return [
                // uuid may be null for a soft-deleted student (its balance still counts in the
                // KPIs, but there is no linkable statement) — the UI renders a non-linked label.
                'student' => [
                    'uuid' => $info['uuid'] ?? null,
                    'name' => $info['name'] ?? ('Student #'.$account->student_id),
                    'admission_number' => $info['admission_number'] ?? null,
                ],
                'balance' => $account->balance,
                'available_credit' => $account->availableCredit(),
                'last_activity' => $account->updated_at?->toIso8601String(),
            ];
        })->all();

        return response()->json([
            'data' => $rows,
            'pagination' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            // School-wide totals — over ALL the School's accounts, unaffected by search/filter.
            'kpis' => $accounts->kpis(),
        ]);
    }
}
