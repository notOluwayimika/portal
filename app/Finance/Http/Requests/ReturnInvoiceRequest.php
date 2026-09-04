<?php

namespace App\Finance\Http\Requests;

use App\Finance\Actions\ReturnInvoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * INTERNAL AUDIT RETURNS ONE BILL TO FINANCE — the field-level half of the refusal.
 *
 * The permission is gated by route middleware (`permission:finance.invoice.reject`, declared
 * explicitly on the route because the enclosing group gates on `approve` — see
 * routes/endpoints/internal-audit.php). This class validates the payload; `ReturnInvoice` refuses a
 * reasonless or over-long return as a backstop, because it is callable off-request. Same division
 * as `RejectVoidRequestRequest`, which this is shaped on.
 *
 * ── THE MAX CITES THE ACTION'S CONSTANT, AND THAT DEVIATES FROM THE SIBLINGS ON PURPOSE ─────────
 *
 * `RejectVoidRequestRequest` and its siblings write `'max:255'` as a literal. This one writes
 * `'max:'.ReturnInvoice::REASON_MAX`, and the difference is not style: those classes have no domain
 * constant to cite, whereas `ReturnInvoice::REASON_MAX` exists and already refuses a longer reason
 * with its own sentence.
 *
 * TWO INDEPENDENT 255s CAN DIVERGE, AND THE FAILURE IS NOT A CRASH. If the column widened and only
 * one number moved, the operator would be refused by the ACTION — "the return reason is 300
 * characters; the limit is 255" — instead of by a field error on `reason`. A domain sentence
 * arriving where a form error belongs looks like a bug to the person reading it, and it arrives
 * without the field name a form needs to highlight anything. Citing the constant makes that
 * divergence unrepresentable.
 *
 * ── WHITESPACE IS REFUSED BY `required`, MEASURED RATHER THAN ASSUMED ──────────────────────────
 *
 * `bootstrap/app.php` does not DECLARE `TrimStrings`; it arrives from the framework's default
 * global stack (Illuminate\Foundation\Configuration\Middleware:461-462), together with
 * `ConvertEmptyStringsToNull`. So a reason of three spaces is trimmed to `''` and then converted to
 * `null` before this class sees it, and `required` refuses with a field error on `reason`.
 *
 * That chain was VERIFIED BY POSTING ONE, not read off the default list — a framework default is a
 * premise, and the arm that posts three spaces is what turns it into a reading. `ReturnInvoice`'s
 * own trim-and-refuse therefore remains a genuine backstop rather than the only guard, which is what
 * it must be for a caller that never passes through HTTP.
 */
class ReturnInvoiceRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:1', 'max:'.ReturnInvoice::REASON_MAX],
        ];
    }
}
