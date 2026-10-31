<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rules\Unique;

class UpdateCategoryRequest extends StoreCategoryRequest
{
    /**
     * The category being updated may keep its own slug.
     */
    protected function uniqueSlugRule(): Unique
    {
        return parent::uniqueSlugRule()->ignore($this->route('category'));
    }
}
