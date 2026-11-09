<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Security\TwoFactorCodeVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use OTPHP\TOTP;

class AccountSecurityController extends Controller
{
    private const PRIVILEGED_ROLES = ['super_admin', 'management', 'finance'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $pendingSecret = $user->two_factor_pending_secret;
        $provisioningUri = $pendingSecret === null
            ? null
            : TOTP::createFromSecret($pendingSecret)->withIssuer('GOWNSEA')->withLabel($user->email)->getProvisioningUri();

        return Inertia::render('Account/Security', [
            'eligible' => in_array($user->role, self::PRIVILEGED_ROLES, true),
            'enabled' => $user->two_factor_confirmed_at !== null,
            'pendingSecret' => $pendingSecret,
            'provisioningUri' => $provisioningUri,
            'recoveryCodesRemaining' => count($user->two_factor_recovery_codes ?? []),
            'recoveryCodes' => $request->session()->pull('twoFactorRecoveryCodes', []),
            'otherSessionCount' => $this->otherSessionCount($request),
            'canManageSessions' => config('session.driver') === 'database' && Schema::hasTable('sessions'),
        ]);
    }

    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        abort_unless(config('session.driver') === 'database' && Schema::hasTable('sessions'), 404);

        $user = $request->user();
        $deleted = DB::table(config('session.table'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();
        if ($deleted > 0) {
            $this->audit($request, 'security.other_sessions_revoked', $user, ['other_sessions' => $deleted], ['other_sessions' => 0]);
        }

        return back()->with('success', $deleted > 0 ? "Signed out {$deleted} other session(s)." : 'There are no other active sessions to sign out.');
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, self::PRIVILEGED_ROLES, true), 403);
        if ($user->two_factor_confirmed_at !== null) {
            throw ValidationException::withMessages(['two_factor' => 'Two-factor authentication is already enabled.']);
        }

        $otp = TOTP::generate(secretSize: 20)->withIssuer('GOWNSEA')->withLabel($user->email);
        $user->forceFill(['two_factor_pending_secret' => $otp->getSecret()])->save();
        $this->audit($request, 'security.two_factor_setup_started', $user, ['two_factor_enabled' => false], ['setup_pending' => true]);

        return back()->with('success', 'Add the displayed key to your authenticator app, then verify a current six-digit code.');
    }

    public function confirm(Request $request, TwoFactorCodeVerifier $verifier): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'regex:/^\d{6}$/']]);
        $user = $request->user();
        abort_unless(in_array($user->role, self::PRIVILEGED_ROLES, true), 403);
        if ($user->two_factor_confirmed_at !== null || $user->two_factor_pending_secret === null) {
            throw ValidationException::withMessages(['two_factor' => 'Start two-factor setup before verifying a code.']);
        }

        $step = $verifier->matchingStep($user->two_factor_pending_secret, $data['code']);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That authenticator code is invalid or expired.']);
        }

        $recoveryCodes = collect(range(1, 10))->map(fn (): string => Str::upper(Str::random(24)))->all();
        $user->forceFill([
            'two_factor_secret' => $user->two_factor_pending_secret,
            'two_factor_pending_secret' => null,
            'two_factor_recovery_codes' => array_map(fn (string $code): string => Hash::make($code), $recoveryCodes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => $step,
        ])->save();
        $request->session()->put('two_factor_verified_user', $user->id);
        $this->audit($request, 'security.two_factor_enabled', $user, ['two_factor_enabled' => false], ['two_factor_enabled' => true]);

        return back()->with('twoFactorRecoveryCodes', $recoveryCodes)->with('success', 'Two-factor authentication is enabled. Save your recovery codes now; they are shown only once.');
    }

    public function disable(Request $request, TwoFactorCodeVerifier $verifier): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'regex:/^\d{6}$/'],
        ]);
        $user = $request->user();
        abort_unless(in_array($user->role, self::PRIVILEGED_ROLES, true), 403);
        if ($user->two_factor_confirmed_at === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password or authenticator code is incorrect.']);
        }
        $step = $verifier->matchingStep($user->two_factor_secret, $data['code'], $user->two_factor_last_used_step);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'The password or authenticator code is incorrect.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_pending_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
        $request->session()->forget('two_factor_verified_user');
        $this->audit($request, 'security.two_factor_disabled', $user, ['two_factor_enabled' => true], ['two_factor_enabled' => false]);

        return back()->with('success', 'Two-factor authentication has been disabled for this account.');
    }

    private function audit(Request $request, string $action, User $user, array $before, array $after): void
    {
        AuditLog::query()->create([
            'actor_id' => $user->id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'before_state' => $before,
            'after_state' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    private function otherSessionCount(Request $request): int
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            return 0;
        }

        return DB::table(config('session.table'))
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->count();
    }
}
