<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'short_name' => $this->unit->short_name,
                'allow_decimal' => $this->unit->allow_decimal,
            ]),
            // Where it is kept; "code" is built by the backend, such as SHOP-GF-R3-S2.
            'location' => [
                'area' => $this->location_area->value,
                'floor' => $this->location_floor,
                'rack' => $this->location_rack,
                'shelf' => $this->location_shelf,
                'code' => $this->location_code,
            ],
            'hsn_code' => $this->hsn_code,
            'gst_rate' => $this->gst_rate === null ? null : (float) $this->gst_rate,
            // The main image, for thumbnails; "images" holds them all in order.
            'image_url' => $this->whenLoaded('mainImage', fn () => $this->mainImage->url()),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'is_active' => $this->is_active,
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'stock_quantity' => $this->whenLoaded('variants', fn () => $this->totalStock()),
            'stock_value' => $this->whenLoaded('variants', fn () => [
                'cost' => $this->stockCostValue(),
                'selling' => $this->stockSellingValue(),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
