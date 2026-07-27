<?php

namespace App\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Author a fee schedule (S1 commit 2). The route gates on `finance.fee-schedule.manage`; this validates
 * the shape. `term_id`/`class_level_id` are soft references (the schedule carries live FKs to them, but
 * the wire only names ids); `status` is NEVER a wire field — publication is the Action's job, and in
 * commit 4 the approval Action's alone.
 *
 * @property array<int, array<string, mixed>> $items
 */
class FeeScheduleRequest extends FormRequest
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
            'term_id' => ['required', 'integer', 'exists:terms,id'],
            'class_level_id' => ['required', 'integer', 'exists:class_levels,id'],
            'label' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount_minor' => ['required', 'integer', 'min:1'],
            'items.*.currency' => ['sometimes', 'string', 'size:3'],
            'items.*.is_mandatory' => ['sometimes', 'boolean'],
            'items.*.is_discountable' => ['sometimes', 'boolean'],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemSpecs(): array
    {
        return array_values((array) $this->input('items', []));
    }
}
