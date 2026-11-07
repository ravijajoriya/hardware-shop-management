<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\User;
use App\Repositories\ProductVariantRepository;
use App\Repositories\StockEntryRepository;
use App\Support\ActivitySnapshot;
use App\Support\Money;
use App\Support\StockCost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockEntryService
{
    /**
     * A size's purchase price is kept per loose unit to a ten-thousandth of a rupee, so a pack's
     * price divides back exactly: a ₹1,000 coil of 90 m costs ₹11.1111 a metre.
     */
    private const SCALE = 10000;

    public function __construct(
        private readonly StockEntryRepository $entries,
        private readonly ProductVariantRepository $variants,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Validated list filters: search, per_page.
     * @return LengthAwarePaginator<int, StockEntry>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->entries->paginate($filters, (int) ($filters['per_page'] ?? 20));
    }

    public function details(StockEntry $entry): StockEntry
    {
        return $this->entries->loadDetails($entry);
    }

    /**
     * @return list<string>
     */
    public function supplierNames(): array
    {
        return $this->entries->supplierNames(200);
    }

    /**
     * Log a delivery and add it to stock. Each size's purchase price becomes the average cost
     * of its stock with the new stock in it.
     *
     * @param  array<string, mixed>  $data  Validated delivery details plus an "items" list.
     *
     * @throws ValidationException
     */
    public function create(array $data, User $admin): StockEntry
    {
        $lines = $this->lines($data['items']);

        $entry = DB::transaction(function () use ($data, $lines, $admin) {
            $entry = $this->entries->create([
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_bill_number' => $data['supplier_bill_number'] ?? null,
                'received_on' => $data['received_on'],
                'note' => $data['note'] ?? null,
                'total' => Money::toRupees(array_sum(array_column($lines, 'line_total'))),
                'created_by' => $admin->id,
            ]);

            $logged = [];

            foreach ($lines as $line) {
                $variant = $this->variants->lockForStock($line['variant']);
                $stock = (float) $variant->stock_quantity;
                $costBefore = $variant->purchase_price;
                $cost = $this->kept(
                    StockCost::afterAdding($stock, $this->precise($costBefore), $line['loose_quantity'], $line['unit_cost']) / self::SCALE,
                    $line['variant']->product,
                );

                $this->variants->updateStock($variant, round($stock + $line['loose_quantity'], 3), $cost);
                $this->entries->addItem($entry, [
                    ...$line['item'],
                    'cost_before' => $costBefore,
                    'cost_after' => $cost,
                ]);

                // Costs read per pack for a line entered in packs, the way it was bought.
                $size = $line['item']['unit_size'];
                $logged[] = $this->itemName($line['item']).': '.ActivitySnapshot::quantity($line['quantity'], $line['item']['unit'])
                    .' at '.Money::format($line['price']).', average cost'.($size !== 1.0 ? " per {$line['item']['unit']}" : '').' '
                    .($costBefore === null ? '—' : ActivitySnapshot::money((float) $costBefore * $size)).' → '.ActivitySnapshot::money($cost * $size);
            }

            $count = count($lines);
            $this->activity->record(
                ActivityAction::StockEntryCreated,
                $this->title($entry).": {$count} ".($count === 1 ? 'item' : 'items').', '.Money::format(Money::toPaise($entry->total)),
                $entry,
                details: [
                    ['label' => 'Received on', 'value' => $entry->received_on->format('j M Y')],
                    ['label' => 'Items', 'value' => $logged],
                    ['label' => 'Note', 'value' => $entry->note],
                ],
                actor: $admin,
            );

            return $entry;
        });

        return $this->details($entry);
    }

    /**
     * Delete a delivery entered by mistake: its stock comes back out, and the average cost goes
     * back to what it was before it. Lines whose product has since been deleted have no stock
     * to change.
     */
    public function delete(StockEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $logged = [];

            foreach ($this->entries->loadDetails($entry)->items as $item) {
                if (! $item->variant) {
                    $logged[] = "{$this->itemName($item->toArray())}: product already deleted, no stock to take out";

                    continue;
                }

                $variant = $this->variants->lockForStock($item->variant);
                $stock = (float) $variant->stock_quantity;
                $cost = $this->costWithout($item, $variant->purchase_price, $stock);

                $this->variants->updateStock($variant, round($stock - $item->inLooseUnits($item->quantity), 3), $cost);

                $logged[] = "{$this->itemName($item->toArray())}: −".ActivitySnapshot::quantity($item->quantity, $item->unit)
                    .', purchase price back to '.($cost === null ? '—' : ActivitySnapshot::money($cost * (float) $item->unit_size))
                    .($item->isInPacks() ? " per {$item->unit}" : '');
            }

            $this->activity->record(
                ActivityAction::StockEntryDeleted,
                'Deleted '.lcfirst($this->title($entry)).' received '.$entry->received_on->format('j M Y').'; its stock was taken back out',
                $entry,
                details: [['label' => 'Items', 'value' => $logged]],
            );

            $this->entries->delete($entry);
        });
    }

    /**
     * The average cost without this delivery in it. While it is still the size's latest
     * delivery, that is simply the cost from before it, however much has been sold since.
     * Once another delivery has come in, the delivery is worked back out of the average.
     */
    private function costWithout(StockEntryItem $item, ?string $cost, float $stock): ?float
    {
        if (! $this->entries->hasLaterDelivery($item) && $item->cost_before !== null) {
            return (float) $item->cost_before;
        }

        $without = StockCost::afterRemoving(
            $stock,
            $this->precise($cost),
            $item->inLooseUnits($item->quantity),
            $this->unitCost((float) $item->purchase_price, (float) $item->unit_size),
        );

        return $without === null ? null : $this->kept($without / self::SCALE, $item->variant->product);
    }

    /**
     * Each item with its size, quantity and price worked out, ready to log. Units such as
     * Piece are counted in whole numbers. A product bought in packs is entered in packs
     * (10 coils at the coil's price) unless the line says it came loose.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function lines(array $items): array
    {
        $variants = $this->variants->findWithUnits(array_map(fn (array $item) => (int) $item['product_variant_id'], $items));
        $lines = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $variant = $variants[(int) $item['product_variant_id']];
            $product = $variant->product;
            $packSize = $product->packSize();
            $inPacks = ($item['entered_as'] ?? ($packSize === null ? 'loose' : 'pack')) === 'pack';

            if ($inPacks && $packSize === null) {
                $errors["items.{$index}.entered_as"] = ["{$product->name} is not bought in packs."];

                continue;
            }

            $unit = $inPacks ? $product->packUnit : $product->unit;
            $unitSize = $inPacks ? $packSize : 1.0;
            $quantity = (float) $item['quantity'];

            if (($inPacks || ! $unit->allow_decimal) && floor($quantity) !== $quantity) {
                $errors["items.{$index}.quantity"] = ["{$unit->name} ({$unit->short_name}) is counted in whole numbers."];

                continue;
            }

            $price = Money::toPaise($item['purchase_price']);
            $lineTotal = (int) round($quantity * $price);

            $lines[] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'loose_quantity' => round($quantity * $unitSize, 3),
                'price' => $price,
                'unit_cost' => $this->unitCost(Money::toRupees($price), $unitSize),
                'line_total' => $lineTotal,
                'item' => [
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_name' => $product->name,
                    'variant_name' => $variant->name,
                    'unit' => $unit->short_name,
                    'unit_size' => $unitSize,
                    'base_unit' => $product->unit->short_name,
                    'quantity' => $quantity,
                    'purchase_price' => Money::toRupees($price),
                    'line_total' => Money::toRupees($lineTotal),
                ],
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * "Stock in from Sharma Traders (bill ST-4512)", or just "Stock in".
     */
    private function title(StockEntry $entry): string
    {
        return 'Stock in'
            .($entry->supplier_name ? " from {$entry->supplier_name}" : '')
            .($entry->supplier_bill_number ? " (bill {$entry->supplier_bill_number})" : '');
    }

    /**
     * @param  array{product_name: string, variant_name?: string|null}  $item
     */
    private function itemName(array $item): string
    {
        return ($item['variant_name'] ?? null) ? "{$item['product_name']} ({$item['variant_name']})" : $item['product_name'];
    }

    /**
     * An average cost as it is kept: to the paisa, as always, for a product sold one way, and
     * to a ten-thousandth of a rupee for one bought in packs, so a pack's price divides back.
     */
    private function kept(float $cost, ?Product $product): float
    {
        return round($cost, $product?->packSize() === null ? 2 : 4);
    }

    /**
     * A purchase price per loose unit, in ten-thousandths of a rupee.
     */
    private function precise(?string $rupees): ?int
    {
        return $rupees === null ? null : (int) round((float) $rupees * self::SCALE);
    }

    /**
     * What one loose unit cost, in ten-thousandths of a rupee, from the price of one of the
     * line's units: a ₹1,000 coil of 90 m is 111,111 (₹11.1111) a metre.
     */
    private function unitCost(float $price, float $unitSize): int
    {
        return (int) round($price * self::SCALE / $unitSize);
    }
}
