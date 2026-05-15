<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardianImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file'                  => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'update_existing_links' => ['nullable', 'boolean'],
        ];
    }
}
