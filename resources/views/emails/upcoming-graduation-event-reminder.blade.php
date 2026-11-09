<!DOCTYPE html>
<html lang="en">
<body style="margin:0;background:#f5f6f8;color:#20242b;font:15px Arial,sans-serif">
    <div style="max-width:640px;margin:28px auto;padding:28px;background:#fff;border:1px solid #e7e9ed;border-radius:10px">
        <div style="padding-bottom:16px;border-bottom:3px solid #d42127;color:#d42127;font-size:24px;font-weight:700">GOWNSEA</div>
        <p>Gown preparation reminder: <strong>{{ $event->name }}</strong> is {{ $daysBefore }} days away.</p>
        <p><strong>Graduation date:</strong> {{ $event->event_date->format('D, j M Y') }}<br>
            <strong>Institution:</strong> {{ $event->institution->name }}<br>
            <strong>Branch:</strong> {{ $event->location?->name ?? 'Unassigned' }}<br>
            <strong>Gowns expected:</strong> {{ number_format($event->expected_quantity) }}
            @if($event->venue)<br><strong>Venue:</strong> {{ $event->venue }}@endif
            @if($event->delivery_date)<br><strong>Delivery date:</strong> {{ $event->delivery_date->format('D, j M Y') }}@endif
            @if($event->collection_date)<br><strong>Collection date:</strong> {{ $event->collection_date->format('D, j M Y') }}@endif
        </p>
        @if($event->notes)<p><strong>Notes:</strong><br>{{ $event->notes }}</p>@endif
        <p>Please review gown availability and arrange preparation and delivery for this event.</p>
        <p style="color:#737b88;font-size:12px">GOWNSEA · Graduation Wear Specialist</p>
    </div>
</body>
</html>
