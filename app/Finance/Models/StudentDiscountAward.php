<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Actions\AwardStudentDiscount;
use App\Finance\Contracts\BillableEnrollmentProvider;
use App\Finance\Jobs\ProcessBulkInvoiceRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * WHICH discount policy prices a given student. One row per student, written only by
 * {@see AwardStudentDiscount}; read by {@see ProcessBulkInvoiceRun}, which turns it into one
 * percentage reduction spec on that student's invoice.
 *
 * THE ROW CARRIES NO TERMS OF ITS OWN — no percentage, no base. Both live on the cited
 * {@see DiscountPolicy}, and that is the whole reason the award is a two-column link rather than a
 * copy: a percentage stored here would be a second copy of a policy's own figure, free to drift from
 * it, with nothing able to say which of the two the school meant. "Per student" is satisfied by each
 * student naming their OWN policy, not by each student carrying their own number.
 *
 * IT IS AUDITED, AND THAT IS NOT DECORATION — it is the clause the table's own migration rests its
 * append-only EXEMPTION on. The migration argues this row needs no immutability triggers because it
 * is live configuration the import must be able to change, and closes "activitylog covers who
 * changed an award". Cold review checked and that sentence was FALSE when written: no Finance model
 * carried {@see LogsActivity}, there was no listener and no observer, so an award moved from 50% to
 * 10% left nothing but a bumped `updated_at` on the row that decides what a family pays. The trait
 * is what makes the sentence true.
 *
 * IT IS THE SECOND OF TWO AUDIT MECHANISMS AND THEY ARE NOT REDUNDANT.
 * {@see AwardStudentDiscount} writes an explicit `discount_award_created`
 * entry carrying the RESOLVED TERMS — percent, base, policy name — none of which is a column here,
 * so nothing the trait can see would record what the award actually costs. This trait catches any
 * write that does NOT go through that Action, which is precisely the writer the exemption
 * anticipates: the next commit's import. A create therefore produces TWO entries, deliberately —
 * one semantic and queryable, one column-level with before and after.
 *
 * IT NAMES A STUDENT BY id AND NOTHING ELSE. Finance owns no name, no admission number and no
 * scholarship; those are Academics facts reached through the ACL port
 * ({@see BillableEnrollmentProvider}). Isolation is at the engine — the
 * table's composite FKs pin both the student and the policy to this row's School.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property int $student_id
 * @property int $discount_policy_id
 * @property int|null $created_by_user_id
 */
class StudentDiscountAward extends Model
{
    use AddUuid, BelongsToSchool, LogsActivity;

    protected $table = 'finance_student_discount_awards';

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(DiscountPolicy::class, 'discount_policy_id');
    }

    /**
     * `discount_policy_id` is the term: it is the whole of what this row decides, since the
     * percentage and the base live on the policy it names. `school_id` and `student_id` are logged
     * too — not because they are expected to move (the composite FKs and `UNIQUE(student_id)` make
     * that close to unreachable) but because if they ever DID, that is the one change on this table
     * nobody would otherwise be able to reconstruct.
     *
     * `logOnlyDirty()` so a save that changes nothing writes nothing, matching Arm and the rest of
     * the models that carry this trait.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['school_id', 'student_id', 'discount_policy_id'])
            ->logOnlyDirty();
    }
}
