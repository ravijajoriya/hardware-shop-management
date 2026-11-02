{{-- A bill as a PDF (dompdf), matching the printed bill. dompdf has no flexbox, so tables lay it out. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bill {{ $bill['number'] }}</title>
    <style>
        @page {
            margin: 14mm;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5pt;
            line-height: 1.4;
            color: #2a2a2a;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td,
        th {
            vertical-align: top;
        }

        .muted {
            color: #6b6b6b;
        }

        .right {
            text-align: right;
        }

        .shop-name {
            font-size: 17pt;
            font-weight: bold;
            line-height: 1.2;
            color: #111;
        }

        .doc-title {
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 2pt;
            color: #111;
        }

        .head {
            border-bottom: 2px solid #111;
        }

        .head td {
            padding-bottom: 9pt;
        }

        .label {
            font-size: 7.5pt;
            letter-spacing: 0.6pt;
            text-transform: uppercase;
            color: #6b6b6b;
        }

        .customer {
            font-size: 12pt;
            font-weight: bold;
            color: #111;
        }

        .status {
            padding: 2pt 7pt;
            font-size: 8.5pt;
            border: 1px solid #9a9a9a;
            border-radius: 8pt;
        }

        .bill-to td {
            padding: 10pt 0 12pt;
        }

        .items th {
            padding: 6pt;
            font-size: 8.5pt;
            text-align: left;
            color: #111;
            background: #f2f2f2;
            border-bottom: 1px solid #9a9a9a;
        }

        .items td {
            padding: 6pt;
            border-bottom: 1px solid #dcdcdc;
        }

        .items .num {
            text-align: right;
            white-space: nowrap;
        }

        .index {
            width: 18pt;
            color: #6b6b6b;
        }

        .item {
            color: #111;
        }

        .foot {
            margin-top: 12pt;
        }

        .payments td {
            padding: 2pt 0;
        }

        .sums td {
            padding: 3pt 0;
        }

        .sums .total td {
            font-size: 12pt;
            font-weight: bold;
            color: #111;
            border-bottom: 1px solid #dcdcdc;
        }

        .sums .amount {
            font-weight: bold;
            text-align: right;
            color: #111;
        }

        .sums .owing td {
            color: #b42318;
        }

        .thanks {
            margin-top: 26pt;
            text-align: center;
            color: #6b6b6b;
        }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="shop-name">{{ $shop['name'] }}</div>
                @if ($shop['address'])
                    <div>{{ $shop['address'] }}</div>
                @endif
                @if ($shop['phone'])
                    <div>Phone: {{ $shop['phone'] }}</div>
                @endif
            </td>
            <td class="right">
                <div class="doc-title">BILL</div>
                <div><span class="muted">Bill number</span> {{ $bill['number'] }}</div>
                <div><span class="muted">Date</span> {{ $bill['date'] }}</div>
            </td>
        </tr>
    </table>

    <table class="bill-to">
        <tr>
            <td>
                <div class="label">Billed to</div>
                <div class="customer">{{ $bill['customer'] }}</div>
                <div>{{ $bill['mobile'] }}</div>
            </td>
            <td class="right">
                <span class="status">{{ $bill['status'] }}</span>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="index">#</th>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bill['items'] as $item)
                <tr>
                    <td class="index">{{ $loop->iteration }}</td>
                    <td class="item">
                        {{ $item['name'] }}@if ($item['size'])<span class="muted"> · {{ $item['size'] }}</span>@endif
                    </td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">{{ $item['price'] }}</td>
                    <td class="num">{{ $item['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="foot">
        <tr>
            <td style="width: 58%; padding-right: 24pt;">
                <div class="label">Payments</div>
                @if ($bill['payments'])
                    <table class="payments">
                        @foreach ($bill['payments'] as $payment)
                            <tr>
                                <td>{{ $payment['method'] }}@if ($payment['reference']) · {{ $payment['reference'] }}@endif</td>
                                <td class="muted">{{ $payment['date'] }}</td>
                                <td class="right">{{ $payment['amount'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @else
                    <div class="muted">Nothing paid yet.</div>
                @endif
            </td>
            <td>
                <table class="sums">
                    <tr class="total">
                        <td>Total</td>
                        <td class="amount">{{ $bill['total'] }}</td>
                    </tr>
                    <tr>
                        <td>Paid</td>
                        <td class="amount">{{ $bill['paid'] }}</td>
                    </tr>
                    <tr class="{{ $bill['owing'] ? 'owing' : '' }}">
                        <td>Due</td>
                        <td class="amount">{{ $bill['due'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="thanks">Thank you for shopping with us!</div>
</body>
</html>
