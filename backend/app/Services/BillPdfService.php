<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
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
                'payments' => $bill->payments->map(fn (BillPayment $payment) => [
                    'method' => $payment->method->label(),
                    'reference' => $payment->reference,
                    'date' => $this->dateTime($payment->received_at),
                    'amount' => $this->money($payment->amount),
                ])->all(),
                'total' => $this->money($bill->total),
                'paid' => $this->money($bill->paid_amount),
                'due' => $this->money($bill->due_amount),
                'owing' => Money::toPaise($bill->due_amount) > 0,
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
