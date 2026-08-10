<?php

namespace App\Finance\Http\Requests;

use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Models\FeeItem;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization is by route middleware (role:admin|super_admin) for the skeleton —
 * Finance Policies + maker-checker are Ph2/Ph3. Inline ->hasRole() is banned inside
 * app/Finance by the boundary lint, which is exactly why authz stays at the edge.
 *
 * The wire carries LINES, never a total (F6): amounts are integer minor units
 * (ADR 0037), never decimals, and the invoice total is derived server-side from
 * the lines. There is deliberately no `total` field to accept.
 */
class GenerateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollment_id' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            // A line carries EITHER a concrete amount_minor OR a percent (reductions
            // only). amount_minor is required unless a percent is given; no `min:1`,
            // because a reduction is legitimately negative — the SIGN rule is enforced
            // per-kind in the Action, so the edge only rejects the meaningless zero.
            'lines.*.amount_minor' => ['required_without:lines.*.percent', 'integer', 'not_in:0'],
            // 1..100: a percentage may not exceed the whole. The kind/positivity of the
            // resulting line is the Action's job; this just bounds the input.
            'lines.*.percent' => ['sometimes', 'integer', 'between:1,100', 'prohibits:lines.*.amount_minor'],
            'lines.*.kind' => ['sometimes', Rule::enum(InvoiceLineKind::class)],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            // regex mirrors Money's ISO-4217 invariant — a bad case/format is a 422 here, not Money::fromKobo's
            // InvalidArgumentException → 500 inside lineSpecs() one frame later (f293358 finish).
            'lines.*.currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            // Provenance of the price. `integer` alone was the whole rule until this commit, and that made
            // it the one wire field that escaped the principle stated two fields down and at
            // GenerateInvoice:274-276 — a client does not get to decide a fee ITEM's properties. An
            // arbitrary id could cite another School's item, or a DRAFT's.
            //
            // The draft half is not hypothetical bookkeeping: EditFeeScheduleDraft replaces a draft's items
            // WHOLESALE, and the argument that this is safe is "a draft's items cannot be cited by any
            // invoice" — true of every path in the tree (prefill resolves through
            // FeeScheduleLookup::activeFor, active only) and false of a hand-crafted request. Without this
            // rule that safety argument is breakable with one curl.
            //
            // NOT restricted to ACTIVE schedules, deliberately. Two legitimate paths bill from a
            // SUPERSEDED one: a void-and-rebill ('This enrollment already has an active invoice. Void it
            // before billing again.') where a publish was approved in between, and the plain race of a
            // bursar whose generate form was prefilled before an approval landed —
            // ApproveFeeScheduleChange:87 moves the previous active to `superseded` under them. Refusing
            // there would 422 an operator for a change they could not see. GenerateInvoice's own
            // discountability resolution already tolerates a superseded id (no status filter), so closing
            // that door here would contradict it. What is closed is the UNPUBLISHED proposal states —
            // draft and pending_approval — which no legitimate path emits and which are the states this
            // commit's wholesale replacement operates on.
            //
            // A CLOSURE RULE READING THROUGH THE SCOPED MODEL, not Rule::exists. Rule::exists queries the
            // TABLE, so FeeItem's SchoolScope would not apply and isolation would have to be hand-rolled
            // as a `where('school_id', …)` beside it — a second implementation of the one boundary this
            // codebase has. Reading through FeeItem::query() makes SchoolScope the isolation, which is
            // what Constitution §5 says it is everywhere else.
            //
            // A first attempt did use Rule::exists with a status subquery marked withoutGlobalScopes() so
            // the hand-rolled school term stayed independently observable. bin/ci-boundary-lint.php refused
            // it — finance-escape-hatches, §17.1 rule 4 — and it was right to: the escape hatch was there
            // to make a redundant check testable, which is a reason to delete the redundant check, not to
            // open the hatch. The lint bans the DB::table() form of the same evasion in the next token.
            'lines.*.fee_item_id' => [
                'sometimes',
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $item = FeeItem::query()->find((int) $value);

                    // Not found = does not exist, OR belongs to another School and SchoolScope hid it.
                    // The message does not distinguish the two, deliberately: telling a caller that an id
                    // they cannot see nevertheless exists is itself a leak.
                    if ($item === null) {
                        $fail('The selected :attribute is invalid.');

                        return;
                    }

                    if (in_array($item->schedule?->status, [
                        FeeScheduleStatus::Draft,
                        FeeScheduleStatus::PendingApproval,
                    ], true)) {
                        $fail('The selected :attribute belongs to a fee schedule that has not been published.');
                    }
                },
            ],
            // The discount policy a REDUCTION line cites (S1 3b). A LOOKUP id, not the wire's to validate
            // beyond shape — the DB reduction_guard is the authority (active + not approval-requiring + same
            // School). There is deliberately NO is_discountable rule: that is a fee-item property resolved
            // server-side in the Action, never a client claim (it would let a caller move the percentage base).
            'lines.*.discount_policy_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * Typed, validated line specs for the Action — the FormRequest is where the
     * wire becomes domain vocabulary, so the Action never sees raw request data.
     *
     * @return list<InvoiceLineSpec>
     */
    /**
     * Does this request carry any REDUCTION line (waiver/discount)? Drives the
     * `finance.invoice.reduction.apply` guard (S1 Part 0). A reduction is any line whose kind is not
     * `charge`; a bare `percent` line or a negative charge is malformed and the Action 422s it, so
     * reading the resolved specs' `isReduction()` is the exact question the permission gates.
     */
    public function hasReductionLine(): bool
    {
        return collect($this->lineSpecs())->contains(fn (InvoiceLineSpec $spec) => $spec->isReduction());
    }

    public function lineSpecs(): array
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->input('lines', []);

        return array_values(array_map(
            static fn (array $line) => new InvoiceLineSpec(
                description: (string) $line['description'],
                // A percentage line has no amount yet — it is resolved in the Action.
                amount: isset($line['percent'])
                    ? null
                    : Money::fromKobo(
                        (int) $line['amount_minor'],
                        (string) ($line['currency'] ?? Money::DEFAULT_CURRENCY),
                    ),
                feeItemId: isset($line['fee_item_id']) ? (int) $line['fee_item_id'] : null,
                kind: isset($line['kind'])
                    ? InvoiceLineKind::from((string) $line['kind'])
                    : InvoiceLineKind::Charge,
                note: isset($line['note']) ? (string) $line['note'] : null,
                percent: isset($line['percent']) ? (int) $line['percent'] : null,
                discountPolicyId: isset($line['discount_policy_id']) ? (int) $line['discount_policy_id'] : null,
                // isDiscountable is NOT read from the wire — the Action resolves it from the fee item.
            ),
            $lines,
        ));
    }
}
