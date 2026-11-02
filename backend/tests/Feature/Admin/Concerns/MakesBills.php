<?php

namespace Tests\Feature\Admin\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Testing\TestResponse;

trait MakesBills
{
    /**
     * An item in stock, sold by the piece or, with $decimals, by the kilogram.
     */
    protected function itemInStock(
        string $name = 'Claw Hammer',
        float $price = 260,
        float $stock = 100,
        bool $decimals = false,
        ?string $size = null,
    ): ProductVariant {
        $unit = $decimals
            ? Unit::firstOrCreate(['short_name' => 'kg'], ['name' => 'Kilogram', 'allow_decimal' => true])
            : Unit::firstOrCreate(['short_name' => 'pc'], ['name' => 'Piece', 'allow_decimal' => false]);

        return ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $name, 'unit_id' => $unit->id]))
            ->create(['name' => $size, 'selling_price' => $price, 'stock_quantity' => $stock]);
    }

    /**
     * Bill a customer. Each line is [variant, quantity] or [variant, quantity, price];
     * without a price the item's selling price is used.
     *
     * @param  list<array{0: ProductVariant, 1: float|int, 2?: float|int}>  $lines
     * @param  list<array<string, mixed>>  $payments
     */
    protected function postBill(
        string $name,
        string $mobile,
        array $lines,
        array $payments = [],
        ?string $address = null,
    ): TestResponse {
        return $this->postJson('/api/admin/bills', [
            'customer' => ['name' => $name, 'mobile' => $mobile, 'address' => $address],
            'items' => array_map(fn (array $line) => [
                'product_variant_id' => $line[0]->id,
                'quantity' => $line[1],
                'price' => $line[2] ?? (float) $line[0]->selling_price,
            ], $lines),
            'payments' => $payments,
        ]);
    }
}
