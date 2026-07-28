<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Propose a fee-schedule change (S1 commit 4). The route gates on finance.fee-schedule.change.submit;
 * this validates the wire shape. `target` is the target schedule's uuid (always required — a publish names
 * the draft, a retire names the active schedule); the controller resolves it under SchoolScope. There is
 * no proposed-terms payload: the terms ARE the draft's items (9.1's (c) choice), never carried inline.
 */
class SubmitFeeScheduleChangeRequest extends FormRequest
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
            'kind' => ['required', 'in:publish,retire'],
            'target' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
