<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderProductImagesRequest;
use App\Http\Requests\Admin\UploadProductImagesRequest;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductImageController extends Controller
{
    public function __construct(private readonly ProductImageService $images) {}

    /**
     * Add photos after the product's existing ones and return them all.
     */
    public function store(UploadProductImagesRequest $request, Product $product): JsonResponse
    {
        return ProductImageResource::collection($this->images->add($product, $request->file('images')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Put the product's photos in a new order; the first becomes the main image.
     */
    public function reorder(ReorderProductImagesRequest $request, Product $product): AnonymousResourceCollection
    {
        return ProductImageResource::collection($this->images->reorder($product, $request->validated('ids')));
    }

    /**
     * Delete one photo and return the rest.
     */
    public function destroy(Product $product, ProductImage $image): AnonymousResourceCollection
    {
        return ProductImageResource::collection($this->images->delete($product, $image));
    }
}
