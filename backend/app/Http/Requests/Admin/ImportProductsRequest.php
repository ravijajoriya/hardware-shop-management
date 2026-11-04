<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportProductsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // An Excel file, or a CSV saved from one. A CSV is often reported as plain text.
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
            // Import the products that are ready and leave out those with problems.
            'skip_problems' => ['boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the Excel file to import.',
            'file.file' => 'Choose the Excel file to import.',
            'file.mimes' => 'Use an Excel file (.xlsx or .xls) or a CSV file.',
            'file.max' => 'The file can be up to 5 MB. Split it into smaller files.',
        ];
    }
}
