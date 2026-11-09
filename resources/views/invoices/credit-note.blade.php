<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $creditNote->number }}</title>
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
        footer { border-top: 1px solid #dfe2e5; margin-top: 30px; padding-top: 12px; color: #657080; }
        .etims-receipt { border: 1px solid #dfe2e5; margin-top: 18px; padding: 10px; }
    </style>
</head>
<body>
    @php($businessProfile = $businessProfile ?? ['legal_name' => 'GOWNSEA', 'trading_name' => 'GOWNSEA', 'registered_address' => '', 'kra_pin' => null])
    <header>
        <h1>{{ $businessProfile['trading_name'] ?: $businessProfile['legal_name'] }}</h1>
        <div class="muted">{{ $businessProfile['legal_name'] }} · {{ $creditNote->location?->name ?? 'GOWNSEA' }}</div>
        @if($businessProfile['registered_address'] || $businessProfile['kra_pin'])<div class="muted">{{ $businessProfile['registered_address'] }}{{ $businessProfile['kra_pin'] ? ' · KRA PIN '.$businessProfile['kra_pin'] : '' }}</div>@endif
    </header>
    <h2>Credit note {{ $creditNote->number }}</h2>
    <table class="details">
        <tr><td><strong>Customer</strong></td><td>{{ $creditNote->customer->name }}</td><td><strong>Original invoice</strong></td><td>{{ $creditNote->invoice_number }}</td></tr>
        <tr><td><strong>Issued</strong></td><td>{{ $creditNote->issued_on->format('d M Y') }}</td><td><strong>Refund reference</strong></td><td>{{ $creditNote->refund->reference }}</td></tr>
        <tr><td><strong>Reason</strong></td><td colspan="3">{{ $creditNote->reason }}</td></tr>
    </table>
    <table class="lines">
        <thead><tr><th>Description</th><th>Tax code</th><th class="numeric">Net credit</th><th class="numeric">Tax credit</th><th class="numeric">Total credit</th></tr></thead>
        <tbody>
        @foreach ($creditNote->lines as $line)
            <tr>
                <td>{{ $line->description }}</td><td>{{ $line->tax_code ?? '—' }}</td>
                <td class="numeric">{{ number_format($line->net_minor / 100, 2) }}</td>
                <td class="numeric">{{ number_format($line->tax_minor / 100, 2) }}</td>
                <td class="numeric">{{ number_format($line->total_minor / 100, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Taxable value credited</td><td class="numeric">KES {{ number_format($creditNote->subtotal_minor / 100, 2) }}</td></tr>
        <tr><td>Tax credited</td><td class="numeric">KES {{ number_format($creditNote->tax_minor / 100, 2) }}</td></tr>
        <tr class="grand-total"><td>Total credit</td><td class="numeric">KES {{ number_format($creditNote->total_minor / 100, 2) }}</td></tr>
    </table>
    @if($etimsReceipt)<section class="etims-receipt"><strong>KRA eTIMS credit note receipt</strong><div>Invoice no. {{ $etimsReceipt['invoice_number'] }} · Receipt no. {{ $etimsReceipt['receipt_number'] }} · SDC {{ $etimsReceipt['sdc_id'] ?? '—' }}</div><div>Internal data: {{ $etimsReceipt['internal_data'] }}</div><div>Receipt signature: {{ $etimsReceipt['signature'] }}</div><div>Submitted {{ $etimsReceipt['submitted_at'] }}</div></section>@endif
    <footer>This credit note references original invoice {{ $creditNote->invoice_number }}. Retain it with the original invoice and refund record.</footer>
</body>
</html>
