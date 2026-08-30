<?php

namespace App\Finance\Http\Requests;

use App\Finance\Actions\AwardStudentDiscount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The upload of a BSS discount-award list. The route gates on `finance.discount-award.manage`, and
 * {@see AwardStudentDiscount} gates on it again per row — see that Action for
 * why one gate is not enough.
 *
 * ONE FIELD, AND THAT IS THE DESIGN. The opening-balance upload also takes a control total, a
 * closing term and an as-at date, because a cutover posts money and needs an attestation and a
 * period. This posts nothing: it writes standing configuration whose terms were approved elsewhere,
 * so a second field here would be a control nobody could act on.
 *
 * `authorize()` RETURNS TRUE and the route holds the permission — the shape every Finance request in
 * this module takes. That is deliberately NOT the whole gate: a FormRequest is a request-path
 * artefact, and the authority to award has to hold for a caller that never passes through one.
 *
 * NO `NormalizesImportRows`. That trait stringifies numeric cells in a JSON array of rows posted by
 * a client-side spreadsheet parser; this uploads the file itself and the CSV reader yields strings
 * already. Applying it here would be cargo.
 */
class StoreDiscountAwardImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `csv` and `txt` because that is what a CSV arrives as: browsers send text/csv,
            // text/plain or application/vnd.ms-excel for the same bytes depending on the OS.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // `mimes` renders "The file must be a file of type: csv, txt", which tells an operator
            // nothing on the day they need it — and the mistake it catches is the one this flow
            // invites: the template is opened in Excel and saved back as a workbook. So the refusal
            // names the cause AND both ways out of it.
            'file.mimes' => 'This import reads CSV only. If you opened the template in Excel, use File → Save As and choose CSV, or download the CSV template from the button above and fill that.',
            'file.required' => 'Choose the discount-award list to upload. Download the template first if you have not filled one in.',
        ];
    }
}
