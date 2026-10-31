<?php

namespace App\Services;

use App\Models\Category;
use App\Repositories\CategoryRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function __construct(private readonly CategoryRepository $categories) {}

    /**
     * @return Collection<int, Category>
     */
    public function list(): Collection
    {
        return $this->categories->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Category
    {
        $data['slug'] = $this->resolveSlug($data);

        return $this->categories->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Category $category, array $data): Category
    {
        $this->ensureParentIsNotDescendant($category, $data['parent_id'] ?? null);

        $data['slug'] = $this->resolveSlug($data, $category);

        return $this->categories->update($category, $data);
    }

    /**
     * @throws ValidationException
     */
    public function delete(Category $category): void
    {
        if ($this->categories->hasChildren($category)) {
            throw ValidationException::withMessages([
                'category' => ['Move or delete the subcategories of this category before deleting it.'],
            ]);
        }

        $this->categories->delete($category);
    }

    /**
     * Use the given slug, or build a unique one from the name when it is blank.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(array $data, ?Category $category = null): string
    {
        if (filled($data['slug'] ?? null)) {
            return $data['slug'];
        }

        // An update that does not mention the slug keeps the current one.
        if ($category && ! array_key_exists('slug', $data)) {
            return $category->slug;
        }

        $base = Str::slug($data['name']) ?: 'category';
        $slug = $base;

        for ($suffix = 2; $this->categories->slugExists($slug, $category?->id); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Moving a category under itself or one of its own subcategories would
     * detach that branch from the tree, so walk up from the new parent to check.
     *
     * @throws ValidationException
     */
    private function ensureParentIsNotDescendant(Category $category, mixed $parentId): void
    {
        $ancestor = $parentId ? $this->categories->find((int) $parentId) : null;

        while ($ancestor) {
            if ($ancestor->is($category)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A category cannot be placed under itself or one of its subcategories.'],
                ]);
            }

            $ancestor = $ancestor->parent;
        }
    }
}
