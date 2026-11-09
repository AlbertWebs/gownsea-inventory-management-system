<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} · GOWNSEA</title>
    <style>
        @page { margin: 28px 30px; }
        body { color: #202633; font-family: 'DejaVu Sans', sans-serif; font-size: 9px; }
        header { border-bottom: 3px solid #c9202a; margin-bottom: 16px; padding-bottom: 11px; }
        .brand { color: #c9202a; font-size: 18px; font-weight: bold; letter-spacing: 2px; }
        h1 { margin: 9px 0 5px; font-size: 16px; }
        .meta { color: #616976; font-size: 8px; line-height: 1.7; }
        .scope { margin: 12px 0; border-left: 3px solid #c9a354; background: #faf8f2; padding: 8px; color: #555d68; }
        table { width: 100%; border-collapse: collapse; table-layout: auto; }
        th { background: #252a34; color: #fff; font-weight: bold; text-align: left; }
        th, td { border: 1px solid #dfe1e5; padding: 5px 6px; vertical-align: top; overflow-wrap: anywhere; }
        tr:nth-child(even) td { background: #f8f9fa; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        footer { margin-top: 12px; border-top: 1px solid #dfe1e5; padding-top: 6px; color: #828895; font-size: 7px; }
    </style>
</head>
<body>
    <header>
        <div class="brand">GOWNSEA</div>
        <h1>{{ $title }}</h1>
        <div class="meta">Reporting period: {{ $periodStart }} to {{ $periodEnd }}<br>Generated {{ $generatedAt }} EAT</div>
    </header>
    <div class="scope">{{ $scopeNote }}@if($currencyNote) {{ $currencyNote }}@endif</div>
    <table>
        @if(count($rows))
            <thead><tr>@foreach($rows[0] as $cell)<th>{{ $cell }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach(array_slice($rows, 1) as $row)
                    <tr>@foreach($rows[0] as $index => $heading)<td>{{ $row[$index] ?? '' }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        @else
            <tbody><tr><td>No records matched the selected filters.</td></tr></tbody>
        @endif
    </table>
    <footer>Confidential operational report · GOWNSEA Graduation Wear · Kenya</footer>
</body>
</html>
