<?php

namespace App\Security;

use OTPHP\TOTP;

class TwoFactorCodeVerifier
{
    public function matchingStep(string $secret, string $code, ?int $lastUsedStep = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $totp = TOTP::createFromSecret($secret)->withDigits(6);
        $currentStep = intdiv(now()->timestamp, $totp->getPeriod());
        foreach ([$currentStep, $currentStep - 1, $currentStep + 1] as $step) {
            if ($step > ($lastUsedStep ?? -1) && $totp->verify($code, $step * $totp->getPeriod())) {
                return $step;
            }
        }

        return null;
    }
}
