<?php

namespace App\Repositories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Collection;

class BrandRepository
{
    /**
     * @return Collection<int, Brand>
     */
    public function all(): Collection
    {
        return Brand::orderBy('name')->get();
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return Brand::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    public function hasProducts(Brand $brand): bool
    {
        return $brand->products()->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Brand
    {
        return Brand::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Brand $brand, array $attributes): Brand
    {
        $brand->update($attributes);

        return $brand;
    }

    public function delete(Brand $brand): void
    {
        $brand->delete();
    }
}
