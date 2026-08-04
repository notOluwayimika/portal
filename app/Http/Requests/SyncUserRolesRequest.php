<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The write half of the school-admin Users module (C5). Four of the five
 * guards live here; D4 (team-context assignment) lives on the write path
 * itself (SchoolUserController + the User::assignRole null-team invariant).
 *
 * D1 and D3 are STRUCTURAL rules on the target, not permission checks — that
 * is deliberate, not stylistic: Gate::before passes super_admin every
 * permission check, so a permission-shaped "may not edit super_admin" guard
 * would be bypassed by exactly the actor class it must constrain. Target
 * identity cannot be bypassed.
 */
class SyncUserRolesRequest extends FormRequest
{
    /**
     * Ordinary school roles a school admin may assign. `admin` joins only for
     * a super_admin actor (D2); `super_admin` is NEVER assignable here (D1) —
     * platform roles are not granted through a School's module.
     */
    public const SCHOOL_ROLES = [
        'principal',
        'head_of_school',
        'teacher',
        'guardian',
        'boarding_parent',
        'form_teacher',
        'key_stage_coordinator',
        'registrar',
    ];

    public function authorize(): bool
    {
        $actor = $this->user();
        /** @var User $target */
        $target = $this->route('user');
        $schoolId = ActiveSchool::id();

        // D5 — the module permission gates the write as well as the page
        // (route middleware alone would leave the endpoint one refactor away
        // from unguarded).
        if (! $actor?->can('rbac.manage_users')) {
            return false;
        }

        // Isolation: the target must belong to the ACTIVE School, resolved
        // through the SANCTIONED accessor (flag-aware: legacy union today,
        // model_has_roles under S7 single-source) — a raw pivot read here
        // would be a new legacy-source consumer the runtime-zero lint forbids.
        // Needed because {user:uuid} binding is not School-scoped.
        if ($schoolId === null || ! $target->canAccessSchool($schoolId)) {
            return false;
        }

        // D1 — never super_admin. Structural, so it holds with the bypass ON.
        if ($target->isSuperAdmin()) {
            return false;
        }

        // D3 — no self-modification (also closes admin-demotes-self-then-
        // cannot-undo, by refusing the write rather than detecting the
        // specific dangerous transition).
        if ($actor->id === $target->id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $assignable = self::SCHOOL_ROLES;

            // D2 — elevating someone to admin is a super-admin act.
            //
            // `executive_director` joins on the same footing (2026-08-04). It is NOT in
            // SCHOOL_ROLES and must not be: that list is gated on `rbac.manage_users`, which a
            // school admin holds, so putting ED there would let a school admin grant themselves
            // the top financial approver — sole checker on all four built finance pairs. Before
            // this line ED was assignable through no surface at all, which was worse: the seat
            // move left the platform unable to approve anything financial with no in-app remedy.
            //
            // DutySeparation::assertAssignmentAllowed still runs underneath, on every path:
            // spatie's syncRoles ends in `$this->assignRole($roles)`
            // (vendor HasRoles.php:313), which is overridden at User.php:412. So widening this
            // allowlist widens WHO may assign, never WHICH combinations are legal.
            if ($this->user()?->isSuperAdmin()) {
                $assignable[] = 'admin';
                $assignable[] = 'executive_director';
            }

            foreach ((array) $this->input('roles', []) as $role) {
                if (! in_array($role, $assignable, true)) {
                    $v->errors()->add(
                        'roles',
                        "Role [{$role}] is not assignable through this module.",
                    );
                }
            }
        });
    }
}
