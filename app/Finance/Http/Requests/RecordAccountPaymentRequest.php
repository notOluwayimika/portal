<?php

namespace App\Finance\Http\Requests;

use App\Finance\Models\BankAccount;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a payment on the ACCOUNT (the route carries {student:uuid}, no invoice). Rules are
 * identical to {@see RecordPaymentRequest} today, but kept a SEPARATE class deliberately: payment
 * methods (cash / transfer / POS) are the next payment slice and will land on one door before the
 * other, so the two request contracts must be free to diverge.
 */
class RecordAccountPaymentRequest extends FormRequest
{
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
            'amount_minor' => ['required', 'integer', 'min:1'],
            // regex mirrors Money's own ISO-4217 invariant — bad case/format is a 422 here, not the
            // constructor's InvalidArgumentException → 500 (backstop-reachability audit). Refuse, don't repair.
            // Rule::in([DEFAULT_CURRENCY]) — refuse at the edge what the single-currency system cannot
            // process; a well-formed "USD" would otherwise add straight into an NGN balance. The Action
            // (account currency) and SubledgerPoster backstop this. Reverses the f293358 regex steer.
            'currency' => ['sometimes', 'string', Rule::in([Money::DEFAULT_CURRENCY])],
            'payer_name' => ['required', 'string', 'max:255'],
            // THE ACCOUNT THE MONEY LANDED IN. Required with no default — a payment that does not
            // say where the cash went cannot be reconciled against a bank statement, and
            // finance_payments is append-only so it can never be answered later.
            //
            // ACTIVE ONLY, and scoped to the active School. A deactivated account cannot receive new
            // money — that is the whole reason commit 1 chose deactivation over deletion — and the
            // School clause is what makes the composite foreign key's refusal a 422 here rather than
            // a 500 from the database. Historical payments keep pointing at deactivated rows and
            // still render their name; only NEW money is refused.
            'bank_account_id' => [
                'required',
                Rule::exists(BankAccount::class, 'uuid')
                    ->where('school_id', ActiveSchool::id())
                    ->whereNull('deactivated_at'),
            ],
            // REQUIRED, with no default at any layer. A payment's business date is the operator's
            // to state: a receipt handed over on Friday and keyed on Monday belongs to Friday, and
            // finance_payments is append-only so nobody can correct it afterwards. Defaulting it to
            // today at the edge would make "the operator did not say" indistinguishable from "the
            // operator said today", forever. The UI pre-fills today; the API refuses silence.
            'received_at' => ['required', 'date', 'before_or_equal:'.SchoolDay::today()],
            // Required only when the date is not today — U9's spec. required_unless compares the
            // SUBMITTED value, so a back-dated receipt cannot arrive without an explanation and a
            // same-day one is not made to invent one.
            'received_at_reason' => ['nullable', 'required_unless:received_at,'.SchoolDay::today(), 'string', 'max:255'],
        ];
    }
}
