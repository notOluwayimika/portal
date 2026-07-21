<?php

namespace App\Finance\Http\Requests;

use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceLineKind;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization is by route middleware (role:admin|super_admin) for the skeleton —
 * Finance Policies + maker-checker are Ph2/Ph3. Inline ->hasRole() is banned inside
 * app/Finance by the boundary lint, which is exactly why authz stays at the edge.
 *
 * The wire carries LINES, never a total (F6): amounts are integer minor units
 * (ADR 0037), never decimals, and the invoice total is derived server-side from
 * the lines. There is deliberately no `total` field to accept.
 */
class GenerateInvoiceRequest extends FormRequest
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
            'enrollment_id' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            // No `min:1` any more: a reduction is legitimately negative. The SIGN rule
            // is enforced per-kind in the Action (where the domain rule lives), so the
            // edge only rejects the one value that is meaningless for either kind.
            'lines.*.amount_minor' => ['required', 'integer', 'not_in:0'],
            'lines.*.kind' => ['sometimes', Rule::enum(InvoiceLineKind::class)],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines.*.currency' => ['sometimes', 'string', 'size:3'],
            'lines.*.fee_item_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * Typed, validated line specs for the Action — the FormRequest is where the
     * wire becomes domain vocabulary, so the Action never sees raw request data.
     *
     * @return list<InvoiceLineSpec>
     */
    public function lineSpecs(): array
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->input('lines', []);

        return array_values(array_map(
            static fn (array $line) => new InvoiceLineSpec(
                (string) $line['description'],
                Money::fromKobo(
                    (int) $line['amount_minor'],
                    (string) ($line['currency'] ?? Money::DEFAULT_CURRENCY),
                ),
                isset($line['fee_item_id']) ? (int) $line['fee_item_id'] : null,
                isset($line['kind'])
                    ? InvoiceLineKind::from((string) $line['kind'])
                    : InvoiceLineKind::Charge,
                isset($line['note']) ? (string) $line['note'] : null,
            ),
            $lines,
        ));
    }
}
