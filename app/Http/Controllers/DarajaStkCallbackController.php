<?php

namespace App\Http\Controllers;

use App\Actions\RecordCustomOrderPayment;
use App\Actions\RecordHirePayment;
use App\Models\AuditLog;
use App\Models\MpesaStkPush;
use App\Models\Payment;
use App\Models\User;
use App\Services\IntegrationSettings;
use App\Support\KenyanPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DarajaStkCallbackController extends Controller
{
    public function __invoke(Request $request, string $token, RecordHirePayment $recordHirePayment, RecordCustomOrderPayment $recordOrderPayment, IntegrationSettings $settings): JsonResponse
    {
        $expected = (string) $settings->daraja()['callback_token'];
        abort_unless(strlen($expected) >= 32 && hash_equals($expected, $token), 404);

        $payload = $request->all();
        $callback = data_get($payload, 'Body.stkCallback');
        if (! is_array($callback) || blank($callback['CheckoutRequestID'] ?? null)) {
            return $this->acknowledge();
        }

        $push = MpesaStkPush::query()->where('checkout_request_id', $callback['CheckoutRequestID'])->first();
        if ($push === null || ! in_array($push->status, ['pending', 'review'], true)) {
            return $this->acknowledge();
        }

        DB::transaction(function () use ($push, $callback, $recordHirePayment, $recordOrderPayment): void {
            $push = MpesaStkPush::query()->lockForUpdate()->find($push->id);
            if ($push === null || ! in_array($push->status, ['pending', 'review'], true)) {
                return;
            }

            $resultCode = (string) ($callback['ResultCode'] ?? 'unknown');
            $items = collect(data_get($callback, 'CallbackMetadata.Item', []))->keyBy('Name')->map(fn ($item) => $item['Value'] ?? null);
            $receipt = (string) ($items->get('MpesaReceiptNumber') ?? '');
            $amount = $items->get('Amount');
            $rawPhone = (string) ($items->get('PhoneNumber') ?? '');
            $safeCallback = [
                'result_code' => $resultCode,
                'amount_minor' => is_numeric($amount) ? (int) round(((float) $amount) * 100) : null,
                'receipt' => $receipt !== '' ? $receipt : null,
                'phone_last4' => $rawPhone !== '' ? substr($rawPhone, -4) : null,
            ];

            if ($resultCode !== '0') {
                $push->update(['status' => 'failed', 'result_code' => $resultCode, 'result_description' => mb_substr((string) ($callback['ResultDesc'] ?? 'Customer did not complete the payment.'), 0, 1000), 'callback_payload' => $safeCallback, 'completed_at' => now()]);

                return;
            }

            try {
                $callbackPhone = KenyanPhoneNumber::normalize($rawPhone);
            } catch (\InvalidArgumentException) {
                $callbackPhone = '';
            }
            $amountMinor = is_numeric($amount) ? (int) round(((float) $amount) * 100) : -1;
            if ($amountMinor !== $push->amount_minor || $callbackPhone !== $push->phone || $receipt === '' || Payment::query()->where('provider_reference', $receipt)->exists()) {
                $push->update(['status' => 'review', 'result_code' => $resultCode, 'result_description' => 'Daraja reported success, but the payment details did not match the requested amount, phone, or a unique receipt. Manual reconciliation is required.', 'callback_payload' => $safeCallback, 'completed_at' => now()]);
                Log::critical('Daraja STK callback requires payment reconciliation.', ['stk_reference' => $push->reference, 'checkout_request_id' => $push->checkout_request_id, 'receipt' => $receipt]);

                return;
            }

            $actor = User::query()->find($push->requested_by);
            if ($actor === null) {
                $push->update(['status' => 'review', 'result_code' => $resultCode, 'result_description' => 'Initiating staff account is unavailable; manual reconciliation is required.', 'callback_payload' => $safeCallback, 'completed_at' => now()]);

                return;
            }

            $paymentData = [
                'amount' => intdiv($amountMinor, 100).'.00', 'method' => 'mpesa', 'purpose' => $push->purpose,
                'provider_reference' => $receipt, 'idempotency_key' => $push->idempotency_key,
            ];

            try {
                $payment = $push->hire_id !== null
                    ? $recordHirePayment->handle($paymentData + ['hire_id' => $push->hire_id], $actor)
                    : $recordOrderPayment->handle($paymentData + ['custom_order_id' => $push->custom_order_id], $actor);
            } catch (Throwable $exception) {
                $push->update(['status' => 'review', 'result_code' => $resultCode, 'result_description' => 'Daraja confirmed payment, but the linked balance changed before settlement. Manual reconciliation is required.', 'callback_payload' => $safeCallback, 'completed_at' => now()]);
                report($exception);

                return;
            }

            $push->update(['status' => 'paid', 'result_code' => $resultCode, 'result_description' => mb_substr((string) ($callback['ResultDesc'] ?? 'Payment received.'), 0, 1000), 'mpesa_receipt' => $receipt, 'payment_id' => $payment->id, 'callback_payload' => $safeCallback, 'completed_at' => now()]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $push->location_id, 'action' => 'payment.mpesa_callback_received',
                'subject_type' => Payment::class, 'subject_id' => $payment->id,
                'after_state' => ['stk_reference' => $push->reference, 'payment_reference' => $payment->reference, 'provider_reference' => $receipt, 'amount_minor' => $amountMinor],
                'occurred_at' => now(),
            ]);
        }, attempts: 3);

        return $this->acknowledge();
    }

    private function acknowledge(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
