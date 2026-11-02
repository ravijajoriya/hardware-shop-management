<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethod;
use App\Support\MobileNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillRequest extends FormRequest
{
    private const MAX_AMOUNT = 9999999999.99;

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
        $amount = ['numeric', 'max:'.self::MAX_AMOUNT, 'decimal:0,2'];

        return [
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.mobile' => ['required', 'string', 'regex:'.MobileNumber::PATTERN],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['array'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999', 'decimal:0,3'],
            'items.*.price' => ['required', 'min:0', ...$amount],

            // Left empty when nothing is paid now; the whole bill is then due.
            'payments' => ['nullable', 'array', 'max:10'],
            'payments.*' => ['array'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'gt:0', ...$amount],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
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
            'customer.name' => 'customer name',
            'customer.mobile' => 'mobile number',
            'items.*.product_variant_id' => 'item',
            'items.*.quantity' => 'quantity',
            'items.*.price' => 'price',
            'payments.*.method' => 'payment method',
            'payments.*.amount' => 'amount',
            'payments.*.reference' => 'transaction number',
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
            'customer.mobile.regex' => 'Enter a 10-digit mobile number, such as 98765 43210.',
            'items.required' => 'Add at least one item to the bill.',
            'items.min' => 'Add at least one item to the bill.',
            'items.*.product_variant_id.distinct' => 'This item is on the bill twice. Change its quantity instead.',
            'items.*.product_variant_id.exists' => 'This item no longer exists. Remove it from the bill.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $customer = $this->input('customer');

        // "+91 98765-43210" and "098765 43210" are the same number as "9876543210".
        if (is_array($customer) && is_string($customer['mobile'] ?? null)) {
            $this->merge(['customer' => [...$customer, 'mobile' => MobileNumber::normalize($customer['mobile'])]]);
        }
    }
}
