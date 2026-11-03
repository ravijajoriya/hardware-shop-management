<?php

namespace App\Http\Requests\Admin;

use App\Enums\StockCorrectionReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockCorrectionRequest extends FormRequest
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
            // The stock it really is now, not the difference.
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'reason' => ['required', Rule::enum(StockCorrectionReason::class)],
            'note' => [
                Rule::requiredIf($this->input('reason') === StockCorrectionReason::Other->value),
                'nullable', 'string', 'max:255',
            ],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['quantity' => 'stock'];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['note.required' => 'Say what happened, since the reason is "Other".'];
    }
}
