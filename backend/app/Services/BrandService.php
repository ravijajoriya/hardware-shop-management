<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\Brand;
use App\Repositories\BrandRepository;
use App\Support\ActivityChanges;
use App\Support\ActivitySnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BrandService
{
    public function __construct(
        private readonly BrandRepository $brands,
        private readonly SlugService $slugs,
        private readonly ActivityLogService $activity,
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

        // Also used for a brand typed into the product form, inside the product's transaction.
        return DB::transaction(function () use ($data) {
            $brand = $this->brands->create($data);

            $this->activity->record(
                ActivityAction::BrandCreated,
                "Added brand {$brand->name}",
                $brand,
                ActivityChanges::between([], ActivitySnapshot::brand($brand)),
            );

            return $brand;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Brand $brand, array $data): Brand
    {
        $data['slug'] = $this->resolveSlug($data, $brand);
        $before = ActivitySnapshot::brand($brand);

        return DB::transaction(function () use ($brand, $data, $before) {
            $brand = $this->brands->update($brand, $data);
            $changes = ActivityChanges::between($before, ActivitySnapshot::brand($brand));

            // Saving without changing anything is not worth an entry.
            if ($changes) {
                $this->activity->record(
                    ActivityAction::BrandUpdated,
                    "Changed brand {$brand->name}: ".ActivityChanges::summary($changes),
                    $brand,
                    $changes,
                );
            }

            return $brand;
        });
    }

    /**
     * @throws ValidationException
     */
    public function delete(Brand $brand): void
    {
        if ($this->brands->hasProducts($brand)) {
            throw ValidationException::withMessages([
                'brand' => ['Change the brand of the products that use it before deleting it.'],
            ]);
        }

        $before = ActivitySnapshot::brand($brand);

        DB::transaction(function () use ($brand, $before) {
            $this->brands->delete($brand);

            $this->activity->record(
                ActivityAction::BrandDeleted,
                "Deleted brand {$brand->name}",
                $brand,
                ActivityChanges::between($before, []),
            );
        });
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
