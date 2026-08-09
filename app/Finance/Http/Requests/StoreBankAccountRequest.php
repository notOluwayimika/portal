<?php

namespace App\Finance\Http\Requests;

use App\Finance\Models\BankAccount;
use App\Support\ActiveSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a bank account. Authorization is the ROUTE's (permission:finance.bank-account.manage);
 * this refuses malformed input only, which is the split every other finance request follows.
 */
class StoreBankAccountRequest extends FormRequest
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
            'bank_name' => ['required', 'string', 'max:255'],
            // Unique WITHIN THE SCHOOL, not globally: the number is the reconciliation key, so two
            // accounts in one school sharing it makes a statement line ambiguous — but two different
            // schools may legitimately bank the same account, and a global unique would refuse that.
            // Mirrors the composite unique index; the database is the authority, this is the 422.
            'account_number' => [
                'required', 'string', 'max:64',
                Rule::unique(BankAccount::class, 'account_number')
                    ->where('school_id', ActiveSchool::id()),
            ],
            'account_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
