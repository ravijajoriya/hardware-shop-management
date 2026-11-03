<?php

namespace App\Services;

use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\User;
use App\Repositories\ProductVariantRepository;
use App\Repositories\StockEntryRepository;
use App\Support\Money;
use App\Support\StockCost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockEntryService
{
    public function __construct(
        private readonly StockEntryRepository $entries,
        private readonly ProductVariantRepository $variants,
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

            foreach ($lines as $line) {
                $variant = $this->variants->lockForStock($line['variant']);
                $stock = (float) $variant->stock_quantity;
                $costBefore = $variant->purchase_price;
                $cost = Money::toRupees(StockCost::afterAdding($stock, $this->paise($costBefore), $line['quantity'], $line['price']));

                $this->variants->updateStock($variant, round($stock + $line['quantity'], 3), $cost);
                $this->entries->addItem($entry, [
                    ...$line['item'],
                    'cost_before' => $costBefore,
                    'cost_after' => $cost,
                ]);
            }

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
            foreach ($this->entries->loadDetails($entry)->items as $item) {
                if (! $item->variant) {
                    continue;
                }

                $variant = $this->variants->lockForStock($item->variant);
                $stock = (float) $variant->stock_quantity;
                $quantity = (float) $item->quantity;

                $this->variants->updateStock($variant, round($stock - $quantity, 3), $this->costWithout($item, $variant->purchase_price, $stock));
            }

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

        $without = StockCost::afterRemoving($stock, $this->paise($cost), (float) $item->quantity, Money::toPaise($item->purchase_price));

        return $without === null ? null : Money::toRupees($without);
    }

    /**
     * Each item with its size, quantity and price worked out, ready to log. Units such as
     * Piece are counted in whole numbers.
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
            $unit = $variant->product->unit;
            $quantity = (float) $item['quantity'];

            if (! $unit->allow_decimal && floor($quantity) !== $quantity) {
                $errors["items.{$index}.quantity"] = ["{$unit->name} ({$unit->short_name}) is counted in whole numbers."];

                continue;
            }

            $price = Money::toPaise($item['purchase_price']);
            $lineTotal = (int) round($quantity * $price);

            $lines[] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'price' => $price,
                'line_total' => $lineTotal,
                'item' => [
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'unit' => $unit->short_name,
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

    private function paise(?string $rupees): ?int
    {
        return $rupees === null ? null : Money::toPaise($rupees);
    }
}
