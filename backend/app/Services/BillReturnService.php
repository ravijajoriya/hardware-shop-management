<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillReturn;
use App\Models\User;
use App\Repositories\BillRepository;
use App\Repositories\BillReturnRepository;
use App\Repositories\ProductVariantRepository;
use App\Support\ActivitySnapshot;
use App\Support\BillAmounts;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillReturnService
{
    public function __construct(
        private readonly BillRepository $bills,
        private readonly BillReturnRepository $returns,
        private readonly ProductVariantRepository $variants,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * Take items back from a bill. They go back into stock, and what they were worth on the
     * bill is first taken off what is still due on it; anything beyond that is given back.
     *
     * @param  array{
     *     items: list<array{bill_item_id: int, quantity: float|string}>,
     *     note?: string|null,
     *     refund?: array{method?: string|null, reference?: string|null}|null,
     * }  $data
     *
     * @throws ValidationException
     */
    public function create(Bill $bill, array $data, User $admin): BillReturn
    {
        return DB::transaction(function () use ($bill, $data, $admin) {
            $bill = $this->bills->lock($bill);

            if ($bill->isCancelled()) {
                throw ValidationException::withMessages(['items' => ['This bill is cancelled, so nothing can be returned from it.']]);
            }

            $lines = $this->lines($bill, $data['items']);
            $amount = array_sum(array_column($lines, 'line_total'));
            $amounts = BillAmounts::of($bill);
            $dueReduced = min($amount, $amounts['due']);
            $refund = $amount - $dueReduced;
            $method = $data['refund']['method'] ?? null;

            if ($refund > 0 && ! $method) {
                throw ValidationException::withMessages([
                    'refund.method' => [Money::format($refund).' has to be given back. Choose how.'],
                ]);
            }

            $prefix = DocumentNumber::prefix('RET');
            $return = $this->returns->create($bill, [
                'return_number' => DocumentNumber::next($prefix, $this->returns->lastNumberStartingWith($prefix)),
                'amount' => Money::toRupees($amount),
                'due_reduced' => Money::toRupees($dueReduced),
                'refund_amount' => Money::toRupees($refund),
                'note' => $data['note'] ?? null,
                'returned_at' => now(),
                'created_by' => $admin->id,
            ]);

            foreach ($lines as ['item' => $item, 'quantity' => $quantity, 'line_total' => $lineTotal]) {
                $this->returns->addItem($return, [
                    'bill_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name' => $item->product_name,
                    'variant_name' => $item->variant_name,
                    'unit' => $item->unit,
                    'quantity' => $quantity,
                    'price' => $item->price,
                    'line_total' => Money::toRupees($lineTotal),
                    'cost_price' => $item->cost_price,
                ]);
                $this->bills->updateItem($item, ['returned_quantity' => round((float) $item->returned_quantity + $quantity, 3)]);

                // A deleted product has no stock to go back to.
                if ($item->variant) {
                    $this->variants->returnStock($item->variant, $quantity);
                }
            }

            if ($refund > 0) {
                $this->bills->addRefund($bill, [
                    'bill_return_id' => $return->id,
                    'reason' => RefundReason::Returned,
                    'method' => $method,
                    'amount' => Money::toRupees($refund),
                    'reference' => $data['refund']['reference'] ?? null,
                    'refunded_at' => now(),
                    'refunded_by' => $admin->id,
                ]);
            }

            $this->bills->update($bill, BillAmounts::columns(
                $amounts['total'],
                $amounts['paid'],
                $amounts['returned'] + $amount,
                $amounts['refunded'] + $refund,
            ));

            $this->activity->record(
                ActivityAction::BillReturned,
                'Took back items worth '.Money::format($amount)." on bill {$bill->bill_number} ({$return->return_number}): "
                    .$this->settlement($dueReduced, $refund, $method),
                $bill,
                details: [
                    ['label' => 'Items', 'value' => array_map(
                        fn (array $line) => $this->name($line['item']).': '.ActivitySnapshot::quantity($line['quantity'], $line['item']->unit)
                            .' × '.ActivitySnapshot::money($line['item']->price).' = '.Money::format($line['line_total']),
                        $lines,
                    )],
                    ['label' => 'Note', 'value' => $return->note],
                    ['label' => 'Refund', 'value' => $refund > 0
                        ? PaymentMethod::from($method)->label().' '.Money::format($refund)
                            .(($data['refund']['reference'] ?? null) ? " ({$data['refund']['reference']})" : '')
                        : null],
                ],
                actor: $admin,
            );

            return $return->load(['items', 'refund']);
        });
    }

    /**
     * Each line being returned, checked against what is left of it to return.
     *
     * @param  list<array{bill_item_id: int, quantity: float|string}>  $rows
     * @return list<array{item: BillItem, quantity: float, line_total: int}>
     *
     * @throws ValidationException
     */
    private function lines(Bill $bill, array $rows): array
    {
        $items = $bill->items->keyBy('id');
        $lines = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            /** @var BillItem $item */
            $item = $items[(int) $row['bill_item_id']];
            $quantity = round((float) $row['quantity'], 3);
            $left = round((float) $item->quantity - (float) $item->returned_quantity, 3);
            $unit = $item->variant?->product?->unit;

            if ($left <= 0) {
                $errors["items.{$index}.quantity"] = ['All of this has already been returned.'];
            } elseif ($quantity > $left) {
                $errors["items.{$index}.quantity"] = ['Only '.ActivitySnapshot::quantity($left, $item->unit).' of this is left to return.'];
            } elseif ($unit && ! $unit->allow_decimal && floor($quantity) !== $quantity) {
                $errors["items.{$index}.quantity"] = ["{$unit->name} ({$unit->short_name}) is counted in whole numbers."];
            }

            $lines[] = ['item' => $item, 'quantity' => $quantity, 'line_total' => (int) round($quantity * Money::toPaise($item->price))];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * "₹200 off the due, ₹100 given back by Cash", or one of the two.
     */
    private function settlement(int $dueReduced, int $refund, ?string $method): string
    {
        return implode(', ', array_filter([
            $dueReduced > 0 ? Money::format($dueReduced).' off the due' : null,
            $refund > 0 ? Money::format($refund).' given back by '.PaymentMethod::from($method)->label() : null,
        ])) ?: 'no money to settle';
    }

    private function name(BillItem $item): string
    {
        return $item->variant_name ? "{$item->product_name} ({$item->variant_name})" : $item->product_name;
    }
}
