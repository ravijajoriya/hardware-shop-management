<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreProductRequest extends FormRequest
{
    private const MAX_PRICE = 9999999999.99;

    private const MAX_STOCK = 999999999.999;

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
        $price = ['numeric', 'min:0', 'max:'.self::MAX_PRICE, 'decimal:0,2'];

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            // A brand that is not in the list yet; it is created along with the product.
            'new_brand_name' => ['nullable', 'string', 'max:255', 'prohibits:brand_id', Rule::unique('brands', 'name')],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->uniqueSlugRule()],
            'description' => ['nullable', 'string', 'max:2000'],
            'hsn_code' => ['nullable', 'string', 'regex:/^(\d{4}|\d{6}|\d{8})$/'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'is_active' => ['boolean'],

            'variants' => ['required', 'array', 'min:1', 'max:50'],
            'variants.*' => ['array'],
            'variants.*.id' => $this->variantIdRules(),
            // A product sold in one form needs no variant name; with several, each needs one.
            'variants.*.name' => [
                Rule::requiredIf(fn () => count((array) $this->input('variants')) > 1),
                'nullable', 'string', 'max:100', 'distinct:ignore_case',
            ],
            'variants.*.sku' => ['nullable', 'string', 'max:50', 'distinct:ignore_case', $this->uniqueSkuRule()],
            'variants.*.purchase_price' => ['required', ...$price],
            'variants.*.selling_price' => ['required', ...$price],
            'variants.*.mrp' => ['nullable', ...$price, 'gte:variants.*.selling_price'],
            'variants.*.stock_quantity' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_STOCK, 'decimal:0,3'],
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
            'category_id' => 'category',
            'brand_id' => 'brand',
            'new_brand_name' => 'brand name',
            'unit_id' => 'unit',
            'hsn_code' => 'HSN code',
            'gst_rate' => 'GST rate',
            'variants.*.name' => 'variant name',
            'variants.*.sku' => 'SKU',
            'variants.*.purchase_price' => 'purchase price',
            'variants.*.selling_price' => 'selling price',
            'variants.*.mrp' => 'MRP',
            'variants.*.stock_quantity' => 'stock',
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
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and hyphens.',
            'new_brand_name.unique' => 'This brand already exists. Choose it from the brand list.',
            'new_brand_name.prohibits' => 'Choose a brand from the list or type a new one, not both.',
            'hsn_code.regex' => 'The HSN code must be 4, 6 or 8 digits.',
            'variants.required' => 'Add at least one variant with its prices and stock.',
            'variants.min' => 'Add at least one variant with its prices and stock.',
            'variants.*.name.required' => 'Give each variant a name, such as its size or colour.',
            'variants.*.name.distinct' => 'Each variant needs a different name.',
            'variants.*.sku.distinct' => 'Each variant needs a different SKU.',
            'variants.*.mrp.gte' => 'The MRP cannot be lower than the selling price.',
        ];
    }

    protected function uniqueSlugRule(): Unique
    {
        return Rule::unique('products', 'slug');
    }

    protected function uniqueSkuRule(): Unique
    {
        return Rule::unique('product_variants', 'sku');
    }

    /**
     * A new product's variants are all new.
     *
     * @return array<int, mixed>
     */
    protected function variantIdRules(): array
    {
        return ['prohibited'];
    }
}
