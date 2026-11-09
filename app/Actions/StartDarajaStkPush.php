<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderDepositApplication;
use App\Models\Hire;
use App\Models\HireDepositApplication;
use App\Models\MpesaStkPush;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PenaltyAdjustment;
use App\Models\User;
use App\Services\DarajaClient;
use App\Services\HirePenaltyBalance;
use App\Services\IntegrationSettings;
use App\Support\KenyanPhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartDarajaStkPush
{
    public function __construct(private readonly DarajaClient $daraja, private readonly HirePenaltyBalance $penaltyBalance, private readonly IntegrationSettings $integrationSettings) {}

    /** @param array{hire_id?:int,custom_order_id?:int,amount:string,purpose:string,phone:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): MpesaStkPush
    {
        $this->ensureConfigured();
        $amountMinor = (int) $data['amount'] * 100;
        try {
            $phone = KenyanPhoneNumber::normalize($data['phone']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        $push = DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent, $amountMinor, $phone): MpesaStkPush {
            $existing = MpesaStkPush::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->amount_minor !== $amountMinor || $existing->phone !== $phone || $existing->hire_id !== (isset($data['hire_id']) ? (int) $data['hire_id'] : null) || $existing->custom_order_id !== (isset($data['custom_order_id']) ? (int) $data['custom_order_id'] : null) || $existing->purpose !== $data['purpose']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different STK request.']);
                }

                return $existing;
            }

            if (isset($data['hire_id'])) {
                $hire = Hire::query()->lockForUpdate()->findOrFail($data['hire_id']);
                $this->authorizeLocation($actor, $hire->location_id);
                $this->validateHirePayment($hire, $data['purpose'], $amountMinor);
                $customerId = $hire->customer_id;
                $locationId = $hire->location_id;
                $customOrderId = null;
                $hireId = $hire->id;
            } else {
                $order = CustomOrder::query()->lockForUpdate()->findOrFail($data['custom_order_id']);
                $this->authorizeLocation($actor, $order->location_id);
                $this->validateOrderPayment($order, $data['purpose'], $amountMinor);
                $customerId = $order->customer_id;
                $locationId = $order->location_id;
                $customOrderId = $order->id;
                $hireId = null;
            }

            $push = MpesaStkPush::query()->create([
                'reference' => 'STK-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'customer_id' => $customerId,
                'hire_id' => $hireId, 'custom_order_id' => $customOrderId, 'location_id' => $locationId,
                'requested_by' => $actor->id, 'amount_minor' => $amountMinor, 'purpose' => $data['purpose'],
                'phone' => $phone, 'status' => 'pending', 'requested_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $locationId, 'action' => 'payment.mpesa_stk_requested',
                'subject_type' => MpesaStkPush::class, 'subject_id' => $push->id,
                'after_state' => ['reference' => $push->reference, 'amount_minor' => $amountMinor, 'purpose' => $data['purpose'], 'phone_last4' => substr($phone, -4)],
                'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
            ]);

            return $push;
        }, attempts: 3);

        return Cache::lock('daraja.stk.'.hash('sha256', $push->idempotency_key), 60)->block(45, function () use ($push): MpesaStkPush {
            $push->refresh();
            if ($push->status !== 'pending' || filled($push->checkout_request_id)) {
                return $push;
            }

            try {
                $response = $this->daraja->initiateStkPush(substr($push->phone, 1), intdiv($push->amount_minor, 100), $push->reference);
                if (($response['ResponseCode'] ?? null) !== '0' || blank($response['CheckoutRequestID'] ?? null)) {
                    $push->update(['status' => 'failed', 'result_code' => (string) ($response['ResponseCode'] ?? 'unknown'), 'result_description' => mb_substr((string) ($response['ResponseDescription'] ?? 'Daraja rejected the STK request.'), 0, 1000), 'completed_at' => now()]);
                } else {
                    $push->update(['merchant_request_id' => $response['MerchantRequestID'] ?? null, 'checkout_request_id' => $response['CheckoutRequestID'], 'result_description' => mb_substr((string) ($response['ResponseDescription'] ?? 'STK request accepted.'), 0, 1000)]);
                }
            } catch (Throwable $exception) {
                $push->update(['status' => 'review', 'result_description' => 'Daraja request outcome is uncertain. Check transaction status before creating another payment prompt.', 'completed_at' => now()]);
                report($exception);
            }

            return $push->refresh();
        });
    }

    private function ensureConfigured(): void
    {
        $config = $this->integrationSettings->daraja();
        if (! $config['enabled'] || blank($config['consumer_key']) || blank($config['consumer_secret']) || blank($config['shortcode']) || blank($config['passkey']) || strlen((string) $config['callback_token']) < 32) {
            throw ValidationException::withMessages(['mpesa' => 'Daraja is not enabled or its credentials and callback token are incomplete.']);
        }
    }

    private function authorizeLocation(User $actor, ?int $locationId): void
    {
        if ($locationId !== null && ! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($locationId)->exists()) {
            abort(403);
        }
    }

    private function validateHirePayment(Hire $hire, string $purpose, int $amountMinor): void
    {
        if (! in_array($purpose, ['service_charge', 'deposit', 'penalty'], true) || ($hire->status === 'reserved' && $purpose !== 'deposit')) {
            throw ValidationException::withMessages(['purpose' => 'This payment type is not valid for the selected hire.']);
        }
        $applied = 0;
        if ($purpose === 'service_charge') {
            $cap = (int) $hire->items()->sum(DB::raw('quantity * unit_rate_minor'));
            $paid = (int) Payment::query()->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', $purpose)->sum('amount_minor');
            $refunds = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('hire_id', $hire->id)->where('purpose', $purpose))->sum('amount_minor');
            $applied = (int) HireDepositApplication::query()->where('hire_id', $hire->id)->sum('amount_minor');
        } elseif ($purpose === 'penalty') {
            $cap = (int) $hire->items()->sum('penalty_assessed_minor') + $this->penaltyBalance->accruedForHire($hire) - (int) PenaltyAdjustment::query()->where('hire_id', $hire->id)->where('kind', 'waiver')->sum('amount_minor');
            $paid = (int) Payment::query()->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', $purpose)->sum('amount_minor');
            $refunds = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('hire_id', $hire->id)->where('purpose', $purpose))->sum('amount_minor');
        } else {
            return;
        }

        $pending = (int) MpesaStkPush::query()->where('hire_id', $hire->id)->where('purpose', $purpose)->whereIn('status', ['pending', 'review'])->sum('amount_minor');
        if ($amountMinor > max(0, $cap - $paid + $refunds - ($purpose === 'service_charge' ? $applied : 0) - $pending)) {
            throw ValidationException::withMessages(['amount' => 'The STK amount exceeds the outstanding balance for this hire.']);
        }
    }

    private function validateOrderPayment(CustomOrder $order, string $purpose, int $amountMinor): void
    {
        if ($order->status === 'cancelled' || ! in_array($purpose, ['custom_order_charge', 'custom_order_deposit'], true)) {
            throw ValidationException::withMessages(['purpose' => 'This payment type is not valid for the selected custom order.']);
        }
        $cap = $purpose === 'custom_order_deposit' ? $order->deposit_required_minor : $order->agreed_total_minor;
        $paid = (int) Payment::query()->where('custom_order_id', $order->id)->where('status', 'received')->where('purpose', $purpose)->sum('amount_minor');
        $refunds = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('custom_order_id', $order->id)->where('purpose', $purpose))->sum('amount_minor');
        $applied = $purpose === 'custom_order_charge' ? (int) CustomOrderDepositApplication::query()->where('custom_order_id', $order->id)->sum('amount_minor') : 0;
        $pending = (int) MpesaStkPush::query()->where('custom_order_id', $order->id)->where('purpose', $purpose)->whereIn('status', ['pending', 'review'])->sum('amount_minor');
        if ($amountMinor > max(0, $cap - $paid + $refunds - $applied - $pending)) {
            throw ValidationException::withMessages(['amount' => 'The STK amount exceeds the outstanding balance for this custom order.']);
        }
    }
}
