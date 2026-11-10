<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Thermal payment receipt {{ $payment->reference }}</title>
    <style>
        @page { margin: 4mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font-family: DejaVu Sans, sans-serif; font-size: 8pt; line-height: 1.35; }
        .receipt { width: 100%; }
        header { padding-bottom: 8px; border-bottom: 1px dashed #333; text-align: center; }
        .brand { font-size: 14pt; font-weight: bold; letter-spacing: .06em; }
        .company { margin-top: 2px; font-size: 8pt; font-weight: bold; }
        .branch { margin-top: 2px; font-size: 7pt; }
        h1 { margin: 9px 0 2px; font-size: 11pt; text-align: center; text-transform: uppercase; }
        .reference { margin-bottom: 8px; font-size: 8pt; font-weight: bold; text-align: center; }
        .rule { margin: 7px 0; border-top: 1px dashed #555; }
        .section-label { margin: 7px 0 4px; font-size: 7pt; font-weight: bold; text-transform: uppercase; }
        .customer { margin-bottom: 3px; font-size: 9pt; font-weight: bold; overflow-wrap: anywhere; }
        .customer-detail { font-size: 7pt; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td { padding: 2.5px 0; vertical-align: top; }
        td:first-child { width: 43%; padding-right: 5px; }
        td:last-child { width: 57%; font-weight: bold; text-align: right; overflow-wrap: anywhere; }
        .total td { padding: 7px 0 2px; border-top: 1px solid #333; font-size: 10pt; }
        .total td:last-child { font-size: 11pt; }
        .refund td { font-size: 7pt; }
        footer { margin-top: 10px; padding-top: 7px; border-top: 1px dashed #333; font-size: 7pt; text-align: center; }
    </style>
</head>
<body>
    <main class="receipt">
        <header>
            <div class="brand">{{ filled($businessProfile['trading_name'] ?? null) ? $businessProfile['trading_name'] : 'GOWNSEA' }}</div>
            <div class="company">{{ filled($businessProfile['legal_name'] ?? null) ? $businessProfile['legal_name'] : 'Graduation Gowns East Africa Limited' }}</div>
            <div class="branch">{{ $locationName ?? 'GOWNSEA' }}</div>
            @if(!empty($locationAddress))<div class="branch">{{ $locationAddress }}</div>@endif
            @if(!empty($locationPhone))<div class="branch">Branch: {{ $locationPhone }}</div>@endif
            @if(!empty($businessProfile['registered_address']))<div class="branch">{{ $businessProfile['registered_address'] }}</div>@endif
            @if(!empty($businessProfile['receipt_phone']))<div class="branch">Tel: {{ $businessProfile['receipt_phone'] }}</div>@endif
            @if(!empty($businessProfile['receipt_email']))<div class="branch">{{ $businessProfile['receipt_email'] }}</div>@endif
            @if(!empty($businessProfile['receipt_website']))<div class="branch">{{ $businessProfile['receipt_website'] }}</div>@endif
            @if(!empty($businessProfile['kra_pin']))<div class="branch">PIN {{ $businessProfile['kra_pin'] }}</div>@endif
        </header>

        <h1>Payment receipt</h1>
        <div class="reference">{{ $payment->reference }}</div>

        <div class="section-label">Received from</div>
        <div class="customer">{{ $payment->customer?->name ?? 'Customer' }}</div>
        @if($payment->customer?->phone)<div class="customer-detail">{{ $payment->customer->phone }}</div>@endif
        @if($payment->customer?->email)<div class="customer-detail">{{ $payment->customer->email }}</div>@endif

        <div class="rule"></div>
        <div class="section-label">Payment details</div>
        <table>
            <tr><td>Date received</td><td>{{ $payment->received_at->format('d M Y, H:i') }}</td></tr>
            @if($relatedReference)<tr><td>Hire / order</td><td>{{ $relatedReference }}</td></tr>@endif
            <tr><td>Purpose</td><td>{{ str($payment->purpose)->replace('_', ' ')->title() }}</td></tr>
            <tr><td>Method</td><td>{{ str($payment->method)->replace('_', ' ')->title() }}</td></tr>
            @if($payment->provider_reference)<tr><td>Provider reference</td><td>{{ $payment->provider_reference }}</td></tr>@endif
            <tr><td>Currency</td><td>{{ $payment->currency }}</td></tr>
            <tr class="total"><td>Amount received</td><td>{{ $payment->currency }} {{ number_format($payment->amount_minor / 100, 2) }}</td></tr>
            @if($refundedMinor > 0)<tr class="refund"><td>Refunded to date</td><td>{{ $payment->currency }} {{ number_format($refundedMinor / 100, 2) }}</td></tr>@endif
        </table>
        <footer>{{ filled($businessProfile['receipt_footer'] ?? null) ? $businessProfile['receipt_footer'] : 'Payment received. Thank you for choosing GOWNSEA.' }}</footer>
    </main>
</body>
</html>
