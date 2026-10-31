<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rules\Unique;

class UpdateBrandRequest extends StoreBrandRequest
{
    /**
     * The brand being updated may keep its own name and slug.
     */
    protected function uniqueRule(string $column): Unique
    {
        return parent::uniqueRule($column)->ignore($this->route('brand'));
    }
}
