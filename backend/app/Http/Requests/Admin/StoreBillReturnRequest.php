<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethod;
use App\Models\Bill;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillReturnRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['array'],
            'items.*.bill_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('bill_items', 'id')->where('bill_id', $this->bill()->id),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999', 'decimal:0,3'],
            'note' => ['nullable', 'string', 'max:255'],
            // How to give money back, when the return is worth more than what is still due.
            'refund' => ['nullable', 'array'],
            'refund.method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'refund.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items.*.bill_item_id' => 'item',
            'items.*.quantity' => 'quantity',
            'refund.method' => 'refund method',
            'refund.reference' => 'transaction number',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Choose at least one item that is coming back.',
            'items.min' => 'Choose at least one item that is coming back.',
            'items.*.bill_item_id.exists' => 'This item is not on this bill.',
            'items.*.bill_item_id.distinct' => 'This item is listed twice.',
        ];
    }

    private function bill(): Bill
    {
        return $this->route('bill');
    }
}
