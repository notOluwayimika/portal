<?php

namespace App\Http\Requests;

use App\Enums\Permission as PermissionEnum;
use App\Support\ApprovalAbility;
use App\Support\DutySeparation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The write half of the super-admin RBAC matrix (C6). The route group already
 * gates on role:super_admin; authorize() repeats the actor check
 * (defense-in-depth) and carries the STRUCTURAL target rule.
 */
class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defense-in-depth actor check (the route group is the primary gate).
        if (! $this->user()?->isSuperAdmin()) {
            return false;
        }

        // D1 — the super_admin row is immutable through the matrix. A
        // STRUCTURAL rule on the target, deliberately not a permission check:
        // the only actors here are super admins, whom the Gate::before bypass
        // passes every permission — a permission-shaped guard would constrain
        // nobody. super_admin's authority is the bypass; its explicit platform
        // grants (RbacSeeder::SUPER_ADMIN_PLATFORM) are the frozen probe
        // precondition; edits here could only break invariants, never grant
        // authority.
        return $this->route('roleName') !== 'super_admin';
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            // D4 — the enum is code: grants are editable, definitions are not.
            // An unknown name is a validation failure, never a creation.
            'permissions.*' => ['string', 'in:'.implode(',', PermissionEnum::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // D2 — grant-time SoD: the RESULTING set may not contain a checker
            // ability together with its matching maker (ApprovalAbility
            // convention: result.approve ↔ result.submit;
            // finance.invoice.approve ↔ finance.invoice.submit the day Ph3
            // creates the pair). Validated on the resulting state, not the
            // delta — the invariant is about what the edit produces, however
            // it got there. Runtime counterpart of the seeder's SoD test:
            // that pins the DEFAULT map, this pins every map the matrix can
            // produce.
            $requested = (array) $this->input('permissions', []);

            foreach ($requested as $index => $ability) {
                if (! is_string($ability)) {
                    continue;
                }

                $maker = ApprovalAbility::matchingMakerFor($ability);

                if ($maker !== null && in_array($maker, $requested, true)) {
                    $message = "A role may not hold both [{$maker}] (maker) and [{$ability}] (checker) — segregation of duties (ADR 0040/0044).";

                    $v->errors()->add('permissions', $message);

                    // ALSO keyed by position, so the console can put the error on the offending
                    // chip instead of parsing the ability back out of the prose. Both keys are
                    // populated deliberately: `permissions` remains the bag any existing consumer
                    // reads, `permissions.N` is the addressable one.
                    $v->errors()->add("permissions.{$index}", $message);
                }
            }

            // USER-level SoD (Finance pairs only, Decision 0): the check above stops one ROLE holding
            // both sides; this stops the edit from making a MEMBER of this role hold the opposite side
            // via ANOTHER role in some school — a cross-role both-sides user the single-role check
            // cannot see. Surfaced as a validation error, so the whole sync is refused before any
            // write (wholesale). Shares DutySeparation's enforced-pair definition (Decision 1).
            $roleName = (string) $this->route('roleName');
            foreach (DutySeparation::violationsFromRolePermissionSync($roleName, $requested) as $violation) {
                $message = sprintf(
                    'This set would leave [%s] holding BOTH the checker [%s] and the maker [%s] in school #%d — via another role they already hold — segregation of duties (ADR 0040/0044). Give one of the two grants to a different user.',
                    $violation['userLabel'],
                    $violation['pair']['checker'],
                    $violation['pair']['maker'],
                    $violation['schoolId'],
                );

                $v->errors()->add('permissions', $message);

                // Attach to whichever side of the pair the edit actually requested, so this lands
                // on a chip too. Unlike the role-level rule above there may be no requested index
                // (the conflict can come entirely from another role), in which case the message
                // lives only in the bag and the console renders it in the panel.
                foreach (['checker', 'maker'] as $side) {
                    $index = array_search($violation['pair'][$side], $requested, true);

                    if ($index !== false) {
                        $v->errors()->add("permissions.{$index}", $message);
                    }
                }
            }
        });
    }
}
