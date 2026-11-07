<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockCorrection;
use App\Models\StockEntryItem;
use App\Repositories\StockCorrectionRepository;
use App\Repositories\StockEntryRepository;

class StockHistoryService
{
    private const LIMIT = 200;

    public function __construct(
        private readonly StockEntryRepository $entries,
        private readonly StockCorrectionRepository $corrections,
    ) {}

    /**
     * What came in and every hand correction for the product, newest first. A delivery is
     * dated the day it was received, which may be earlier than the day it was entered.
     *
     * @return list<array<string, mixed>>
     */
    public function forProduct(Product $product): array
    {
        $deliveries = $this->entries->itemsFor($product, self::LIMIT)->map(fn (StockEntryItem $item) => [
            'type' => 'stock_in',
            'date' => $item->entry->received_on->toDateString(),
            'recorded_at' => $item->created_at,
            'variant_name' => $item->variant_name,
            'unit' => $item->unit,
            // As entered: "+10 coil at ₹1,000", with the average cost per coil to match.
            'change' => (float) $item->quantity,
            'purchase_price' => (float) $item->purchase_price,
            'cost_after' => round((float) $item->cost_after * (float) $item->unit_size, 2),
            'supplier_name' => $item->entry->supplier_name,
            'supplier_bill_number' => $item->entry->supplier_bill_number,
            'stock_entry_id' => $item->stock_entry_id,
        ]);

        $corrections = $this->corrections->forProduct($product, self::LIMIT)->map(fn (StockCorrection $correction) => [
            'type' => 'correction',
            'date' => $correction->created_at->copy()->setTimezone(config('shop.timezone'))->toDateString(),
            'recorded_at' => $correction->created_at,
            'variant_name' => $correction->variant_name,
            'unit' => $correction->unit,
            'change' => round((float) $correction->stock_after - (float) $correction->stock_before, 3),
            'stock_before' => (float) $correction->stock_before,
            'stock_after' => (float) $correction->stock_after,
            'reason' => ['value' => $correction->reason->value, 'label' => $correction->reason->label()],
            'note' => $correction->note,
            'by' => $correction->creator?->name,
        ]);

        return $deliveries->toBase()
            ->concat($corrections)
            ->sortBy([['date', 'desc'], ['recorded_at', 'desc']])
            ->take(self::LIMIT)
            ->values()
            ->all();
    }
}
