<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBrandRequest;
use App\Http\Requests\Admin\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\BrandService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BrandController extends Controller
{
    public function __construct(private readonly BrandService $brands) {}

    /**
     * List every brand.
     */
    public function index(): AnonymousResourceCollection
    {
        return BrandResource::collection($this->brands->list());
    }

    /**
     * Create a brand.
     */
    public function store(StoreBrandRequest $request): BrandResource
    {
        return new BrandResource($this->brands->create($request->validated()));
    }

    /**
     * Show a single brand.
     */
    public function show(Brand $brand): BrandResource
    {
        return new BrandResource($brand);
    }

    /**
     * Update a brand.
     */
    public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
    {
        return new BrandResource($this->brands->update($brand, $request->validated()));
    }

    /**
     * Delete a brand.
     */
    public function destroy(Brand $brand): Response
    {
        $this->brands->delete($brand);

        return response()->noContent();
    }
}
