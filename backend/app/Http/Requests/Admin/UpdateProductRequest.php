<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdateProductRequest extends StoreProductRequest
{
    /**
     * The product being updated may keep its own slug.
     */
    protected function uniqueSlugRule(): Unique
    {
        return parent::uniqueSlugRule()->ignore($this->product());
    }

    /**
     * SKUs only clash with other products' variants; this product's own variants
     * are checked against each other by the distinct rule.
     */
    protected function uniqueSkuRule(): Unique
    {
        return parent::uniqueSkuRule()->where(fn ($query) => $query->where('product_id', '!=', $this->product()->id));
    }

    /**
     * Existing variants are sent back with their id; new ones without.
     *
     * @return array<int, mixed>
     */
    protected function variantIdRules(): array
    {
        return [
            'nullable', 'integer', 'distinct',
            Rule::exists('product_variants', 'id')->where('product_id', $this->product()->id),
        ];
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
