<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->reference }}</title>
    <style>
        body { color: #17191d; font: 12px DejaVu Sans, sans-serif; }
        header { border-bottom: 3px solid #d42127; margin-bottom: 26px; padding-bottom: 14px; }
        h1 { color: #d42127; font-size: 24px; margin: 0 0 5px; }
        h2 { font-size: 16px; margin-top: 28px; }
        .muted { color: #657080; }
        .details { width: 100%; }
        .details td { padding: 4px 0; vertical-align: top; }
        table.lines { border-collapse: collapse; margin-top: 20px; width: 100%; }
        .lines th { background: #f3f4f5; text-align: left; }
        .lines th, .lines td { border-bottom: 1px solid #dfe2e5; padding: 9px 8px; }
        .numeric { text-align: right !important; }
        .totals { margin: 18px 0 0 auto; width: 42%; }
        .totals td { padding: 5px 0; }
        .grand-total { border-top: 2px solid #17191d; font-size: 15px; font-weight: bold; }
        .notes { border-top: 1px solid #dfe2e5; margin-top: 26px; padding-top: 12px; }
    </style>
</head>
<body>
    <header>
        <h1>GOWNSEA</h1>
        <div class="muted">Graduation Wear Specialist · {{ $quotation->location->name }}</div>
    </header>
    <h2>Quotation {{ $quotation->reference }}</h2>
    <table class="details">
        <tr><td><strong>Prepared for</strong></td><td>{{ $quotation->lead->contact_name }}</td><td><strong>Revision</strong></td><td>{{ $quotation->revision }}</td></tr>
        <tr><td><strong>Institution</strong></td><td>{{ $quotation->lead->institution?->name ?? '—' }}</td><td><strong>Status</strong></td><td>{{ ucfirst($quotation->status) }}</td></tr>
        <tr><td><strong>Valid until</strong></td><td>{{ $quotation->valid_until?->format('d M Y') ?? 'Not specified' }}</td><td><strong>Currency</strong></td><td>KES</td></tr>
    </table>
    <table class="lines">
        <thead><tr><th>Description</th><th class="numeric">Quantity</th><th class="numeric">Unit price</th><th class="numeric">Total</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr><td>{{ $line->description }}</td><td class="numeric">{{ number_format($line->quantity) }}</td><td class="numeric">{{ number_format($line->unit_price_minor / 100, 2) }}</td><td class="numeric">{{ number_format($line->line_total_minor / 100, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Subtotal</td><td class="numeric">KES {{ number_format($quotation->subtotal_minor / 100, 2) }}</td></tr>
        <tr><td>Tax</td><td class="numeric">KES {{ number_format($quotation->tax_minor / 100, 2) }}</td></tr>
        <tr class="grand-total"><td>Total</td><td class="numeric">KES {{ number_format($quotation->total_minor / 100, 2) }}</td></tr>
    </table>
    @if (filled($quotation->notes))
        <div class="notes"><strong>Notes</strong><p>{{ $quotation->notes }}</p></div>
    @endif
</body>
</html>
