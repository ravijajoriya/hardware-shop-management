<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\BillRefund;
use App\Models\BillReturn;
use App\Models\BillReturnItem;
use App\Support\MobileNumber;
use App\Support\Money;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;

class BillPdfService
{
    public function __construct(
        private readonly BillService $bills,
        private readonly ShopService $shop,
    ) {}

    /**
     * The bill as an A4 PDF, laid out like the printed bill.
     */
    public function render(Bill $bill): string
    {
        // DejaVu Sans ships with dompdf and, unlike the standard PDF fonts, has the ₹ sign.
        $options = (new Options)
            ->setDefaultFont('DejaVu Sans')
            ->setIsRemoteEnabled(false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html($bill));
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    /**
     * The page the PDF is made from, with every amount and date already written out.
     */
    public function html(Bill $bill): string
    {
        $bill = $this->bills->details($bill);

        return view('pdf.bill', [
            'shop' => $this->shop->details(),
            'bill' => [
                'number' => $bill->bill_number,
                'date' => $this->dateTime($bill->created_at),
                'customer' => $bill->customer_name,
                'mobile' => MobileNumber::format($bill->customer_mobile),
                'address' => $bill->customer_address,
                'status' => $bill->status->label(),
                'items' => $bill->items->map(fn (BillItem $item) => [
                    'name' => $item->product_name,
                    'size' => $item->variant_name,
                    'quantity' => $this->quantity($item->quantity).' '.$item->unit,
                    'price' => $this->money($item->price),
                    'amount' => $this->money($item->line_total),
                ])->all(),
                // Money in, and money given back, in the order it happened.
                'payments' => $bill->payments->map(fn (BillPayment $payment) => [
                    'method' => $payment->method->label(),
                    'reference' => $payment->reference,
                    'date' => $this->dateTime($payment->received_at),
                    'amount' => $this->money($payment->amount),
                    'at' => $payment->received_at,
                ])->concat($bill->refunds->map(fn (BillRefund $refund) => [
                    'method' => 'Refund · '.$refund->method->label(),
                    'reference' => $refund->reference,
                    'date' => $this->dateTime($refund->refunded_at),
                    'amount' => '−'.$this->money($refund->amount),
                    'at' => $refund->refunded_at,
                ]))->sortBy('at')->values()->all(),
                'returns' => $bill->returns->map(fn (BillReturn $return) => [
                    'number' => $return->return_number,
                    'date' => $this->dateTime($return->returned_at),
                    'items' => $return->items->map(fn (BillReturnItem $item) => $item->product_name
                        .($item->variant_name ? " · {$item->variant_name}" : '').': '.$this->quantity($item->quantity).' '.$item->unit)->all(),
                    'amount' => $this->money($return->amount),
                ])->all(),
                'total' => $this->money($bill->total),
                'returned' => Money::toPaise($bill->returned_amount) > 0 ? $this->money($bill->returned_amount) : null,
                'paid' => $this->money($bill->paid_amount),
                'refunded' => Money::toPaise($bill->refunded_amount) > 0 ? $this->money($bill->refunded_amount) : null,
                'due' => $this->money($bill->due_amount),
                'owing' => Money::toPaise($bill->due_amount) > 0,
                'cancelled' => $bill->cancelled_at ? [
                    'date' => $this->dateTime($bill->cancelled_at),
                    'reason' => $bill->cancel_reason,
                ] : null,
            ],
        ])->render();
    }

    /**
     * Headers that open the PDF in the browser, saved as "INV-2026-0001.pdf" when downloaded.
     *
     * @return array<string, string>
     */
    public function headers(Bill $bill): array
    {
        return [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$bill->bill_number}.pdf\"",
        ];
    }

    private function money(string|float $rupees): string
    {
        return Money::format(Money::toPaise($rupees));
    }

    /**
     * Up to three decimals without trailing zeros: "1.5", "2".
     */
    private function quantity(string|float $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');
    }

    /**
     * "2 Nov 2026, 3:45 pm" by the shop's clock.
     */
    private function dateTime(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(config('shop.timezone'))->format('j M Y, g:i a');
    }
}
