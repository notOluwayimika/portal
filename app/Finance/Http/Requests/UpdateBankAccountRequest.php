<?php

namespace App\Finance\Http\Requests;

use App\Finance\Models\BankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Editing a bank account — LABELS ONLY.
 *
 * `bank_name` and `account_number` are immutable from creation: they are what a bursar matches a
 * bank statement against, so changing them after payments point at the account silently rewrites
 * where historical money went. See the identity-immutable migration for the full argument.
 *
 * THIS LAYER EXISTS TO SAY WHAT TO DO NEXT. The database trigger refuses the write, but a 500 from
 * a SIGNAL tells an operator nothing they can act on. This turns the same rule into a 422 naming
 * the way out — deactivate and create a new one — which is the only useful thing to say to someone
 * whose bank details have genuinely changed.
 *
 * The fields are not merely ignored: submitting a DIFFERENT value is refused. Silently dropping it
 * would let an operator believe the change was accepted, which is worse than refusing — they would
 * discover it at the next reconciliation instead of at the click.
 */
class UpdateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            /** @var BankAccount|null $account */
            $account = $this->route('bankAccount');

            if ($account === null) {
                return;
            }

            foreach (['bank_name' => $account->bank_name, 'account_number' => $account->account_number] as $field => $current) {
                // Absent is fine — the screen does not send them. A DIFFERENT value is the refusal.
                if ($this->has($field) && (string) $this->input($field) !== (string) $current) {
                    $v->errors()->add($field, match ($field) {
                        'bank_name' => 'The bank name cannot be changed. Deactivate this account and create a new one.',
                        default => 'The account number cannot be changed. Deactivate this account and create a new one.',
                    });
                }
            }
        });
    }
}
