<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\ProductVariant;
use App\Models\StockCorrection;
use App\Models\User;
use App\Repositories\ProductVariantRepository;
use App\Repositories\StockCorrectionRepository;
use App\Support\ActivitySnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCorrectionService
{
    public function __construct(
        private readonly StockCorrectionRepository $corrections,
        private readonly ProductVariantRepository $variants,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * Set a size's stock to the count it really is, logging the count before and why.
     * The purchase price stays as it is.
     *
     * @param  array{quantity: float|int|string, reason: string, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function correct(ProductVariant $variant, array $data, User $admin): StockCorrection
    {
        return DB::transaction(function () use ($variant, $data, $admin) {
            $variant = $this->variants->lockForStock($variant)->load('product.unit');
            $unit = $variant->product->unit;
            $before = (float) $variant->stock_quantity;
            $after = round((float) $data['quantity'], 3);

            if (! $unit->allow_decimal && floor($after) !== $after) {
                throw ValidationException::withMessages([
                    'quantity' => ["{$unit->name} ({$unit->short_name}) is counted in whole numbers."],
                ]);
            }

            if ($after === round($before, 3)) {
                throw ValidationException::withMessages([
                    'quantity' => ['The stock is already '.(float) $before." {$unit->short_name}. Enter the count it should be."],
                ]);
            }

            $this->variants->setStock($variant, $after);

            $correction = $this->corrections->create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'unit' => $unit->short_name,
                'stock_before' => $before,
                'stock_after' => $after,
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'created_by' => $admin->id,
            ]);

            $name = $variant->product->name.($variant->name ? " ({$variant->name})" : '');
            $this->activity->record(
                ActivityAction::StockCorrected,
                "Corrected stock of {$name}: ".ActivitySnapshot::quantity($before, $unit->short_name)
                    .' → '.ActivitySnapshot::quantity($after, $unit->short_name)." ({$correction->reason->label()})",
                $variant->product,
                details: [
                    ['label' => 'Why', 'value' => $correction->reason->label()],
                    ['label' => 'Note', 'value' => $correction->note],
                ],
                actor: $admin,
            );

            return $correction;
        });
    }
}
