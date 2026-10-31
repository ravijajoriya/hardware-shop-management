<?php

namespace App\Services;

use App\Models\Brand;
use App\Repositories\BrandRepository;
use Illuminate\Database\Eloquent\Collection;

class BrandService
{
    public function __construct(
        private readonly BrandRepository $brands,
        private readonly SlugService $slugs,
    ) {}

    /**
     * @return Collection<int, Brand>
     */
    public function list(): Collection
    {
        return $this->brands->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Brand
    {
        $data['slug'] = $this->resolveSlug($data);

        return $this->brands->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Brand $brand, array $data): Brand
    {
        $data['slug'] = $this->resolveSlug($data, $brand);

        return $this->brands->update($brand, $data);
    }

    public function delete(Brand $brand): void
    {
        $this->brands->delete($brand);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(array $data, ?Brand $brand = null): string
    {
        return $this->slugs->resolve(
            $data,
            $brand?->slug,
            fn (string $slug) => $this->brands->slugExists($slug, $brand?->id),
            'brand',
        );
    }
}
