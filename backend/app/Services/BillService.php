<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Repositories\BillRepository;
use App\Repositories\ProductVariantRepository;
use App\Support\ActivityChanges;
use App\Support\ActivitySnapshot;
use App\Support\BillAmounts;
use App\Support\DocumentNumber;
use App\Support\MobileNumber;
use App\Support\Money;
use App\Support\ShopCalendar;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillService
{
    public function __construct(
        private readonly BillRepository $bills,
        private readonly ProductVariantRepository $variants,
        private readonly CustomerService $customers,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Validated list filters (see BillIndexRequest).
     * @return LengthAwarePaginator<int, Bill>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->bills->paginate($this->listFilters($filters), (int) ($filters['per_page'] ?? 20));
    }

    /**
     * What all the bills matching the list filters add up to.
     *
     * @param  array<string, mixed>  $filters  Validated list filters (see BillIndexRequest).
     * @return array{bills: int, cancelled: int, billed: float, returned: float, due: float}
     */
    public function totals(array $filters): array
    {
        return $this->bills->totals($this->listFilters($filters));
    }

    /**
     * The list filters as the repository takes them: days by the shop's calendar become
     * moments, and "due for over N days" becomes the moment a bill must be older than.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function listFilters(array $filters): array
    {
        return [
            ...Arr::only($filters, ['search', 'status', 'method', 'product', 'changes', 'sort']),
            'from' => isset($filters['from']) ? ShopCalendar::start($filters['from']) : null,
            'until' => isset($filters['to']) ? ShopCalendar::after($filters['to']) : null,
            'due_before' => isset($filters['overdue']) ? now()->subDays((int) $filters['overdue']) : null,
        ];
    }

    public function details(Bill $bill): Bill
    {
        return $this->bills->loadDetails($bill);
    }

    /**
     * Make a bill: find or add the customer, copy each item's details onto the bill,
     * reduce stock and record the payments. Whatever is not paid is due (udhaar).
     *
     * @param  array{
     *     customer: array{name: string, mobile: string, address?: string|null},
     *     items: list<array{product_variant_id: int, quantity: float|string, price: float|string}>,
     *     payments?: list<array{method: string, amount: float|string, reference?: string|null}>|null,
     * }  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, User $admin): Bill
    {
        $lines = $this->lines($data['items']);
        $payments = $data['payments'] ?? [];

        $total = array_sum(array_column($lines, 'line_total'));
        $paid = array_sum(array_map(fn (array $payment) => Money::toPaise($payment['amount']), $payments));

        if ($paid > $total) {
            throw ValidationException::withMessages([
                'payments' => ['The payments add up to '.Money::format($paid).', more than the bill total of '.Money::format($total).'.'],
            ]);
        }

        $bill = DB::transaction(function () use ($data, $lines, $payments, $total, $paid, $admin) {
            $customer = $this->customers->resolve(
                $data['customer']['name'],
                $data['customer']['mobile'],
                $data['customer']['address'] ?? null,
            );

            $bill = $this->bills->create([
                'bill_number' => DocumentNumber::next(
                    $prefix = DocumentNumber::prefix('INV'),
                    $this->bills->lastNumberStartingWith($prefix),
                ),
                // Long and random, so the bill's PDF link cannot be guessed.
                'share_token' => Str::random(40),
                'customer_id' => $customer->id,
                'customer_name' => $data['customer']['name'],
                'customer_mobile' => $data['customer']['mobile'],
                'customer_address' => $data['customer']['address'] ?? null,
                ...BillAmounts::columns($total, $paid),
                'created_by' => $admin->id,
            ]);

            foreach ($lines as $line) {
                $this->addLine($bill, $line);
            }

            foreach ($payments as $payment) {
                $this->bills->addPayment($bill, [
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'reference' => $payment['reference'] ?? null,
                    'received_at' => now(),
                    'received_by' => $admin->id,
                ]);
            }

            $this->activity->record(
                ActivityAction::BillCreated,
                "Made bill {$bill->bill_number} for {$bill->customer_name}: ".$this->amountsLine($total, $paid),
                $bill,
                details: [
                    ['label' => 'Customer', 'value' => implode(' · ', array_filter([
                        $bill->customer_name, MobileNumber::format($bill->customer_mobile), $bill->customer_address,
                    ]))],
                    ['label' => 'Items', 'value' => array_map(fn (array $line) => $this->itemLine($line), $lines)],
                    ['label' => 'Paid', 'value' => array_map(fn (array $payment) => $this->paymentLine(
                        PaymentMethod::from($payment['method']),
                        Money::toPaise($payment['amount']),
                        $payment['reference'] ?? null,
                    ), $payments)],
                    ['label' => 'Due', 'value' => $total > $paid ? Money::format($total - $paid) : null],
                ],
                actor: $admin,
            );

            return $bill;
        });

        return $this->details($bill);
    }

    /**
     * Fix a bill in place: its customer details, and its items, quantities and prices. Stock
     * moves by the difference, and when the new total is less than what was paid, the extra
     * is given back. Once items have been returned from a bill only its customer details can
     * change, so the bill and its returns always agree.
     *
     * @param  array{
     *     customer: array{name: string, mobile: string, address?: string|null},
     *     items: list<array{id?: int|null, product_variant_id?: int|null, quantity: float|string, price: float|string}>,
     *     refund?: array{method?: string|null, reference?: string|null}|null,
     * }  $data
     *
     * @throws ValidationException
     */
    public function update(Bill $bill, array $data, User $admin): Bill
    {
        $bill = DB::transaction(function () use ($bill, $data, $admin) {
            $bill = $this->bills->lock($bill);

            if ($bill->isCancelled()) {
                throw ValidationException::withMessages(['bill' => ['A cancelled bill cannot be edited.']]);
            }

            $before = ActivitySnapshot::bill($bill);
            $plan = $this->editPlan($bill, $data['items']);
            $amounts = BillAmounts::of($bill);
            $refund = BillAmounts::overpaid($bill, $plan['total'] - $amounts['returned']);
            $method = $this->refundMethod($data, $refund, 'The new total is less than what was paid, so '.Money::format($refund).' must be given back. Choose how.');

            // The customer's own record changes only when the customer details on the bill do,
            // so fixing the items of an old bill never undoes a later change to the customer.
            $details = $data['customer'];
            $sameCustomer = $details['name'] === $bill->customer_name
                && $details['mobile'] === $bill->customer_mobile
                && ($details['address'] ?? null) === $bill->customer_address;
            $customerId = $sameCustomer
                ? $bill->customer_id
                : $this->customers->resolve($details['name'], $details['mobile'], $details['address'] ?? null)->id;

            foreach ($plan['keep'] as ['item' => $item, 'quantity' => $quantity, 'price' => $price, 'line_total' => $lineTotal]) {
                $this->moveStock($item->variant, (float) $item->quantity - $quantity);
                $this->bills->updateItem($item, [
                    'quantity' => $quantity,
                    'price' => Money::toRupees($price),
                    'line_total' => Money::toRupees($lineTotal),
                ]);
            }

            foreach ($plan['remove'] as $item) {
                $this->moveStock($item->variant, (float) $item->quantity);
                $this->bills->deleteItem($item);
            }

            foreach ($plan['add'] as $line) {
                $this->addLine($bill, $line);
            }

            if ($refund > 0) {
                $this->bills->addRefund($bill, $this->refundRow(RefundReason::Edited, $method, $refund, $data, $admin));
            }

            $this->bills->update($bill, [
                'customer_id' => $customerId,
                'customer_name' => $details['name'],
                'customer_mobile' => $details['mobile'],
                'customer_address' => $details['address'] ?? null,
                ...BillAmounts::columns($plan['total'], $amounts['paid'], $amounts['returned'], $amounts['refunded'] + $refund),
                'edited_at' => now(),
                'edited_by' => $admin->id,
            ]);

            $changes = ActivityChanges::between($before, ActivitySnapshot::bill($bill));

            if ($changes || $refund > 0) {
                $this->activity->record(
                    ActivityAction::BillUpdated,
                    "Edited bill {$bill->bill_number}: ".ActivityChanges::summary($changes),
                    $bill,
                    $changes,
                    [['label' => 'Refunded', 'value' => $refund > 0
                        ? $this->paymentLine($method, $refund, $data['refund']['reference'] ?? null) : null]],
                    $admin,
                );
            }

            return $bill;
        });

        return $this->details($bill);
    }

    /**
     * Cancel a bill: everything on it goes back into stock (apart from items already returned),
     * anything paid on it is given back, and it no longer counts as a sale or as money due.
     * It keeps its number and stays in the list, marked cancelled.
     *
     * @param  array{reason: string, refund?: array{method?: string|null, reference?: string|null}|null}  $data
     *
     * @throws ValidationException
     */
    public function cancel(Bill $bill, array $data, User $admin): Bill
    {
        DB::transaction(function () use ($bill, $data, $admin) {
            $bill = $this->bills->lock($bill);

            if ($bill->isCancelled()) {
                throw ValidationException::withMessages(['bill' => ['This bill is already cancelled.']]);
            }

            if ($bill->status === BillStatus::Returned) {
                throw ValidationException::withMessages(['bill' => ['Everything on this bill has already been returned, so there is nothing left to cancel.']]);
            }

            $amounts = BillAmounts::of($bill);
            $refund = max($amounts['paid'] - $amounts['refunded'], 0);
            $method = $this->refundMethod($data, $refund, Money::format($refund).' was paid on this bill. Choose how it is given back.');
            $restocked = [];

            foreach ($bill->items as $item) {
                $left = round((float) $item->quantity - (float) $item->returned_quantity, 3);

                if ($left > 0 && $item->variant) {
                    $this->variants->returnStock($item->variant, $left);
                    $restocked[] = "{$this->name($item)}: +".ActivitySnapshot::quantity($left, $item->unit);
                }
            }

            if ($refund > 0) {
                $this->bills->addRefund($bill, $this->refundRow(RefundReason::Cancelled, $method, $refund, $data, $admin));
            }

            $this->bills->update($bill, [
                'refunded_amount' => Money::toRupees($amounts['refunded'] + $refund),
                'due_amount' => 0,
                'status' => BillStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $admin->id,
                'cancel_reason' => $data['reason'],
            ]);

            $this->activity->record(
                ActivityAction::BillCancelled,
                "Cancelled bill {$bill->bill_number} for {$bill->customer_name} (".Money::format($amounts['total']).')'
                    .($refund > 0 ? '; '.Money::format($refund).' given back by '.$method->label() : ''),
                $bill,
                details: [
                    ['label' => 'Reason', 'value' => $data['reason']],
                    ['label' => 'Put back in stock', 'value' => $restocked],
                    ['label' => 'Refunded', 'value' => $refund > 0
                        ? $this->paymentLine($method, $refund, $data['refund']['reference'] ?? null) : null],
                ],
                actor: $admin,
            );
        });

        return $this->details($bill->refresh());
    }

    /**
     * Take a payment towards what a customer owes, clearing their oldest bills first.
     *
     * @param  array{method: string, amount: float|string, reference?: string|null}  $data
     * @return list<array{bill_number: string, amount: float}> How the payment was spread over the bills.
     *
     * @throws ValidationException
     */
    public function payDues(User $customer, array $data, User $admin): array
    {
        return DB::transaction(function () use ($customer, $data, $admin) {
            $bills = $this->bills->unpaidFor($customer);
            $owed = $bills->sum(fn (Bill $bill) => Money::toPaise($bill->due_amount));
            $left = Money::toPaise($data['amount']);

            if ($owed === 0) {
                throw ValidationException::withMessages(['amount' => ['This customer has nothing due.']]);
            }

            if ($left > $owed) {
                throw ValidationException::withMessages([
                    'amount' => ['This is more than the customer owes ('.Money::format($owed).').'],
                ]);
            }

            $method = PaymentMethod::from($data['method']);
            $spread = $this->spread($bills, $left, $method, $data['reference'] ?? null, $admin);

            $this->activity->record(
                ActivityAction::DuesPaymentReceived,
                'Received '.Money::format($left).' by '.$method->label()." from {$customer->name} towards dues",
                $customer,
                details: [
                    ['label' => 'Payment', 'value' => $this->paymentLine($method, $left, $data['reference'] ?? null)],
                    ['label' => 'Put towards', 'value' => array_map(
                        fn (array $part) => "{$part['bill_number']}: ".Money::format(Money::toPaise($part['amount'])),
                        $spread,
                    )],
                    ['label' => 'Still due', 'value' => Money::format($owed - $left)],
                ],
                actor: $admin,
            );

            return $spread;
        });
    }

    /**
     * Take a payment towards this one bill, for a customer who wants it there rather than on
     * their oldest bill. Any number of part payments can be made until the bill is paid.
     *
     * @param  array{method: string, amount: float|string, reference?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function payBill(Bill $bill, array $data, User $admin): Bill
    {
        DB::transaction(function () use ($bill, $data, $admin) {
            // Read again under a lock, so two payments at once cannot both take the same due.
            $locked = $this->bills->lock($bill);
            $due = Money::toPaise($locked->due_amount);
            $amount = Money::toPaise($data['amount']);

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['amount' => ['This bill is cancelled.']]);
            }

            if ($due === 0) {
                throw ValidationException::withMessages(['amount' => ['This bill is already paid in full.']]);
            }

            if ($amount > $due) {
                throw ValidationException::withMessages([
                    'amount' => ['This is more than is due on this bill ('.Money::format($due).').'],
                ]);
            }

            $method = PaymentMethod::from($data['method']);
            $this->spread(new Collection([$locked]), $amount, $method, $data['reference'] ?? null, $admin);
            $left = Money::toPaise($locked->due_amount);

            $this->activity->record(
                ActivityAction::BillPaymentReceived,
                'Received '.Money::format($amount).' by '.$method->label()." on bill {$locked->bill_number}: "
                    .($left > 0 ? Money::format($left).' still due' : 'now paid in full'),
                $locked,
                details: [
                    ['label' => 'Customer', 'value' => $locked->customer_name],
                    ['label' => 'Payment', 'value' => $this->paymentLine($method, $amount, $data['reference'] ?? null)],
                    ['label' => 'Still due on the bill', 'value' => Money::format($left)],
                ],
                actor: $admin,
            );
        });

        return $this->details($bill->refresh());
    }

    /**
     * What an edit does to each line: lines kept (with their new quantity and price), lines
     * removed and lines added, and the new total, all in paise.
     *
     * @param  list<array<string, mixed>>  $items  The bill's lines as they should be.
     * @return array{keep: list<array{item: BillItem, quantity: float, price: int, line_total: int}>, remove: list<BillItem>, add: list<array<string, mixed>>, total: int}
     *
     * @throws ValidationException
     */
    private function editPlan(Bill $bill, array $items): array
    {
        $existing = $bill->items->keyBy('id');
        $locked = Money::toPaise($bill->returned_amount) > 0;
        $keep = [];
        $new = [];
        $errors = [];

        foreach ($items as $index => $row) {
            if (empty($row['id'])) {
                $new[$index] = $row;

                continue;
            }

            /** @var BillItem $item */
            $item = $existing[(int) $row['id']];
            $quantity = round((float) $row['quantity'], 3);
            $price = Money::toPaise($row['price']);
            $unit = $item->variant?->product?->unit;
            $changed = $quantity !== round((float) $item->quantity, 3) || $price !== Money::toPaise($item->price);

            if ($changed && $locked) {
                $errors["items.{$index}.quantity"] = [$this->returnedMessage()];
            } elseif ($changed && $quantity !== round((float) $item->quantity, 3) && ! $item->variant) {
                $errors["items.{$index}.quantity"] = ['This product has been deleted, so its quantity cannot be changed.'];
            } elseif ($unit && ! $unit->allow_decimal && floor($quantity) !== $quantity) {
                $errors["items.{$index}.quantity"] = ["{$unit->name} ({$unit->short_name}) is sold in whole numbers."];
            }

            $keep[] = ['item' => $item, 'quantity' => $quantity, 'price' => $price, 'line_total' => (int) round($quantity * $price)];
        }

        $keptIds = array_map(fn (array $kept) => $kept['item']->id, $keep);
        $remove = $existing->reject(fn (BillItem $item) => in_array($item->id, $keptIds, true))->values()->all();

        if ($locked && ($new || $remove)) {
            $errors['items'] = [$this->returnedMessage()];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $add = $this->lines($new);
        $total = array_sum(array_column($keep, 'line_total')) + array_sum(array_column($add, 'line_total'));

        return ['keep' => $keep, 'remove' => $remove, 'add' => $add, 'total' => $total];
    }

    private function returnedMessage(): string
    {
        return 'Items on this bill have been returned, so only the customer details can be changed now.';
    }

    /**
     * Add a line to the bill and take its items out of stock.
     *
     * @param  array{variant: ProductVariant, quantity: float, line_total: int, item: array<string, mixed>}  $line
     */
    private function addLine(Bill $bill, array $line): void
    {
        $this->bills->addItem($bill, [
            ...$line['item'],
            'line_total' => Money::toRupees($line['line_total']),
        ]);
        $this->variants->reduceStock($line['variant'], $line['quantity']);
    }

    /**
     * Put back what came off a line (a positive amount) or take more out (a negative one).
     * A deleted product has no stock to change.
     */
    private function moveStock(?ProductVariant $variant, float $putBack): void
    {
        $putBack = round($putBack, 3);

        if (! $variant || $putBack === 0.0) {
            return;
        }

        if ($putBack > 0) {
            $this->variants->returnStock($variant, $putBack);
        } else {
            $this->variants->reduceStock($variant, -$putBack);
        }
    }

    /**
     * How money is to be given back, required whenever there is money to give back.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function refundMethod(array $data, int $refund, string $message): ?PaymentMethod
    {
        $method = $data['refund']['method'] ?? null;

        if ($refund > 0 && ! $method) {
            throw ValidationException::withMessages(['refund.method' => [$message]]);
        }

        return $method ? PaymentMethod::from($method) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function refundRow(RefundReason $reason, PaymentMethod $method, int $amount, array $data, User $admin): array
    {
        return [
            'reason' => $reason,
            'method' => $method,
            'amount' => Money::toRupees($amount),
            'reference' => $data['refund']['reference'] ?? null,
            'refunded_at' => now(),
            'refunded_by' => $admin->id,
        ];
    }

    /**
     * "₹4,320, ₹500 paid, ₹3,820 due", "₹865, paid in full" or "₹350, all due".
     */
    private function amountsLine(int $total, int $paid): string
    {
        $amount = Money::format($total);

        return match (true) {
            $paid >= $total => "{$amount}, paid in full",
            $paid === 0 => "{$amount}, all due",
            default => "{$amount}, ".Money::format($paid).' paid, '.Money::format($total - $paid).' due',
        };
    }

    /**
     * "Wire Nails (2 inch): 1.5 kg × ₹110 = ₹165"
     *
     * @param  array<string, mixed>  $line
     */
    private function itemLine(array $line): string
    {
        $item = $line['item'];
        $name = $item['variant_name'] ? "{$item['product_name']} ({$item['variant_name']})" : $item['product_name'];

        return "{$name}: ".ActivitySnapshot::quantity($item['quantity'], $item['unit'])
            .' × '.ActivitySnapshot::money($item['price']).' = '.Money::format($line['line_total']);
    }

    private function name(BillItem $item): string
    {
        return $item->variant_name ? "{$item->product_name} ({$item->variant_name})" : $item->product_name;
    }

    /**
     * "UPI ₹300 (UPI778899)"
     */
    private function paymentLine(PaymentMethod $method, int $amount, ?string $reference): string
    {
        return $method->label().' '.Money::format($amount).($reference ? " ({$reference})" : '');
    }

    /**
     * @param  Collection<int, Bill>  $bills  Oldest first.
     * @return list<array{bill_number: string, amount: float}>
     */
    private function spread(Collection $bills, int $left, PaymentMethod $method, ?string $reference, User $admin): array
    {
        $spread = [];

        foreach ($bills as $bill) {
            if ($left === 0) {
                break;
            }

            $part = min($left, Money::toPaise($bill->due_amount));
            $amounts = BillAmounts::of($bill);

            $this->bills->addPayment($bill, [
                'method' => $method,
                'amount' => Money::toRupees($part),
                'reference' => $reference,
                'received_at' => now(),
                'received_by' => $admin->id,
            ]);
            $this->bills->update(
                $bill,
                BillAmounts::columns($amounts['total'], $amounts['paid'] + $part, $amounts['returned'], $amounts['refunded']),
            );

            $spread[] = ['bill_number' => $bill->bill_number, 'amount' => Money::toRupees($part)];
            $left -= $part;
        }

        return $spread;
    }

    /**
     * Work out each new bill line, checking that items sold by whole units are not sold in
     * fractions. The keys of $items are kept in the messages, so they point at the right line.
     *
     * @param  array<int, array{product_variant_id: int, quantity: float|string, price: float|string}>  $items
     * @return list<array{variant: ProductVariant, quantity: float, line_total: int, item: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    private function lines(array $items): array
    {
        if (! $items) {
            return [];
        }

        $variants = $this->variants->findWithUnits(array_map(fn (array $item) => (int) $item['product_variant_id'], array_values($items)));
        $lines = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $variant = $variants[(int) $item['product_variant_id']];
            $unit = $variant->product->unit;
            $quantity = (float) $item['quantity'];

            if (! $unit->allow_decimal && floor($quantity) !== $quantity) {
                $errors["items.{$index}.quantity"] = ["{$unit->name} ({$unit->short_name}) is sold in whole numbers."];

                continue;
            }

            $lines[] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'line_total' => (int) round($quantity * Money::toPaise($item['price'])),
                'item' => [
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'unit' => $unit->short_name,
                    'quantity' => $quantity,
                    'price' => $item['price'],
                    'list_price' => $variant->selling_price,
                    // What the size cost the shop, for profit: its average purchase price now.
                    'cost_price' => $variant->purchase_price,
                ],
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }
}
