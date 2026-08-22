<?php

namespace App\Finance\Http\Requests;

use App\Finance\Actions\AllocatePayment;
use App\Finance\Exceptions\AllocationRefused;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Every rule below can be decided from the request body alone; NOTHING here reads the
 * database, and that is deliberate rather than lazy.
 *
 * WHY THE INTERESTING RULES ARE NOT HERE. A FormRequest runs before the transaction, so any check it
 * made against the payment's remaining headroom or an invoice's outstanding would be answering from a
 * snapshot the write does not use — green here and refused three milliseconds later at the trigger,
 * or worse, green here and wrong. {@see AllocatePayment} makes those refusals
 * under the student-account row lock, where the numbers are the ones the write will actually use, and
 * {@see AllocationRefused} carries the field name so they still render on the
 * row they are about. `Rule::exists` on the invoice id is absent for the same reason: the Action
 * already establishes that every id is one the proposal offered, which is a strictly stronger
 * statement than "a row with this uuid exists".
 */
class AllocatePaymentRequest extends FormRequest
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
            // The proposal token this screen was rendered from — 64 lowercase hex characters of
            // sha256. Required with no default: without it the Action cannot tell an operator's edit
            // from the position moving underneath them, and would stamp a false override marker onto
            // an append-only row. See AllocationProposal::fingerprint.
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],

            // A LIST, not a uuid-keyed map, so a refusal can name `allocations.2.amount_minor` and the
            // screen can put the message on that row. The screen posts EVERY offered invoice,
            // including the ones it is allocating nothing to, because a zero is how an operator says
            // "not this one" and the Action needs to see it to compare against the proposal.
            'allocations' => ['required', 'array', 'min:1'],

            // The uuid, never the integer key — the wire has not carried an invoice's primary key
            // since U8 commit 1. Whether it is an invoice this payment may settle is the Action's
            // question, under the lock.
            'allocations.*.invoice_id' => ['required', 'uuid'],

            // Integer MINOR UNITS, never a decimal (ADR 0037/0039). `min:0` and not `min:1`: zero is a
            // meaningful direction and the Action writes no row for it. A negative is refused here AND
            // again in the Action, because the Action is also reachable off-HTTP.
            //
            // `integer:strict`, AND THE `:strict` IS THE WHOLE RULE. Plain `integer` is
            // `filter_var($value, FILTER_VALIDATE_INT) !== false`
            // (Illuminate\Validation\Concerns\ValidatesAttributes::validateInteger), so the JSON STRING
            // "3000" passes it and arrives as a string. Nothing downstream cast it, and the Action
            // decides `allocation_overridden` with `!==` — so `"3000" !== 3000` was TRUE and a
            // submission identical to the proposal was recorded as an override the operator never made,
            // with a reason they were wrongly compelled to invent, on a table that has no UPDATE.
            // `:strict` is `is_int($value)` and refuses the string at the edge.
            //
            // THE ACTION CASTS TOO, and that is not belt-and-braces. This rule protects the HTTP door
            // only, and AllocatePayment's own docblock says it is reachable off-HTTP; a job or a console
            // command handing it `['amount_minor' => '3000']` would reach the same comparison with no
            // FormRequest anywhere in the path. Each guard covers callers the other cannot see.
            'allocations.*.amount_minor' => ['required', 'integer:strict', 'min:0'],

            // Required only when the submission departs from the proposal, which cannot be known until
            // the proposal is re-derived under the lock — so it is nullable here and REQUIRED there.
            // max:255 mirrors the column (`allocation_override_reason` is a varchar(255)); letting a
            // longer string through would be a truncation on an append-only row, which is a reason
            // that says something other than what the operator wrote.
            'override_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
