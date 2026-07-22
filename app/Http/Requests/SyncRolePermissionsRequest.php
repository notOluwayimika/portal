<?php

namespace App\Http\Requests;

use App\Enums\Permission as PermissionEnum;
use App\Support\ApprovalAbility;
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
        // nobody. super_admin's authority is the bypass; its explicit 15
        // grants are the frozen probe precondition; edits here could only
        // break invariants, never grant authority.
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

            foreach ($requested as $ability) {
                if (! is_string($ability)) {
                    continue;
                }

                $maker = ApprovalAbility::matchingMakerFor($ability);

                if ($maker !== null && in_array($maker, $requested, true)) {
                    $v->errors()->add(
                        'permissions',
                        "A role may not hold both [{$maker}] (maker) and [{$ability}] (checker) — segregation of duties (ADR 0040/0044).",
                    );
                }
            }
        });
    }
}
