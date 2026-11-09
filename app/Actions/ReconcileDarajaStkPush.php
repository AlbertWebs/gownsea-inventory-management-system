<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\MpesaStkPush;
use App\Models\User;
use App\Services\DarajaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReconcileDarajaStkPush
{
    public function __construct(private readonly DarajaClient $daraja) {}

    public function handle(MpesaStkPush $candidate, User $actor, ?string $ipAddress = null, ?string $userAgent = null): MpesaStkPush
    {
        $push = $candidate->fresh();
        if (! in_array($push->status, ['pending', 'review'], true) || blank($push->checkout_request_id)) {
            throw ValidationException::withMessages(['push' => 'This M-Pesa request has no queryable pending transaction.']);
        }

        $throttleKey = 'daraja.stk.status-check.'.hash('sha256', (string) $push->checkout_request_id);
        if (! Cache::add($throttleKey, true, now()->addSeconds(60))) {
            throw ValidationException::withMessages(['push' => 'This transaction was checked recently. Wait a minute before checking it again.']);
        }

        try {
            $result = $this->daraja->queryStkPushStatus($push->checkout_request_id);
        } catch (Throwable $exception) {
            report($exception);
            $push->update(['last_status_check_at' => now()]);
            throw ValidationException::withMessages(['push' => 'Daraja status could not be confirmed. The request remains open for callback or a later status check.']);
        }

        $responseCode = (string) ($result['ResponseCode'] ?? 'unknown');
        $resultCode = isset($result['ResultCode']) ? (string) $result['ResultCode'] : null;
        $description = mb_substr((string) ($result['ResultDesc'] ?? $result['ResponseDescription'] ?? 'Daraja has not returned a final transaction result.'), 0, 1000);
        $nextStatus = $push->status;
        $completedAt = $push->completed_at;

        if ($responseCode === '0' && $resultCode === '0') {
            $nextStatus = 'review';
            $description = 'Daraja reports a successful STK transaction. Waiting for callback receipt details before posting the payment.';
            $completedAt = now();
        } elseif ($responseCode === '0' && $resultCode !== null && $resultCode !== '0') {
            $nextStatus = 'failed';
            $completedAt = now();
        } elseif ($responseCode !== '0') {
            $nextStatus = 'review';
            $description = 'Daraja accepted the status check but did not confirm a final result. '.$description;
        }

        return DB::transaction(function () use ($push, $actor, $ipAddress, $userAgent, $responseCode, $resultCode, $description, $nextStatus, $completedAt): MpesaStkPush {
            $locked = MpesaStkPush::query()->lockForUpdate()->findOrFail($push->id);
            if (in_array($locked->status, ['paid', 'failed'], true)) {
                return $locked;
            }

            $before = ['status' => $locked->status, 'result_code' => $locked->result_code];
            $locked->update([
                'status' => $nextStatus,
                'result_code' => $resultCode ?? ($responseCode !== '0' ? $responseCode : $locked->result_code),
                'result_description' => $description,
                'last_status_check_at' => now(),
                'completed_at' => $completedAt,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $locked->location_id,
                'action' => 'payment.mpesa_status_checked',
                'subject_type' => MpesaStkPush::class,
                'subject_id' => $locked->id,
                'before_state' => $before,
                'after_state' => ['status' => $locked->status, 'result_code' => $locked->result_code],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'reason' => 'Daraja transaction status was checked for reconciliation.',
                'occurred_at' => now(),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }
}
