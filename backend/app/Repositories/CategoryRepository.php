<?php

namespace App\Repositories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository
{
    /**
     * @return Collection<int, Category>
     */
    public function all(): Collection
    {
        return Category::orderBy('name')->get();
    }

    public function find(int $id): ?Category
    {
        return Category::find($id);
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return Category::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    public function hasChildren(Category $category): bool
    {
        return $category->children()->exists();
    }

    public function hasProducts(Category $category): bool
    {
        return $category->products()->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Category
    {
        return Category::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        $category->update($attributes);

        return $category;
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }
}
