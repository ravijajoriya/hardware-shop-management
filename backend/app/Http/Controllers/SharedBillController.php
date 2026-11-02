<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Services\BillPdfService;
use Illuminate\Http\Response;

class SharedBillController extends Controller
{
    public function __construct(
        private readonly BillPdfService $pdfs,
    ) {}

    /**
     * The bill's PDF for the customer, opened from the private link sent on WhatsApp.
     */
    public function show(Bill $bill): Response
    {
        return response($this->pdfs->render($bill), 200, [
            ...$this->pdfs->headers($bill),
            // Made fresh each time, so later payments show; and kept out of search engines.
            'Cache-Control' => 'no-cache, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
