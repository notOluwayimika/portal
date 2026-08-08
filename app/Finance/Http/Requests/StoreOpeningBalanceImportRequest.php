<?php

namespace App\Finance\Http\Requests;

use App\Finance\Services\OpeningBalanceFileValidator;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * §9 step 5b-iii — the operator's upload. The route gates on `finance.opening-balance.submit`.
 *
 * THE CONTROL TOTAL IS THE ONLY FIELD HERE THAT IS NOT ADMINISTRATIVE, and it is required for the
 * reason §12 decision 2 gives: it is the operator's ATTESTATION, read off WCBS's own report and
 * typed by the person doing the upload. A total carried inside the FILE was produced by the same
 * export run as the rows — drop a student on the way out of WCBS and they vanish from the rows AND
 * from the total, the two still agree, and §1's L2 goes green on an incomplete file. A witness that
 * shares a failure mode with the thing it witnesses is not a witness. That is why this is a form
 * field and not a column, and why the form says so to the operator rather than only to the code.
 *
 * SIGNED, like `balance`: a school whose students are net in credit has a negative Σ, and a
 * non-negative rule here would refuse the file rather than the mistake.
 *
 * THE REFERENCE'S LENGTH RULE IS THE VALIDATOR'S CONSTANT, not a number retyped. It is snapshotted
 * onto every migrated payment's `payer_name` at posting, so a long-but-legal reference would stage
 * green and abort the post at 1406 on cutover day.
 */
class StoreOpeningBalanceImportRequest extends FormRequest
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

            // Naira with up to two decimals, signed. Format-checked here and PARSED in the
            // controller through Money::fromNaira, which is the only sanctioned reader.
            'control_total' => ['required', 'string', 'regex:/^-?\d+(\.\d{1,2})?$/'],

            // The term being CLOSED OUT — the last term, whose closing position the file carries.
            // Scoped to the active School, so a foreign term id is a validation failure and not a
            // batch that references another school's calendar.
            'closing_term' => [
                'required', 'integer',
                Rule::exists('terms', 'id')->where('school_id', ActiveSchool::id()),
            ],

            'as_at' => ['required', 'date_format:Y-m-d'],

            'batch_reference' => ['nullable', 'string', 'max:'.OpeningBalanceFileValidator::BATCH_REFERENCE_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // `mimes` renders "The file must be a file of type: csv, txt", which tells an operator
            // nothing on the day they need it — and the mistake it catches is the one the flow
            // invites: a data team opens the CSV in Excel and saves it back as a workbook. So the
            // refusal names the cause AND both ways out of it.
            'file.mimes' => 'This import reads CSV only. If you opened the template in Excel, use File → Save As and choose CSV, or download the CSV template from the button above and fill that.',
            'control_total.required' => 'The control total is required: Σ of every student\'s stated total, read off WCBS\'s own report (§1 L2).',
            'control_total.regex' => 'The control total must be naira with up to two decimal places. It may be negative if the school is net in credit.',
            'batch_reference.max' => 'The batch reference is snapshotted onto every migrated payment at posting, so it cannot exceed '.OpeningBalanceFileValidator::BATCH_REFERENCE_MAX.' characters.',
        ];
    }

    /**
     * The attestation as money. Parsed here so the controller never re-reads the raw string, and
     * through Money::fromNaira so the digits are read by integer string arithmetic rather than by a
     * float multiplication that turns "80000.15" into 8000014 kobo.
     */
    public function controlTotal(): Money
    {
        return Money::fromNaira(trim((string) $this->input('control_total')));
    }

    /**
     * The batch reference, defaulted to the uploaded filename exactly as the console defaults it to
     * the file's basename — §7's idempotency key either way, enforced by
     * `unique(school_id, batch_reference)` at the engine.
     */
    public function batchReference(): string
    {
        $given = trim((string) $this->input('batch_reference', ''));

        if ($given !== '') {
            return $given;
        }

        $name = (string) $this->file('file')->getClientOriginalName();

        // Defaulted, so it can exceed the limit the rule above only applies to an EXPLICIT value.
        // Truncating would produce two different files under one reference; refusing is the same
        // answer the console gives, and it is raised as a validation error rather than thrown.
        return mb_substr($name, 0, OpeningBalanceFileValidator::BATCH_REFERENCE_MAX);
    }

    protected function prepareForValidation(): void
    {
        // A control total typed with thousands separators or a currency symbol is the single most
        // likely operator slip on this form, and rejecting "₦1,250,000.00" as "not naira" would be
        // technically true and useless. Normalised here so the rule sees digits.
        $raw = $this->input('control_total');

        if (is_string($raw)) {
            $this->merge(['control_total' => str_replace(['₦', ',', ' '], '', trim($raw))]);
        }
    }

    /** Belt and braces: the regex above is the gate, this converts a slip into a validation error. */
    protected function passedValidation(): void
    {
        try {
            $this->controlTotal();
        } catch (InvalidArgumentException) {
            abort(422, 'The control total is not naira with up to two decimal places.');
        }
    }
}
