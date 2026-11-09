<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    <style>
        body { color: #17191d; font: 11px DejaVu Sans, sans-serif; }
        header { border-bottom: 3px solid #d42127; margin-bottom: 26px; padding-bottom: 14px; }
        h1 { color: #d42127; font-size: 23px; margin: 0 0 5px; }
        h2 { font-size: 17px; margin: 0 0 16px; }
        .muted { color: #657080; }
        .details { width: 100%; }
        .details td { padding: 5px 7px 5px 0; vertical-align: top; }
        table.lines { border-collapse: collapse; margin-top: 20px; width: 100%; }
        .lines th { background: #f3f4f5; text-align: left; }
        .lines th, .lines td { border-bottom: 1px solid #dfe2e5; padding: 9px 8px; }
        .numeric { text-align: right !important; }
        .totals { margin: 18px 0 0 auto; width: 42%; }
        .totals td { padding: 6px 0; }
        .grand-total { border-top: 2px solid #17191d; font-size: 14px; font-weight: bold; }
        .etims-receipt { border: 1px solid #dfe2e5; margin-top: 18px; padding: 10px; }
        footer { border-top: 1px solid #dfe2e5; margin-top: 30px; padding-top: 12px; color: #657080; }
    </style>
</head>
<body>
    @php($businessProfile = $businessProfile ?? ['legal_name' => 'GOWNSEA', 'trading_name' => 'GOWNSEA', 'registered_address' => '', 'kra_pin' => null])
    <header>
        <h1>{{ $businessProfile['trading_name'] ?: $businessProfile['legal_name'] }}</h1>
        <div class="muted">{{ $businessProfile['trading_name'] && $businessProfile['legal_name'] !== $businessProfile['trading_name'] ? $businessProfile['legal_name'].' · ' : '' }}Graduation Wear Specialist · {{ $invoice->location->name }}</div>
        @if($businessProfile['registered_address'] || $businessProfile['kra_pin'])<div class="muted">{{ $businessProfile['registered_address'] }}{{ $businessProfile['kra_pin'] ? ' · KRA PIN '.$businessProfile['kra_pin'] : '' }}</div>@endif
    </header>
    <h2>Custom order invoice {{ $invoice->number }}</h2>
    <table class="details">
        <tr><td><strong>Billed to</strong></td><td>{{ $invoice->customer->name }}</td><td><strong>Order reference</strong></td><td>{{ $invoice->order->reference }}</td></tr>
        <tr><td><strong>Issued</strong></td><td>{{ $invoice->issued_on->format('d M Y') }}</td><td><strong>Due</strong></td><td>{{ $invoice->due_on?->format('d M Y') ?? 'On receipt' }}</td></tr>
        <tr><td><strong>Currency</strong></td><td>KES</td><td><strong>Customer phone</strong></td><td>{{ $invoice->customer->phone ?? '—' }}</td></tr>
    </table>
    <table class="lines">
        <thead><tr><th>Description</th><th class="numeric">Quantity</th><th class="numeric">Agreed amount</th></tr></thead>
        <tbody>
        @foreach ($invoice->lines as $line)
            <tr><td>{{ $line->description }}</td><td class="numeric">{{ number_format($line->quantity) }}</td><td class="numeric">KES {{ number_format($line->line_total_minor / 100, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        @if($invoice->tax_configuration_id)<tr><td>Taxable value</td><td class="numeric">KES {{ number_format($invoice->subtotal_minor / 100, 2) }}</td></tr><tr><td>Tax</td><td class="numeric">KES {{ number_format($invoice->tax_minor / 100, 2) }}</td></tr>@endif
        <tr><td>Invoice total</td><td class="numeric">KES {{ number_format($invoice->total_minor / 100, 2) }}</td></tr>
        <tr><td>Receipts and deposits applied</td><td class="numeric">KES {{ number_format(($invoice->total_minor - $balanceMinor) / 100, 2) }}</td></tr>
        <tr class="grand-total"><td>Balance due</td><td class="numeric">KES {{ number_format($balanceMinor / 100, 2) }}</td></tr>
    </table>
    @if($etimsReceipt ?? false)<section class="etims-receipt"><strong>KRA eTIMS fiscal receipt</strong><div>Invoice no. {{ $etimsReceipt['invoice_number'] }} · Receipt no. {{ $etimsReceipt['receipt_number'] }} · SDC {{ $etimsReceipt['sdc_id'] ?? '—' }}</div><div>Internal data: {{ $etimsReceipt['internal_data'] }}</div><div>Receipt signature: {{ $etimsReceipt['signature'] }}</div><div>Submitted {{ $etimsReceipt['submitted_at'] }}</div></section>@endif
    <footer>{{ $invoice->tax_configuration_id ? 'Tax treatment is based on the approved configuration effective on the invoice date.' : 'Tax was not calculated when this invoice was issued.' }} Thank you for choosing GOWNSEA. Please quote {{ $invoice->number }} with any payment or billing enquiry.</footer>
</body>
</html>
