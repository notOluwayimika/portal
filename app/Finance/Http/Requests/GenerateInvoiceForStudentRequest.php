<?php

namespace App\Finance\Http\Requests;

/**
 * Generate an invoice for a STUDENT (the route carries {student:uuid}) — so unlike
 * GenerateInvoiceRequest there is no `enrollment_id` on the wire: the current billable
 * episode is resolved server-side via the ACL port. Everything else (the LINES contract,
 * lineSpecs(), the F6 "no total on the wire" rule) is identical, so this reuses the parent
 * and only drops the enrollment_id rule.
 */
class GenerateInvoiceForStudentRequest extends GenerateInvoiceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['enrollment_id']);

        return $rules;
    }
}
