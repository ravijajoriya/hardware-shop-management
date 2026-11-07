<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethod;
use App\Models\Bill;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * A bill as it should be after an edit: the same customer and items fields as a new bill, with
 * each existing line sent back with its id. Payments are not part of an edit.
 */
class UpdateBillRequest extends StoreBillRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['payments'], $rules['payments.*'], $rules['payments.*.method'], $rules['payments.*.amount'], $rules['payments.*.reference']);

        return [
            ...$rules,
            'items.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('bill_items', 'id')->where('bill_id', $this->bill()->id),
            ],
            // An existing line keeps its item, so only new lines need to say which item they are.
            'items.*.product_variant_id' => [
                'required_without:items.*.id', 'nullable', 'integer', Rule::exists('product_variants', 'id'),
            ],
            // How to give money back if the new total is less than what was paid.
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
            ...parent::attributes(),
            'items.*.id' => 'bill line',
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
            ...parent::messages(),
            'items.*.id.exists' => 'This line is not on this bill.',
        ];
    }

    private function bill(): Bill
    {
        return $this->route('bill');
    }
}
