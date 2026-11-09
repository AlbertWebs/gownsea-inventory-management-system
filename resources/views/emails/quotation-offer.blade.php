<!DOCTYPE html>
<html lang="en">
<body style="margin:0;background:#f5f6f8;color:#20242b;font:15px Arial,sans-serif">
    <div style="max-width:640px;margin:28px auto;padding:28px;background:#fff;border:1px solid #e7e9ed;border-radius:10px">
        <div style="padding-bottom:16px;border-bottom:3px solid #d42127;color:#d42127;font-size:24px;font-weight:700">GOWNSEA</div>
        <p>Hello {{ $customerName }},</p>
        <p>Please find quotation <strong>{{ $quoteReference }}</strong>, revision {{ $revision }}, attached as a PDF.</p>
        <p>Total: <strong>KES {{ number_format($totalMinor / 100, 2) }}</strong><br>Prepared by {{ $locationName }}@if($validUntil)<br>Valid until {{ $validUntil }}@endif</p>
        @if($notes)<p>{{ $notes }}</p>@endif
        <p>Reply to this email or contact your GOWNSEA branch if you would like to discuss the offer.</p>
        <p style="color:#737b88;font-size:12px">Thank you,<br>GOWNSEA · Graduation Wear Specialist</p>
    </div>
</body>
</html>
