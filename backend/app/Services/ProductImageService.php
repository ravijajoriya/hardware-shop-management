<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Repositories\ProductImageRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageService
{
    public const MAX_IMAGES = 10;

    public function __construct(private readonly ProductImageRepository $images) {}

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

        $paths = array_map(fn (UploadedFile $file) => $file->store('products', ProductImage::DISK), $files);

        try {
            DB::transaction(function () use ($product, $paths) {
                $position = $this->images->nextSortOrder($product);

                foreach ($paths as $path) {
                    $this->images->create($product, $path, $position++);
                }
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

        DB::transaction(fn () => $this->images->reorder($product, $ids));

        return $this->images->allFor($product);
    }

    /**
     * @return Collection<int, ProductImage> The product's remaining images.
     */
    public function delete(Product $product, ProductImage $image): Collection
    {
        $this->images->delete($image);
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
}
