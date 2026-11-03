<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\ProductVariant;
use App\Models\User;
use App\Repositories\BillRepository;
use App\Repositories\ProductVariantRepository;
use App\Support\ActivitySnapshot;
use App\Support\MobileNumber;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
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
     * @param  array<string, mixed>  $filters  Validated list filters: search, status, per_page.
     * @return LengthAwarePaginator<int, Bill>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->bills->paginate($filters, (int) ($filters['per_page'] ?? 20));
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
     *     customer: array{name: string, mobile: string},
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
                'bill_number' => $this->nextBillNumber(),
                // Long and random, so the bill's PDF link cannot be guessed.
                'share_token' => Str::random(40),
                'customer_id' => $customer->id,
                'customer_name' => $data['customer']['name'],
                'customer_mobile' => $data['customer']['mobile'],
                'customer_address' => $data['customer']['address'] ?? null,
                ...$this->amounts($total, $paid),
                'created_by' => $admin->id,
            ]);

            foreach ($lines as $line) {
                $this->bills->addItem($bill, [
                    ...$line['item'],
                    'line_total' => Money::toRupees($line['line_total']),
                ]);
                $this->variants->reduceStock($line['variant'], $line['quantity']);
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
     * @param  Collection<int, Bill>  $bills  Oldest first.
     * @return list<array{bill_number: string, amount: float}>
     */
    /**
     * Take a payment towards this one bill, for a customer who wants it there rather than on
     * their oldest bill. Any number of part payments can be made until the bill is paid.
     */
    public function payBill(Bill $bill, array $data, User $admin): Bill
    {
        DB::transaction(function () use ($bill, $data, $admin) {
            // Read again under a lock, so two payments at once cannot both take the same due.
            $locked = $this->bills->lockForPayment($bill);
            $due = Money::toPaise($locked->due_amount);
            $amount = Money::toPaise($data['amount']);

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
     * "₹4,320.00, ₹500.00 paid, ₹3,820.00 due", "₹865.00, paid in full" or "₹350.00, all due".
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
     * "Wire Nails (2 inch): 1.5 kg × ₹110.00 = ₹165.00"
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

    /**
     * "UPI ₹300.00 (UPI778899)"
     */
    private function paymentLine(PaymentMethod $method, int $amount, ?string $reference): string
    {
        return $method->label().' '.Money::format($amount).($reference ? " ({$reference})" : '');
    }

    private function spread(Collection $bills, int $left, PaymentMethod $method, ?string $reference, User $admin): array
    {
        $spread = [];

        foreach ($bills as $bill) {
            if ($left === 0) {
                break;
            }

            $part = min($left, Money::toPaise($bill->due_amount));

            $this->bills->addPayment($bill, [
                'method' => $method,
                'amount' => Money::toRupees($part),
                'reference' => $reference,
                'received_at' => now(),
                'received_by' => $admin->id,
            ]);
            $this->bills->updateAmounts(
                $bill,
                $this->amounts(Money::toPaise($bill->total), Money::toPaise($bill->paid_amount) + $part),
            );

            $spread[] = ['bill_number' => $bill->bill_number, 'amount' => Money::toRupees($part)];
            $left -= $part;
        }

        return $spread;
    }

    /**
     * Work out each bill line, checking that items sold by whole units are not sold in fractions.
     *
     * @param  list<array{product_variant_id: int, quantity: float|string, price: float|string}>  $items
     * @return list<array{variant: ProductVariant, quantity: float, line_total: int, item: array<string, mixed>}>
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
                ],
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * Paid, due and status for a bill, from its total and what has been paid, both in paise.
     *
     * @return array{total: float, paid_amount: float, due_amount: float, status: BillStatus}
     */
    private function amounts(int $total, int $paid): array
    {
        return [
            'total' => Money::toRupees($total),
            'paid_amount' => Money::toRupees($paid),
            'due_amount' => Money::toRupees($total - $paid),
            'status' => BillStatus::forAmounts($total, $paid),
        ];
    }

    /**
     * The next number in this year's series: INV-2026-0001, INV-2026-0002 …
     * The year follows the shop's clock, so a bill made just after midnight on
     * 1 January starts the new year's series.
     */
    private function nextBillNumber(): string
    {
        $prefix = 'INV-'.now(config('shop.timezone'))->year.'-';
        $last = $this->bills->lastNumberStartingWith($prefix);
        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
