<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class StockEntryRepository
{
    /**
     * Deliveries, newest first. "search" matches the supplier, their bill number or an item.
     *
     * @param  array{search?: string|null}  $filters
     * @return LengthAwarePaginator<int, StockEntry>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return StockEntry::query()
            ->withCount('items')
            ->with('creator:id,name')
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('supplier_name', 'like', "%{$search}%")
                        ->orWhere('supplier_bill_number', 'like', "%{$search}%")
                        ->orWhereHas('items', fn (Builder $items) => $items->where('product_name', 'like', "%{$search}%"));
                });
            })
            ->latest('received_on')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function loadDetails(StockEntry $entry): StockEntry
    {
        return $entry->load(['items', 'creator:id,name']);
    }

    public function create(array $attributes): StockEntry
    {
        return StockEntry::create($attributes);
    }

    public function addItem(StockEntry $entry, array $attributes): StockEntryItem
    {
        return $entry->items()->create($attributes);
    }

    /**
     * Whether the same size has come in again since this delivery line.
     */
    public function hasLaterDelivery(StockEntryItem $item): bool
    {
        return StockEntryItem::where('product_variant_id', $item->product_variant_id)
            ->where('id', '>', $item->id)
            ->exists();
    }

    public function delete(StockEntry $entry): void
    {
        // Its items go with it.
        $entry->delete();
    }

    /**
     * Supplier names used before, most recent first, to suggest while typing.
     *
     * @return list<string>
     */
    public function supplierNames(int $limit): array
    {
        return StockEntry::query()
            ->whereNotNull('supplier_name')
            ->groupBy('supplier_name')
            ->orderByRaw('MAX(received_on) DESC')
            ->limit($limit)
            ->pluck('supplier_name')
            ->all();
    }

    /**
     * The product's delivery lines with their delivery, newest first.
     *
     * @return Collection<int, StockEntryItem>
     */
    public function itemsFor(Product $product, int $limit): Collection
    {
        return $product->stockEntryItems()
            ->with('entry:id,supplier_name,supplier_bill_number,received_on')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
