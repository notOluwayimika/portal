<?php

namespace App\Finance\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Finance\Enums\DiscountBase;
use App\Finance\Enums\DiscountBasis;
use App\Finance\Enums\DiscountPolicyChangeKind;
use App\Finance\Enums\DiscountPolicyChangeStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed change to the discount catalog (create | amend | retire) — the finance_void_requests shape.
 * Money-immutable / status-mutable by DB trigger; maker≠checker by DB CHECK. The catalog changes only
 * when ApproveDiscountPolicyChange approves this.
 *
 * @property int $id
 * @property string $uuid
 * @property int $school_id
 * @property DiscountPolicyChangeKind $kind
 * @property int|null $target_policy_id
 * @property string|null $name
 * @property string|null $description
 * @property DiscountBasis|null $basis
 * @property int|null $value_minor
 * @property string|null $value_currency
 * @property int|null $percent
 * @property DiscountBase|null $base
 * @property bool|null $requires_approval
 * @property string $reason
 * @property DiscountPolicyChangeStatus $status
 * @property int|null $submitted_by
 * @property int|null $decided_by
 */
class DiscountPolicyChange extends Model
{
    use AddUuid, BelongsToSchool;

    protected $table = 'finance_discount_policy_changes';

    protected $guarded = ['id'];

    protected $casts = [
        'kind' => DiscountPolicyChangeKind::class,
        'basis' => DiscountBasis::class,
        // NULLABLE here, unlike the catalog's: a `retire` proposes no terms and an `amount` basis has
        // no percentage to take of anything. NULL is the PROPOSED term's absence, not the value that
        // will be stamped — effectiveBase() below resolves that, and is the only thing that should
        // be read when the question is what the catalog will hold.
        'base' => DiscountBase::class,
        'status' => DiscountPolicyChangeStatus::class,
        'requires_approval' => 'boolean',
        'decided_at' => 'datetime',
    ];

    /**
     * WHAT BASE THE CATALOG WILL BE STAMPED WITH IF THIS CHANGE IS APPROVED — the ONE writer of that
     * rule. `ApproveDiscountPolicyChange::insertPolicy()` writes the catalog from it and
     * `DiscountPolicyChangeResource` shows the checker the same value, so the term decided is the
     * term seen. (Named in prose and not through `{@see}`: a fully-qualified tag makes Pint import
     * the class, and a Model reaching for an Action and an Http Resource is a dependency the wrong
     * way round for the sake of a doc link.) That single-writer shape is the
     * point: `base` was dropped from the catalog in the first place because the coalesce lived in a
     * whitelist nobody cross-checked, and a second copy of it in the Resource would be the same
     * defect with a screen in front of it — the two would agree until one was edited.
     *
     * Null ONLY on a `retire`, which approves no policy at all: there is no row to stamp and so no
     * base to state. That is honest rather than convenient — the alternative is a Resource key that
     * says `discountable` about an act that creates nothing.
     *
     * THE THREE STEPS, IN ORDER, AND EACH EARNS ITS PLACE:
     *
     *   $this->base   — the maker said so. Stating a term is always authoritative.
     *   the target    — the maker said NOTHING, there is a policy being amended, AND the amendment
     *                   keeps its basis, so nothing is what changes. This is the step that makes
     *                   omission SAFE rather than merely refused: a `total` policy raised from 50%
     *                   to 55% stays whole-bill even if the maker never mentions the base, which is
     *                   the realistic shape of the mistake. Requiring the field would have moved the
     *                   defect onto the maker remembering; this removes it.
     *   Discountable  — a create, a pre-axis change row whose `base` is NULL because it was
     *                   submitted before the column existed, or a CROSS-BASIS amend (below). The
     *                   same value a create lands on and the column's own default.
     *
     * CROSS-BASIS DOES NOT INHERIT, and that condition is the whole of why this method exists rather
     * than a `??` chain. Two reasons, and the second is the hole:
     *
     *   - A maker converting a policy's basis is RESHAPING it, not nudging a rate. The premise of
     *     the inheritance step — "the maker said nothing, so nothing changes" — is false there:
     *     they changed the one thing the base qualifies. They get the default, and may state
     *     otherwise on the same change.
     *   - SubmitDiscountPolicyChangeRequest:58 carries `prohibited_if:basis,amount`, so a change to
     *     an AMOUNT basis cannot state a base at all. Inheriting there stamps the amount policy with
     *     a value that is inert and that no maker typed; amend that policy back to a percent basis
     *     without stating one and it inherits AGAIN — so a live percentage would take its base from
     *     a value nobody stated on either change and no checker saw on either screen, and `base` is
     *     immutable on the catalog row. Refusing to carry across the basis hop closes the round trip
     *     at the point where the value stops meaning anything.
     *
     * The percent→percent amend — the case the inheritance exists for — is untouched by this.
     */
    public function effectiveBase(): ?DiscountBase
    {
        if ($this->kind === DiscountPolicyChangeKind::Retire) {
            return null;
        }

        if ($this->base instanceof DiscountBase) {
            return $this->base;
        }

        $target = $this->target;

        // Enum identity, so a null basis on either side is a mismatch and falls to the default —
        // which is the safe direction: the default is what a create lands on.
        if ($target instanceof DiscountPolicy && $this->basis === $target->basis) {
            return $target->base;
        }

        return DiscountBase::Discountable;
    }

    /**
     * The policy being amended or retired — null on a `create`, which has no target yet.
     *
     * The generic is not decoration: without it Larastan reads `$this->target` as a bare Model and
     * fails any property read on it (level 5, `property.notFound`), which is what happens the moment
     * anything actually uses this relation. FeeScheduleChange::target() has carried the annotation
     * since it shipped; this one was written without a caller and never had to.
     *
     * @return BelongsTo<DiscountPolicy, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(DiscountPolicy::class, 'target_policy_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
