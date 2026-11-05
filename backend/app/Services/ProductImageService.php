<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\Product;
use App\Models\ProductImage;
use App\Repositories\ProductImageRepository;
use App\Support\ImageCompressor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageService
{
    public const MAX_IMAGES = 10;

    public function __construct(
        private readonly ProductImageRepository $images,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * Add photos after the product's existing ones.
     *
     * @param  list<UploadedFile>  $files
     * @return Collection<int, ProductImage> All of the product's images, in order.
     *
     * @throws ValidationException
     */
    public function add(Product $product, array $files): Collection
    {
        $existing = $this->images->countFor($product);

        if ($existing + count($files) > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => [sprintf(
                    'A product can have up to %d images, and this one already has %d.',
                    self::MAX_IMAGES,
                    $existing,
                )],
            ]);
        }

        $paths = array_map(fn (UploadedFile $file) => $this->store($file), $files);

        try {
            DB::transaction(function () use ($product, $paths) {
                $position = $this->images->nextSortOrder($product);

                foreach ($paths as $path) {
                    $this->images->create($product, $path, $position++);
                }

                $count = count($paths);
                $this->activity->record(
                    ActivityAction::ProductImagesAdded,
                    "Added {$count} ".($count === 1 ? 'photo' : 'photos')." to {$product->name}",
                    $product,
                );
            });
        } catch (Throwable $e) {
            // Leave no files behind for rows that were never saved.
            $this->deleteFiles($paths);

            throw $e;
        }

        return $this->images->allFor($product);
    }

    /**
     * Put the product's images in the given order; the first becomes the main image.
     *
     * @param  list<int>  $ids  Every image id of the product, each exactly once.
     * @return Collection<int, ProductImage>
     *
     * @throws ValidationException
     */
    public function reorder(Product $product, array $ids): Collection
    {
        $ids = array_map('intval', $ids);
        $current = $this->images->idsFor($product);

        $given = $ids;
        sort($given);
        sort($current);

        if ($given !== $current) {
            throw ValidationException::withMessages([
                'ids' => ['List every image of this product exactly once.'],
            ]);
        }

        DB::transaction(function () use ($product, $ids) {
            $this->images->reorder($product, $ids);
            $this->activity->record(ActivityAction::ProductImagesReordered, "Changed the order of {$product->name}’s photos", $product);
        });

        return $this->images->allFor($product);
    }

    /**
     * @return Collection<int, ProductImage> The product's remaining images.
     */
    public function delete(Product $product, ProductImage $image): Collection
    {
        DB::transaction(function () use ($product, $image) {
            $this->images->delete($image);
            $this->activity->record(ActivityAction::ProductImageRemoved, "Removed a photo from {$product->name}", $product);
        });

        $this->deleteFiles([$image->path]);

        return $this->images->allFor($product);
    }

    /**
     * @return list<string>
     */
    public function pathsFor(Product $product): array
    {
        return $this->images->pathsFor($product);
    }

    /**
     * @param  list<string>  $paths
     */
    public function deleteFiles(array $paths): void
    {
        Storage::disk(ProductImage::DISK)->delete($paths);
    }

    /**
     * Save a photo, made smaller first unless it already is, and return its path.
     */
    private function store(UploadedFile $file): string
    {
        $smaller = ImageCompressor::compress($file->getRealPath());

        if ($smaller === null) {
            return $file->store('products', ProductImage::DISK);
        }

        $path = 'products/'.Str::random(40).'.webp';
        Storage::disk(ProductImage::DISK)->put($path, $smaller);

        return $path;
    }
}
