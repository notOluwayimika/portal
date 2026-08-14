<?php

namespace App\Finance\Http\Requests;

use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\FeeItem;
use App\Support\Money;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    /**
     * Resolved specs for THIS request, computed once. See {@see self::lineSpecs()} for why the
     * single resolution is a correctness property and not only a query-count one.
     *
     * @var list<InvoiceLineSpec>|null
     */
    private ?array $lineSpecs = null;

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
            // GenerateInvoice:280-282 — a client does not get to decide a fee ITEM's properties. An
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
            // codebase has. Reading through FeeItem::query() lets SchoolScope do the scoping, which is
            // what Constitution §5 says it is everywhere else.
            //
            // "SchoolScope IS the isolation" IS TRUE OF EVERY PRINCIPAL BUT ONE, and the exception is
            // stated here rather than left implied. A super_admin who has selected NO School reaches this
            // rule: SetSchoolContext:51 only redirects a non-super_admin without context. ActiveSchool::id()
            // is then null, and SchoolScope's null-context branch throws only for models listed in
            // config/rbac.php `fail_closed_models` — ten transactional models, and neither FeeItem nor
            // DiscountPolicy is among them. So for that principal this query runs UNSCOPED and a foreign
            // School's uuid resolves to its real integer id.
            //
            // WHAT REFUSES THE WRITE IN THAT CASE IS GenerateInvoice, NOT THIS RULE: with no context there
            // is no school_id to raise an invoice under, and the Action returns 422 "No active School
            // context: an invoice cannot be raised." Confirmed over HTTP, and pinned by InvoiceWireIdsTest's
            // super_admin arm, which also asserts that the edge did NOT refuse — so if a fail_closed_models
            // entry ever closes this, that arm fails and this paragraph is what needs rewriting.
            //
            // A first attempt did use Rule::exists with a status subquery marked withoutGlobalScopes() so
            // the hand-rolled school term stayed independently observable. bin/ci-boundary-lint.php refused
            // it — finance-escape-hatches, §17.1 rule 4 — and it was right to: the escape hatch was there
            // to make a redundant check testable, which is a reason to delete the redundant check, not to
            // open the hatch. The lint bans the DB::table() form of the same evasion in the next token.
            //
            // A UUID, NOT THE INTEGER PRIMARY KEY (U8 commit 1). Every id this platform puts on the wire is
            // a uuid — DiscountPolicyResource:15, FeeScheduleResource:69 and :93, InvoiceResource:23,
            // InvoiceLineResource:17, and every finance route binds {model:uuid}. These two fields were the
            // exception: they accepted an integer PRIMARY KEY from a client. A screen built from those
            // Resources holds uuids and nothing else, so it could not have constructed a valid payload.
            //
            // An integer is now REFUSED, not accepted alongside — `string` + `uuid` reject it. There is no
            // transition period because there is no client to break: no caller of either field exists under
            // resources/js. `bail` keeps a non-uuid to ONE message rather than a shape error plus a
            // never-resolves error naming the same field twice.
            //
            // The integer id stays server-side. lineSpecs() resolves uuid → id below, so InvoiceLineSpec,
            // GenerateInvoice and finance_invoice_lines.fee_item_id are all unchanged, and the integer is
            // never handed back out: adding it to a Resource is the thing this rule exists to prevent.
            'lines.*.fee_item_id' => [
                'sometimes',
                'nullable',
                'bail',
                'string',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $item = FeeItem::query()->where('uuid', (string) $value)->first();

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
            // The discount policy a REDUCTION line cites (S1 3b). A LOOKUP id — the DB reduction_guard stays
            // the authority on what makes it USABLE (active + not approval-requiring + same School), and this
            // rule deliberately does not duplicate any of those three. There is likewise NO is_discountable
            // rule: that is a fee-item property resolved server-side in the Action, never a client claim (it
            // would let a caller move the percentage base).
            //
            // What it does check is EXISTENCE, which `integer` alone did not need to and a uuid does: the
            // wire id is no longer the stored id, so lineSpecs() has to resolve it, and an unresolvable uuid
            // would otherwise become a silent NULL — turning a bad reference into "no policy cited", which
            // for a reduction line is a DIFFERENT refusal with a different message, and for a charge line is
            // no refusal at all. Refusing it here keeps the resolution total FOR A VALUE THAT REACHES IT.
            //
            // ONE VALUE DOES NOT REACH IT, AND THAT IS DELIBERATE. `""` is rewritten to null by
            // Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull, which sits in the global
            // stack (verified by reflecting the kernel's $middleware; bootstrap/app.php:47 does not remove
            // it) and therefore runs BEFORE any rule here. So `""` arrives as null, satisfies `nullable`,
            // never enters this closure, and resolves to no provenance — exactly as an omitted field or an
            // explicit null does. That is the CHOSEN behaviour, not an oversight: an unselected `<select>`
            // posts `""`, and "the operator did not pick one" is what that means. The alternative —
            // refusing `""` as a malformed uuid — is not implementable at this layer at all, because the
            // value is already null by the time a rule can look at it; it would take removing a global
            // middleware every form on the platform depends on.
            //
            // What that costs is bounded and named rather than assumed. A REDUCTION line whose policy went
            // empty is still refused, by finance_invoice_lines_reduction_guard ("A reduction line must
            // reference an active discount policy"), so no reduction is ever written without one. A CHARGE
            // line whose fee_item_id went empty is written with null provenance — which is the same row a
            // hand-entered free-text line produces, and is indistinguishable from it by design, since
            // finance_invoice_lines.fee_item_id is nullable LOOKUP provenance with no foreign key. Both
            // outcomes are pinned by InvoiceWireIdsTest.
            //
            // Read through the SCOPED model, same shape and same reason as fee_item_id above. And the same
            // single message for both outcomes — a policy uuid that does not exist and one that belongs to
            // another School are indistinguishable to the caller, because saying "that exists, but not for
            // you" is the leak.
            'lines.*.discount_policy_id' => [
                'sometimes',
                'nullable',
                'bail',
                'string',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (DiscountPolicy::query()->where('uuid', (string) $value)->doesntExist()) {
                        $fail('The selected :attribute is invalid.');
                    }
                },
            ],
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
        // MEMOIZED, AND THE SECOND REASON IS THE ONE THAT MATTERS.
        //
        // Every generate path calls this TWICE. InvoiceController::generate:33 calls
        // assertMayReduce() → hasReductionLine() → lineSpecs(), and then :38 calls lineSpecs() again for
        // the Action; generateForStudent:76 and :83 do the same. Unmemoized, each pass re-ran the whole
        // uuid → id resolution.
        //
        // The cheap consequence is the query count. Measured over one POST with three fee-item lines and
        // one policy line, counting statements against finance_fee_items / finance_discount_policies /
        // finance_fee_schedules: 15 attributable to these two fields without this cache, 11 with it.
        //
        // The consequence worth the comment is that TWO INDEPENDENT RESOLUTIONS OF THE SAME UUID CAN
        // DISAGREE. The passes are separated by a permission check, not by a lock or a transaction, so a
        // row that is deleted, or a uuid that is re-pointed, between them makes the first pass — the one
        // the reduction guard is decided from — describe a different set of lines than the second pass,
        // which is what actually reaches GenerateInvoice and gets written. The permission decision would
        // then have been taken about lines that were never billed. One resolution per request closes
        // that window by construction rather than by timing.
        if ($this->lineSpecs !== null) {
            return $this->lineSpecs;
        }

        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->input('lines', []);

        return $this->lineSpecs = array_values(array_map(
            static fn (array $line) => new InvoiceLineSpec(
                description: (string) $line['description'],
                // A percentage line has no amount yet — it is resolved in the Action.
                amount: isset($line['percent'])
                    ? null
                    : Money::fromKobo(
                        (int) $line['amount_minor'],
                        (string) ($line['currency'] ?? Money::DEFAULT_CURRENCY),
                    ),
                // WIRE UUID → STORED INTEGER ID. This is the whole of U8 commit 1's server side: the
                // boundary translates, and nothing downstream learns that it did. InvoiceLineSpec keeps
                // `?int $feeItemId` / `?int $discountPolicyId`, GenerateInvoice:218/221 keeps writing
                // integers into finance_invoice_lines, and neither the Action nor the DTO nor the trigger
                // changes. The rules above have already resolved both uuids once, so a null here means the
                // field was absent or null, never that a validated uuid failed to resolve.
                feeItemId: isset($line['fee_item_id'])
                    ? self::idForUuid(FeeItem::query(), (string) $line['fee_item_id'])
                    : null,
                kind: isset($line['kind'])
                    ? InvoiceLineKind::from((string) $line['kind'])
                    : InvoiceLineKind::Charge,
                note: isset($line['note']) ? (string) $line['note'] : null,
                percent: isset($line['percent']) ? (int) $line['percent'] : null,
                discountPolicyId: isset($line['discount_policy_id'])
                    ? self::idForUuid(DiscountPolicy::query(), (string) $line['discount_policy_id'])
                    : null,
                // isDiscountable is NOT read from the wire — the Action resolves it from the fee item.
            ),
            $lines,
        ));
    }

    /**
     * The uuid → id lookup, through the SCOPED query the caller hands in.
     *
     * Taking a Builder rather than a class-string is what keeps SchoolScope applied: both callers pass
     * `Model::query()`, so this lookup sees exactly what the validation rules saw, and cannot drift from
     * them. It is not a `withoutGlobalScopes()` seam and must not become one.
     *
     * IT IS NOT A SECOND ISOLATION CHECK, AND SAYING SO WOULD BE WRONG. SchoolScope adds a predicate only
     * when there is an active School. For a super_admin with none selected it adds nothing — FeeItem and
     * DiscountPolicy are not in config/rbac.php `fail_closed_models`, so the null-context branch does not
     * throw either — and a foreign row resolves here to its real id. That request is refused by
     * GenerateInvoice ("No active School context: an invoice cannot be raised."), which is the layer that
     * actually stops it. See the fee_item_id rule above for the full statement and the test that pins it.
     *
     * `covariant` IS LOAD-BEARING AND THE IDE IS WRONG ABOUT IT. Some editors flag it as an undefined
     * type; removing it fails Larastan with `Template type TModel on class Builder is not covariant`
     * at both call sites, because `Builder<FeeItem>` is not a `Builder<Model>` without it. Measured,
     * both directions.
     *
     * @param  Builder<covariant Model>  $query
     */
    private static function idForUuid(Builder $query, string $uuid): ?int
    {
        $id = $query->where('uuid', $uuid)->value('id');

        return $id === null ? null : (int) $id;
    }
}
