<?php

namespace App\Finance\Http\Requests;

use App\Finance\Models\BankAccount;
use App\Models\Student;
use App\Support\ActiveSchool;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * THE BURSAR'S SELECTION, plus the lines every one of them is to be charged.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * EXPLICIT IDS ONLY — there is no filter payload here, and that is a decision
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `student_ids` is an array the caller names in full. "Invoice all N matching" is NOT accepted and
 * must not be added here later as a convenience: the brief (§1) rules that if it is ever offered it
 * is resolved SERVER-SIDE from the filter payload and never from a client id list, because that is
 * exactly the live defect in `guardians/bulk-action-bar.tsx` — the operator is told 240, the browser
 * holds the 25 ids of the current page, and the action runs on those. In an export that produces a
 * short spreadsheet. Here it would bill 25 families and report 240.
 *
 * So this request answers ONE scope, honestly: these ids, this many.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * ISOLATION IS NOT AUTHORIZATION, AND A FOREIGN ID IS REFUSED RATHER THAN DROPPED
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `super_admin` bypasses authorization and NEVER isolation (ADR 0036/0040), so the check below binds
 * them too: it is a query, not a Gate, and the School it names is the ACTIVE School rather than the
 * actor's.
 *
 * FILTERING WOULD BE THE WORSE FAILURE. A cross-School id silently dropped from a list of ninety
 * produces a run that bills eighty-nine and reports eighty-nine — internally consistent, balanced,
 * and about a different selection than the one the bursar made. The refusal is a 422 that NAMES the
 * offending ids, so the operator can act on it.
 *
 * AND THIS REFUSAL IS THE ENFORCEMENT ON THIS PATH, NOT A PRE-CHECK IN FRONT OF ONE. That
 * distinction was wrong in the first version of this docblock and it was MEASURED wrong: with the
 * rule removed, the cross-School arm returned **201**, not a database refusal. The composite foreign
 * key `finance_manual_invoice_run_targets (student_id, school_id) -> students (id, school_id)` does
 * make a foreign target unrepresentable — but only a target that is WRITTEN, and a foreign id
 * resolved under a School-scoped lookup is never written at all. It is dropped. The FK cannot refuse
 * what never reaches it.
 *
 * So the honest shape is: this rule enforces, `selectedStudentIds()` refuses to skip (see the throw
 * there, which is what stops a weakened rule degrading into a silent short bill), and the FK is the
 * backstop for any OTHER caller of `StartManualInvoiceRun` — one handed raw ids rather than ids this
 * class resolved. That is a real guarantee and a narrower one than "the database refuses this",
 * which is what the earlier wording claimed.
 *
 * WHY UUIDs AND NOT INTEGER KEYS. Every id on this API surface is a uuid (`bank_account_id`,
 * `fee_item_id`, `enrollment_id`), and the client that will POST this holds student uuids —
 * `BillableEnrollmentAdapter::displayFor()` is what the students index and every Finance row
 * serializer return. An integer-keyed payload would be a second addressing scheme for one entity.
 *
 * SOFT-DELETED STUDENTS DO NOT RESOLVE, and the message is worded for it. The first version of this
 * class used `withTrashed()` on the reasoning that a trashed student with an ACTIVE episode IS
 * billable — which is true, `BillableEnrollmentAdapter::billableEpisodes()` queries
 * `student_curricula` and never joins `students`. `bin/ci-boundary-lint.php` refused it
 * (finance-escape-hatches, §17.1 rule 4, which covers `withTrashed()` as an alias of
 * `withoutGlobalScope(SoftDeletingScope::class)`), and the refusal was RIGHT on the merits rather
 * than merely enforced: every roster surface a bursar picks from excludes trashed students —
 * `displayFor()` and `admissionNumberIndex()` both do — so a trashed uuid can only arrive from a
 * stale client, and declining to raise a charge against a deleted student is the safe direction to
 * fail. What the refusal must NOT do is claim to know why, so it says the ids could not be FOUND in
 * this school rather than that they belong to another one.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE LINES: CHARGES, AT FULL PRICE, EACH NAMING A DESTINATION
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `bank_account_id` is `required`, not `sometimes` — S11 (`d3227c0`) made a destination account
 * mandatory on every charge line and `finance_invoice_lines_destination_guard` is the authority
 * behind it. `GenerateInvoiceRequest` needs a separate `assertDestinationsChosen()` pass because its
 * lines may be reductions, which carry no destination; a manual run has no reduction line to make
 * room for, so the requirement collapses into the rule list and cannot be reached around.
 * `finance_manual_invoice_run_lines.bank_account_id` is NOT NULL beneath it, and its composite FK to
 * `finance_bank_accounts (id, school_id)` is what makes another School's account unrepresentable.
 *
 * ONE SET OF LINES FOR THE WHOLE RUN — one choice per LINE, not per student (brief §5).
 *
 * `amount_minor` is `min:1`: every line is a CHARGE. `ProcessManualInvoiceRun::lines()` builds each
 * spec with `InvoiceLineKind::Charge` and there is no `percent` and no `discount_policy_id` on this
 * surface at all. A reduction needs a policy to authorise it (`assertDiscountPoliciesUsable()`), and
 * admitting a negative amount here would be a reduction with no policy — the exact thing that has to
 * go through a credit note instead.
 *
 * NO SCHOLARSHIP LOOKUP, ANYWHERE. Brookstone, 29 August: a scholarship covers termly fees and does
 * not touch a mid-term charge. `GenerateInvoice` contains zero references to `StudentDiscountAward`
 * and a test pins that absence. There is nothing to build here and nothing to "fix".
 *
 * NO CAP ON THE SELECTION SIZE, and that is deliberate rather than overlooked. The scheduled run has
 * none either, and a cap invented here would be a number with no consumer's evidence behind it. If
 * one is ever wanted, pin the VALUE and use a LITERAL payload either side of it — a test that builds
 * its input from the constant can only ever restate the constant.
 */
class StoreManualInvoiceRunRequest extends FormRequest
{
    /**
     * uuid => students.id, for the ids this request named. Memoised because the isolation rule and
     * the controller both need it and it is a database read.
     *
     * @var array<string, int>|null
     */
    private ?array $studentIdMap = null;

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
            'student_ids' => [
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->failOnForeignStudents($value, $fail);
                },
            ],
            'student_ids.*' => ['required', 'string', 'uuid', 'distinct'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount_minor' => ['required', 'integer', 'min:1'],
            'lines.*.currency' => ['sometimes', 'string', Rule::in([Money::DEFAULT_CURRENCY])],
            'lines.*.bank_account_id' => [
                'required',
                'bail',
                'string',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (BankAccount::query()->where('uuid', (string) $value)->doesntExist()) {
                        $fail('The selected :attribute is invalid.');
                    }
                },
            ],
        ];
    }

    /**
     * The selected students as `students.id`, IN PAYLOAD ORDER.
     *
     * Order is kept because the run walks its targets by id and the report reads them back in that
     * order; a bursar comparing the report against their own tick list should meet the same
     * sequence they submitted rather than an incidental one.
     *
     * @return list<int>
     */
    public function selectedStudentIds(): array
    {
        $map = $this->studentIdMap();

        $ids = [];

        foreach ((array) $this->input('student_ids', []) as $uuid) {
            $id = $map[(string) $uuid] ?? null;

            /*
             * AN UNRESOLVED ID THROWS; IT IS NEVER SKIPPED — and that is not defensive coding, it is
             * the difference between two failures. MEASURED, by removing the isolation rule above and
             * running the cross-School arm: with a `continue` here the foreign id simply vanished and
             * the request answered 201 over a SHORTER selection. No database guard was reached and
             * none could be, because the id never becomes a row — the composite FK on
             * `finance_manual_invoice_run_targets` can only refuse a foreign student that is WRITTEN,
             * and a silently-dropped one is not.
             *
             * So the rule above is the enforcement on this path rather than a pre-check in front of
             * one, and this throw is what keeps that true: with a skip here, a rule that stopped
             * refusing would degrade into exactly the "told 240, billed 25" behaviour the brief (§1)
             * exists to forbid. It is unreachable while the rule stands, which is the point.
             */
            if ($id === null) {
                throw new \LogicException(
                    'A selected student could not be resolved after validation passed. The isolation '
                    .'rule on student_ids is what must refuse this, and it did not.'
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The run's lines, resolved and ordered, ready for the Action.
     *
     * `sort_order` is the payload index and nothing else — `finance_manual_invoice_run_lines` carries
     * `UNIQUE(school_id, run_id, sort_order)`, so a derived-from-anything-else order could collide.
     *
     * @return list<array{description: string, amount: Money, bank_account_id: int, sort_order: int}>
     */
    public function runLines(): array
    {
        $accounts = BankAccount::query()
            ->whereIn('uuid', array_map(
                static fn (array $line) => (string) ($line['bank_account_id'] ?? ''),
                array_values((array) $this->input('lines', [])),
            ))
            ->pluck('id', 'uuid');

        $lines = [];

        foreach (array_values((array) $this->input('lines', [])) as $index => $line) {
            $lines[] = [
                'description' => (string) $line['description'],
                'amount' => Money::fromKobo(
                    (int) $line['amount_minor'],
                    (string) ($line['currency'] ?? Money::DEFAULT_CURRENCY),
                ),
                'bank_account_id' => (int) $accounts[(string) $line['bank_account_id']],
                'sort_order' => $index,
            ];
        }

        return $lines;
    }

    /**
     * The isolation refusal itself.
     *
     * THE SCHOOL IS AN ARGUMENT, NOT AN AMBIENT OPINION. `ActiveSchool::getOrFail()` rather than
     * `id()`, so a request with no School context is REFUSED (409, MissingSchoolContextException's
     * own render) instead of resolving against whatever the ambient scope happens to be — which for
     * a `super_admin` with no School selected is nothing at all, and `Student`'s SchoolScope would
     * then fall to its silent-unscoped branch and admit every School's students.
     *
     * `->id` and NOT the model: `getOrFail()` returns the School, and `where('school_id', $school)`
     * reads as correct while matching nothing.
     */
    private function failOnForeignStudents(mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $requested = array_values(array_unique(array_map('strval', array_filter($value, 'is_scalar'))));

        if ($requested === []) {
            return;
        }

        $missing = array_values(array_diff($requested, array_keys($this->studentIdMap())));

        if ($missing === []) {
            return;
        }

        $fail(
            'These students could not be found in this school, so they cannot be invoiced by it: '
            .implode(', ', $missing)
            .'. Nothing has been billed — remove them from the selection and start the run again.'
        );
    }

    /**
     * @return array<string, int>
     */
    private function studentIdMap(): array
    {
        if ($this->studentIdMap !== null) {
            return $this->studentIdMap;
        }

        $schoolId = ActiveSchool::getOrFail()->id;

        $uuids = array_values(array_unique(array_map(
            'strval',
            array_filter((array) $this->input('student_ids', []), 'is_scalar'),
        )));

        if ($uuids === []) {
            return $this->studentIdMap = [];
        }

        return $this->studentIdMap = Student::query()
            ->where('students.school_id', $schoolId)
            ->whereIn('students.uuid', $uuids)
            ->pluck('students.id', 'students.uuid')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }
}
