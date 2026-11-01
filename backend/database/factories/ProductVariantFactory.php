<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $purchasePrice = fake()->randomFloat(2, 10, 500);

        return [
            'product_id' => Product::factory(),
            'name' => null,
            'sku' => null,
            'purchase_price' => $purchasePrice,
            'selling_price' => round($purchasePrice * 1.25, 2),
            'mrp' => null,
            'stock_quantity' => fake()->numberBetween(0, 100),
        ];
    }
}
