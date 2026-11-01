<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductIndexRequest;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\ProductFormOptionsResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /**
     * List products a page at a time, with the stock worth of everything matching the filters.
     */
    public function index(ProductIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return ProductResource::collection($this->products->paginate($filters))
            ->additional(['summary' => $this->products->stockSummary($filters)]);
    }

    /**
     * The categories, brands and units the product form can choose from.
     */
    public function options(): ProductFormOptionsResource
    {
        return new ProductFormOptionsResource($this->products->formOptions());
    }

    /**
     * Create a product with its variants.
     */
    public function store(StoreProductRequest $request): ProductResource
    {
        return new ProductResource($this->products->create($request->validated()));
    }

    /**
     * Show a product with its variants.
     */
    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->products->details($product));
    }

    /**
     * Update a product and replace its list of variants.
     */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource($this->products->update($product, $request->validated()));
    }

    /**
     * Delete a product, its variants and its image.
     */
    public function destroy(Product $product): Response
    {
        $this->products->delete($product);

        return response()->noContent();
    }
}
