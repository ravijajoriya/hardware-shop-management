<?php

namespace App\Http\Resources;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The choices offered by the product form.
 *
 * @property array{categories: iterable<Category>, brands: iterable<Brand>, units: iterable<Unit>} $resource
 */
class ProductFormOptionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'categories' => collect($this->resource['categories'])->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'depth' => $category->depth,
                'is_active' => $category->is_active,
            ])->values(),
            'brands' => collect($this->resource['brands'])->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'is_active' => $brand->is_active,
            ])->values(),
            'units' => collect($this->resource['units'])->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'short_name' => $unit->short_name,
                'allow_decimal' => $unit->allow_decimal,
                'is_active' => $unit->is_active,
            ])->values(),
        ];
    }
}
