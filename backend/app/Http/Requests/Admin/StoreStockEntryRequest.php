<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockEntryRequest extends FormRequest
{
    private const MAX_PRICE = 9999999999.99;

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
        // "Today" by the shop's clock, so an early-morning delivery is not taken for tomorrow's.
        $today = now(config('shop.timezone'))->toDateString();

        return [
            'supplier_name' => ['nullable', 'string', 'max:100'],
            'supplier_bill_number' => ['nullable', 'string', 'max:50'],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', "before_or_equal:{$today}"],
            'note' => ['nullable', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['array'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999', 'decimal:0,3'],
            'items.*.purchase_price' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_PRICE, 'decimal:0,2'],
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
            'supplier_name' => 'supplier',
            'supplier_bill_number' => 'supplier bill number',
            'received_on' => 'date received',
            'items.*.product_variant_id' => 'item',
            'items.*.quantity' => 'quantity',
            'items.*.purchase_price' => 'purchase price',
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
            'received_on.before_or_equal' => 'The date received cannot be after today.',
            'items.required' => 'Add at least one item that came in.',
            'items.min' => 'Add at least one item that came in.',
            'items.*.product_variant_id.distinct' => 'This item is on the list twice. Change its quantity instead.',
            'items.*.product_variant_id.exists' => 'This item no longer exists. Remove it from the list.',
        ];
    }
}
