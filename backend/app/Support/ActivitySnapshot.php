<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;

/**
 * Records as the activity log keeps them: each field's label and value written the way people
 * read it (₹380, 2.5 kg, Yes), so old entries stay readable whatever changes later.
 * Compare two snapshots with ActivityChanges::between().
 */
final class ActivitySnapshot
{
    /**
     * @return array<string, array{label: string, value: string|null}>
     */
    public static function product(Product $product): array
    {
        // Reloaded, so a snapshot taken after a save sees the saved category, brand and sizes.
        $product->load(['category', 'brand', 'unit', 'packUnit', 'variants']);
        $packSize = $product->packSize();
        $pack = $packSize === null ? null : $product->packUnit->name;

        $fields = [
            'name' => ['Name', $product->name],
            'category' => ['Category', $product->category?->name],
            'brand' => ['Brand', $product->brand?->name],
            'unit' => ['Unit', $product->unit ? "{$product->unit->name} ({$product->unit->short_name})" : null],
            'pack' => ['Bought in', $pack === null ? null : "{$pack} of ".self::quantity($packSize, $product->unit?->short_name)],
            'location' => ['Location', $product->location_code],
            'hsn_code' => ['HSN code', $product->hsn_code],
            'gst_rate' => ['GST rate', $product->gst_rate === null ? null : self::number($product->gst_rate).'%'],
            'description' => ['Description', $product->description],
            'active' => ['Active', self::yesNo($product->is_active)],
        ];

        // With several sizes each field says which size it is: "Selling price (1 L)".
        $sized = $product->variants->count() > 1;
        $unit = $product->unit?->short_name;

        foreach ($product->variants as $variant) {
            $of = $sized || $variant->name ? ' ('.($variant->name ?? 'unnamed size').')' : '';
            $key = "variant.{$variant->id}";

            $fields += [
                "{$key}.name" => ["Size name{$of}", $variant->name],
                "{$key}.sku" => ["SKU{$of}", $variant->sku],
                // Bought in packs, its cost reads per pack, the way it was entered.
                "{$key}.purchase_price" => $pack === null
                    ? ["Purchase price{$of}", self::money($variant->purchase_price)]
                    : ["Purchase price per {$pack}{$of}", self::money(round((float) $variant->purchase_price * $packSize, 2))],
                "{$key}.selling_price" => ["Selling price{$of}", self::money($variant->selling_price)],
                "{$key}.pack_price" => [($pack ?? 'Pack')." price{$of}", $pack === null ? null : self::money($variant->fullPackPrice($product))],
                "{$key}.mrp" => ["MRP{$of}", self::money($variant->mrp)],
                "{$key}.stock" => ["Stock{$of}", self::quantity($variant->stock_quantity, $unit)],
                "{$key}.min_stock" => ["Minimum stock{$of}", $variant->min_stock === null ? null : self::quantity($variant->min_stock, $unit)],
            ];
        }

        return self::fields($fields);
    }

    /**
     * @return array<string, array{label: string, value: string|null}>
     */
    public static function category(Category $category): array
    {
        $category->load('parent');

        return self::fields([
            'name' => ['Name', $category->name],
            'parent' => ['Parent category', $category->parent?->name],
            'slug' => ['Slug', $category->slug],
            'description' => ['Description', $category->description],
            'active' => ['Active', self::yesNo($category->is_active)],
        ]);
    }

    /**
     * @return array<string, array{label: string, value: string|null}>
     */
    public static function brand(Brand $brand): array
    {
        return self::fields([
            'name' => ['Name', $brand->name],
            'slug' => ['Slug', $brand->slug],
            'description' => ['Description', $brand->description],
            'active' => ['Active', self::yesNo($brand->is_active)],
        ]);
    }

    /**
     * A bill's customer details, each line ("Claw Hammer: 2 pc × ₹350 = ₹700") and total.
     *
     * @return array<string, array{label: string, value: string|null}>
     */
    public static function bill(Bill $bill): array
    {
        // Reloaded, so a snapshot taken after an edit sees the saved lines.
        $bill->load('items');

        $fields = [
            'customer_name' => ['Customer name', $bill->customer_name],
            'customer_mobile' => ['Mobile', MobileNumber::format($bill->customer_mobile)],
            'customer_address' => ['Address', $bill->customer_address],
        ];

        foreach ($bill->items as $item) {
            $fields["item.{$item->id}"] = [
                $item->variant_name ? "{$item->product_name} ({$item->variant_name})" : $item->product_name,
                self::quantity($item->quantity, $item->unit).' × '.self::money($item->price).' = '.self::money($item->line_total),
            ];
        }

        $fields['total'] = ['Total', self::money($bill->total)];

        return self::fields($fields);
    }

    /**
     * @return array<string, array{label: string, value: string|null}>
     */
    public static function customer(User $customer): array
    {
        return self::fields([
            'name' => ['Name', $customer->name],
            'mobile' => ['Mobile', $customer->mobile ? MobileNumber::format($customer->mobile) : null],
            'address' => ['Address', $customer->address],
        ]);
    }

    public static function money(string|float|int|null $rupees): ?string
    {
        return $rupees === null ? null : Money::format(Money::toPaise($rupees));
    }

    /**
     * "2.5 kg", "20 pc": up to three decimals, without trailing zeros.
     */
    public static function quantity(string|float|int|null $quantity, ?string $unit): ?string
    {
        if ($quantity === null) {
            return null;
        }

        return trim(self::number($quantity).' '.$unit);
    }

    public static function number(string|float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    private static function yesNo(?bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    /**
     * Blank text counts as no value, so "" and null are not shown as a change.
     *
     * @param  array<string, array{0: string, 1: string|null}>  $fields
     * @return array<string, array{label: string, value: string|null}>
     */
    private static function fields(array $fields): array
    {
        return array_map(fn (array $field) => [
            'label' => $field[0],
            'value' => $field[1] === null || trim((string) $field[1]) === '' ? null : (string) $field[1],
        ], $fields);
    }
}
