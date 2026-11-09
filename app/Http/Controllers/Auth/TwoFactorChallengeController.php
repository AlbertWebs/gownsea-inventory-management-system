<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Security\TwoFactorCodeVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->two_factor_confirmed_at === null) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Auth/TwoFactorChallenge', ['email' => $user->email]);
    }

    public function store(Request $request, TwoFactorCodeVerifier $verifier): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'required_without:recovery_code', 'regex:/^\d{6}$/'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'min:16', 'max:64'],
        ]);
        $userId = $request->user()->id;

        $verified = DB::transaction(function () use ($data, $request, $userId, $verifier): bool {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if ($user->two_factor_confirmed_at === null || $user->two_factor_secret === null) {
                return false;
            }

            if (filled($data['code'] ?? null)) {
                $step = $verifier->matchingStep($user->two_factor_secret, $data['code'], $user->two_factor_last_used_step);
                if ($step === null) {
                    return false;
                }
                $user->forceFill(['two_factor_last_used_step' => $step])->save();
            } else {
                $codes = $user->two_factor_recovery_codes ?? [];
                $matchedIndex = null;
                foreach ($codes as $index => $hash) {
                    if (Hash::check($data['recovery_code'], $hash)) {
                        $matchedIndex = $index;
                        break;
                    }
                }
                if ($matchedIndex === null) {
                    return false;
                }
                unset($codes[$matchedIndex]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
            }

            AuditLog::query()->create([
                'actor_id' => $user->id,
                'action' => filled($data['code'] ?? null) ? 'security.two_factor_challenge_passed' : 'security.two_factor_recovery_used',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'after_state' => ['method' => filled($data['code'] ?? null) ? 'authenticator' : 'recovery_code'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'occurred_at' => now(),
            ]);

            return true;
        }, attempts: 3);

        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'The authenticator or recovery code is invalid or has already been used.']);
        }

        $request->session()->regenerate();
        $request->session()->put('two_factor_verified_user', $userId);

        return redirect()->intended(route('dashboard'));
    }
}
