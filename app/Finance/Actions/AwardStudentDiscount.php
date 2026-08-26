<?php

namespace App\Finance\Actions;

use App\Enums\ScholarshipKind;
use App\Exceptions\BusinessRuleException;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\StudentDiscountAward;
use App\Models\Scholarship;
use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Put one student on one discount policy — the write side of `finance_student_discount_awards`.
 *
 * ── WHY THE REFUSALS ARE HERE AND NOT ONLY AT BILL TIME ──────────────────────────────────────────
 *
 * `finance_invoice_lines_reduction_guard` would refuse the resulting reduction line anyway: a
 * policy that is not `active`, or that requires per-application approval, or that belongs to another
 * School, cannot become an invoice line. So every refusal below is, strictly, redundant with a
 * control that already exists.
 *
 * It is redundant in WHEN, and that is the entire point. The trigger fires inside
 * {@see ProcessBulkInvoiceRun}, per student, on the one day of the term the whole cohort is being
 * billed — one bad award becomes one `failed` row among a hundred, discovered by an operator
 * reading reasons after the run, with the invoice for that child not raised. Refusing at award time
 * costs the person making the award five seconds and names the policy they just chose. Same rule,
 * two enforcement points, deliberately: the database is the guarantee, this is the moment it is
 * useful.
 *
 * ── THE FOURTH POLICY REFUSAL IS NOT IN THE GUARD, AND HAS TO BE HERE ────────────────────────────
 *
 * `basis` must be `percent`. An `amount`-basis policy has `percent IS NULL` by the table's own
 * basis-exclusive constraint, so an award citing one would produce a reduction spec with no
 * percentage and no amount — and `InvoiceLineSpec::resolvedAmount()` would raise a LogicException
 * inside the run, which is a PHP fault wearing a billing failure's clothes. The reduction guard
 * cannot see this: it inspects status, approval and School, never basis. A fixed-naira reduction per
 * student is a coherent future feature; it is simply not what this path builds, and an award that
 * cannot be applied must not be storable.
 *
 * ── THE SCHOLARSHIP RULE, AND THE DECISION TAKEN ─────────────────────────────────────────────────
 *
 * An award requires the student to hold a scholarship whose {@see ScholarshipKind} is `Discount`.
 * The alternatives were to refuse only the `Sponsored` contradiction, or to allow anything and let
 * the run sort it out. REFUSING EVERYTHING THAT IS NOT `Discount` is what is built, because the two
 * looser readings both create configuration that reads as live and can never act:
 *
 *   SPONSORED — the run EXCLUDES these students before it bills anyone
 *               (`ProcessBulkInvoiceRun::process()`), so their award can never be applied. A stored
 *               award nobody will ever read is the "control the server never receives" defect with
 *               the sign flipped: it manufactures the confidence that a child is being discounted
 *               while an outside organisation is in fact being invoiced by hand.
 *   UNCONFIGURED (`kind` IS NULL) — the run refuses the WHOLE cohort until somebody says which
 *               scheme the scholarship is, so an award made now is unusable now. Refusing here says
 *               the same thing at the same volume, one screen earlier.
 *   NO SCHOLARSHIP — a discount is a scholarship scheme at this school. Awarding one to a child on
 *               no scheme would be an unrecorded fee change: nothing outside `finance_` would say
 *               why that family pays less.
 *
 * THE COST OF THE STRICT READING, STATED: the next commit's import must ensure the scholarship
 * exists and its `kind` is set before it awards. That is a real ordering constraint on that commit
 * and it is the right way round — the scholarship is the school's own record of WHY, and the award
 * is only the pricing consequence.
 *
 * ── THE STUDENT IS REACHED THROUGH THE PORT ──────────────────────────────────────────────────────
 *
 * `scholarshipIdsFor()` — the same route the scholarship commit took, and for its reasons: it
 * matches the cohort's soft-delete rule, and `withTrashed()` is a boundary-lint failure inside
 * `app/Finance`. A student absent from the map holds no scholarship OR is not this School's, and
 * this Action deliberately does not try to tell those apart: the composite FK on the table refuses
 * the cross-School row outright, so the only reachable meaning of "absent" at the point of insert is
 * "holds no scholarship here".
 *
 * ── ONE AWARD PER STUDENT ────────────────────────────────────────────────────────────────────────
 *
 * `finance_student_discount_awards_student_unique` is the authority. The pre-check below is a
 * friendly refusal only, and it CANNOT hold under concurrency — two racers read a snapshot in which
 * no award exists — which is why it is a pre-check and not a claim. Replacing an existing award is
 * deliberately not built here: there is no consumer yet, and a create path that silently overwrites
 * a child's pricing is not something to invent ahead of the screen that would ask for it.
 */
final class AwardStudentDiscount
{
    /** The activity-log event name. snake_case, so the read API's `event` whereIn can select it. */
    public const AWARDED = 'discount_award_created';

    public function __construct(
        private readonly BillableEnrollmentProvider $enrollments,
    ) {}

    /**
     * @param  int  $studentId  the student to award. An id, not a uuid: nothing crosses the wire on
     *                          this path yet (there is no request and no screen until the next
     *                          commit), and `finance_student_discount_awards` stores ids.
     * @param  int  $policyId  `finance_discount_policies.id`.
     * @param  ?int  $actorId  who is making the award — attribution only, never an execution
     *                         identity (Constitution 13). Nullable so a console caller works.
     *
     * @throws BusinessRuleException
     */
    public function handle(int $studentId, int $policyId, ?int $actorId = null): StudentDiscountAward
    {
        // Constitution 13: context is explicit or absent, never inferred. A financial write with no
        // School context fails closed rather than adopting whatever row it happens to read.
        $schoolId = ActiveSchool::id();

        if ($schoolId === null) {
            throw new BusinessRuleException('No active School context: a discount award cannot be made.');
        }

        $policy = $this->readPolicy($policyId, $schoolId);

        $this->assertScholarshipAllows($studentId, $schoolId);

        if (StudentDiscountAward::query()->where('student_id', $studentId)->exists()) {
            throw new BusinessRuleException(
                'This student already has a discount award. Remove the existing one before awarding another.'
            );
        }

        // ONE TRANSACTION, SO AN UNAUDITED AWARD IS NOT REACHABLE. The award and the entry that says
        // who made it and on what terms commit together or not at all. This table carries no
        // immutability trigger — its migration argues the exemption on exactly this audit existing —
        // so a row that landed while its entry did not would be a fee change with no record and no
        // way to notice, which is the state the exemption was written on the assumption of avoiding.
        return DB::transaction(function () use ($schoolId, $studentId, $policy, $actorId) {
            $award = StudentDiscountAward::create([
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'discount_policy_id' => $policy->id,
                'created_by_user_id' => $actorId,
            ]);

            $this->log($award, $policy, $actorId);

            return $award;
        });
    }

    /**
     * The audit entry for a new award — causer, subject, and the terms either side.
     *
     * THE TERMS ARE THE POLICY'S, WHICH IS WHY THIS EXISTS AT ALL. `StudentDiscountAward` carries
     * {@see LogsActivity}, so the row's own columns are already logged
     * before-and-after by the trait — but the only term ON the row is `discount_policy_id`, an
     * integer. What a family actually pays is `percent` and `base`, and those live on the policy. An
     * audit trail reading `discount_policy_id: 41 -> 57` cannot answer "did this child's discount go
     * up or down", which is the only question anyone will ever ask of it. So the resolved figures
     * are SNAPSHOTTED into the properties here, the same reason an invoice line stores the naira
     * figure rather than "50%": the policy may be superseded later, and a historical audit entry
     * must not be re-read against terms that have since moved.
     *
     * `from` IS NULL AND SAYS SO. This Action only ever creates — a second award is refused — so
     * there is no prior state on this path, and an explicitly null `from` is the honest shape rather
     * than an absent key a reader has to interpret. When the next commit's import gains a replace
     * path it fills this in; until then the trait's column-level before/after is what covers a
     * mutation, and it covers a mutation made by ANY writer, including one that does not come
     * through here.
     *
     * SNAKE_CASE EVENT NAME, for the reason `StudentRecordAccessLog` gives: the read API filters on
     * `event` with a whereIn and the screen offers those exact values as a multi-select, so a
     * sentence in `event` is a row nobody can select.
     *
     * NOT DEFENSIVE, unlike `StudentRecordAccessLog::write()`. That class swallows because a failed
     * log must not turn a 403 into a 500 — the log is beside an authorization decision that has to
     * stand either way. Here the log is INSIDE the write it describes and the correct failure is a
     * failed award: the pattern is `LogRbacChange`, which also logs a governance change and also
     * does not catch.
     */
    private function log(StudentDiscountAward $award, DiscountPolicy $policy, ?int $actorId): void
    {
        $causer = $actorId === null ? null : User::find($actorId);

        $logger = activity('finance')
            ->performedOn($award)
            ->event(self::AWARDED)
            ->withProperties([
                'student_id' => $award->student_id,
                'from' => null,
                'to' => [
                    'discount_policy_id' => $policy->id,
                    'policy_name' => $policy->name,
                    'percent' => $policy->percent,
                    'base' => $policy->base->value,
                ],
            ]);

        if ($causer instanceof User) {
            $logger->causedBy($causer);
        }

        $logger->log(self::AWARDED.': '.$policy->name);
    }

    /**
     * The policy, refused unless it is one an invoice line could actually cite.
     *
     * READ UNDER THE SCOPE, WITH THE School RESTATED. `DiscountPolicy` carries `SchoolScope`, and
     * the explicit `where` is the same decision the rest of this module takes: the scope filters
     * when there is ambient context and does not refuse when there is none, so a financial read
     * names its School rather than inheriting one. `withoutGlobalScope(` is a hard boundary-lint
     * failure inside `app/Finance`, so a foreign policy is NOT FOUND rather than reported as
     * foreign — and the message says so, instead of claiming a distinction it cannot make.
     */
    private function readPolicy(int $policyId, int $schoolId): DiscountPolicy
    {
        $policy = DiscountPolicy::query()
            ->where('school_id', $schoolId)
            ->find($policyId);

        if (! $policy instanceof DiscountPolicy) {
            throw new BusinessRuleException(
                "No discount policy [#{$policyId}] exists in this School: it is absent, or it belongs to another School."
            );
        }

        if ($policy->status !== DiscountPolicyStatus::Active) {
            throw new BusinessRuleException(
                "Discount policy [{$policy->name}] is {$policy->status->value}; only an active policy may be awarded."
            );
        }

        if ($policy->requires_approval) {
            throw new BusinessRuleException(
                "Discount policy [{$policy->name}] requires per-application approval, so it cannot be a standing "
                .'award: each application goes through a credit note.'
            );
        }

        if ($policy->basis !== DiscountBasis::Percent) {
            throw new BusinessRuleException(
                "Discount policy [{$policy->name}] is a fixed-amount policy; a standing award applies a percentage "
                .'of the bill, so it needs a percentage policy.'
            );
        }

        return $policy;
    }

    /** The student must be on a `discount` scholarship — see the class docblock for why, and for what else was on the table. */
    private function assertScholarshipAllows(int $studentId, int $schoolId): void
    {
        $held = $this->enrollments->scholarshipIdsFor([$studentId], $schoolId);
        $scholarshipId = $held[$studentId] ?? null;

        if ($scholarshipId === null) {
            throw new BusinessRuleException(
                "Student [#{$studentId}] holds no scholarship in this School, so there is nothing for a discount "
                .'award to price. Put them on a discount scholarship first.'
            );
        }

        $scholarship = Scholarship::query()
            ->where('school_id', $schoolId)
            ->find($scholarshipId);

        if (! $scholarship instanceof Scholarship) {
            // Schema-reachable: `students.scholarship_id` references `scholarships (id)` and is NOT
            // composite with `school_id`. Named by ID — printing another School's scholarship name
            // here would be a cross-School leak through an error message.
            throw new BusinessRuleException(
                "Scholarship [#{$scholarshipId}] could not be read in this School, so student [#{$studentId}] "
                .'cannot be classified. It has been deleted or belongs to another school.'
            );
        }

        if ($scholarship->kind !== ScholarshipKind::Discount) {
            $state = $scholarship->kind === null ? 'not configured yet' : $scholarship->kind->value;

            throw new BusinessRuleException(
                "Scholarship [{$scholarship->name}] is {$state}; a discount award may only be made against a "
                .'discount scholarship. A sponsored student is billed by hand and is excluded from the bulk run, '
                .'so an award on one could never be applied.'
            );
        }
    }
}
