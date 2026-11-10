<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment receipt {{ $payment->reference }}</title>
    <style>
        @page { size: A5 landscape; margin: 10mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 8pt; line-height: 1.35; }
        header,.title,.details-grid,.details-grid td,.details-table tr { page-break-inside: avoid; }
        header { padding-bottom: 8px; border-bottom: 2px solid #bd1e2d; }
        .brand { color: #bd1e2d; font-size: 18pt; font-weight: bold; letter-spacing: .08em; }
        .business { margin-top: 2px; color: #303a49; font-size: 8pt; font-weight: bold; }
        .business-meta { margin-top: 2px; color: #657080; font-size: 7pt; }
        .title { padding: 9px 0 8px; border-bottom: 1px solid #dfe3e8; }
        h1 { margin: 0; font-size: 15pt; }
        .reference { margin-top: 3px; color: #657080; font-size: 8pt; }
        .status { float: right; padding: 4px 8px; border-radius: 20px; background: #e8f7ef; color: #147347; font-size: 7pt; font-weight: bold; text-transform: uppercase; }
        .details-grid { width: 100%; margin-top: 12px; border-collapse: separate; border-spacing: 10px 0; table-layout: fixed; }
        .details-grid > tbody > tr > td { width: 50%; padding: 0; vertical-align: top; }
        .details-card { min-height: 90px; padding: 10px 12px; border: 1px solid #e3e6eb; border-radius: 7px; }
        .section-title { margin: 0 0 7px; color: #657080; font-size: 7pt; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; }
        .details-table { width: 100%; border-collapse: collapse; }
        .details-table td { padding: 3px 0; border-bottom: 1px solid #edf0f3; vertical-align: top; }
        .details-table tr:last-child td { border-bottom: 0; }
        .details-table td:first-child { width: 38%; padding-right: 8px; color: #657080; }
        .details-table td:last-child { overflow-wrap: anywhere; font-weight: 600; }
        .total-row td { padding-top: 6px; color: #a51d2a; font-weight: bold !important; }
        .customer-name { margin: 4px 0 7px; font-size: 12pt; font-weight: bold; overflow-wrap: anywhere; }
        .customer-detail { margin-top: 4px; color: #566171; overflow-wrap: anywhere; }
        .received-note { margin-top: 13px; padding-top: 8px; border-top: 1px solid #edf0f3; color: #657080; font-size: 7pt; }
        .footer { position: fixed; right: 0; bottom: -5mm; left: 0; padding-top: 4px; border-top: 1px solid #dfe3e8; color: #657080; font-size: 7pt; text-align: center; }
    </style>
</head>
<body>
    <header>
        <div class="brand">{{ filled($businessProfile['trading_name'] ?? null) ? $businessProfile['trading_name'] : 'GOWNSEA' }}</div>
        <div class="business">{{ filled($businessProfile['legal_name'] ?? null) ? $businessProfile['legal_name'] : 'Graduation Gowns East Africa Limited' }}</div>
        <div class="business-meta">{{ $businessProfile['registered_address'] ?? '' }}
            @if(!empty($businessProfile['receipt_phone'])) · {{ $businessProfile['receipt_phone'] }} @endif
            @if(!empty($businessProfile['receipt_email'])) · {{ $businessProfile['receipt_email'] }} @endif
            @if(!empty($businessProfile['receipt_website'])) · {{ $businessProfile['receipt_website'] }} @endif
            @if(!empty($businessProfile['kra_pin'])) · KRA PIN {{ $businessProfile['kra_pin'] }} @endif
        </div>
    </header>

    <div class="title">
        <span class="status">Payment received</span>
        <h1>Payment receipt</h1>
        <div class="reference">{{ $payment->reference }}</div>
    </div>

    <table class="details-grid"><tr>
        <td><section class="details-card">
            <div class="section-title">Payment details</div>
            <table class="details-table">
                <tr><td>Date received</td><td>{{ $payment->received_at->format('d M Y, H:i') }}</td></tr>
                <tr><td>Branch</td><td>{{ $locationName ?? '—' }}@if($locationAddress)<br>{{ $locationAddress }}@endif @if($locationPhone)<br>{{ $locationPhone }}@endif</td></tr>
                @if($relatedReference)<tr><td>Hire / order</td><td>{{ $relatedReference }}</td></tr>@endif
                <tr><td>Purpose</td><td>{{ str($payment->purpose)->replace('_', ' ')->title() }}</td></tr>
                <tr><td>Payment method</td><td>{{ str($payment->method)->replace('_', ' ')->title() }}</td></tr>
                @if($payment->provider_reference)<tr><td>Provider reference</td><td>{{ $payment->provider_reference }}</td></tr>@endif
                <tr><td>Currency</td><td>{{ $payment->currency }}</td></tr>
                <tr class="total-row"><td>Amount received</td><td>{{ $payment->currency }} {{ number_format($payment->amount_minor / 100, 2) }}</td></tr>
                @if($refundedMinor > 0)<tr><td>Refunded to date</td><td>{{ $payment->currency }} {{ number_format($refundedMinor / 100, 2) }}</td></tr>@endif
            </table>
        </section></td>
        <td><section class="details-card">
            <div class="section-title">Received from</div>
            <div class="customer-name">{{ $payment->customer?->name ?? 'Customer' }}</div>
            @if($payment->customer?->phone)<div class="customer-detail">{{ $payment->customer->phone }}</div>@endif
            @if($payment->customer?->email)<div class="customer-detail">{{ $payment->customer->email }}</div>@endif
            <div class="received-note">Payment recorded successfully. Please keep this receipt for your records.</div>
        </section></td>
    </tr></table>

        <footer class="footer">{{ filled($businessProfile['receipt_footer'] ?? null) ? $businessProfile['receipt_footer'] : 'Thank you. Please keep this receipt for your records.' }}</footer>
</body>
</html>
