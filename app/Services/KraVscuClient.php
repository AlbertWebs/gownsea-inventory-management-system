<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

class KraVscuClient
{
    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveSales(array $payload): array
    {
        $settings = config('services.etims');
        $endpoint = rtrim((string) ($settings['vscu_endpoint'] ?? ''), '/');
        if (! ($settings['enabled'] ?? false) || ($settings['provider'] ?? null) !== 'kra_vscu' || $endpoint === '') {
            throw new LogicException('KRA VSCU submission is not enabled or the endpoint is missing.');
        }

        $parts = parse_url($endpoint);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])) {
            throw new LogicException('The KRA VSCU endpoint must be an HTTP(S) URL.');
        }
        if (($parts['scheme'] ?? '') === 'http' && ! $this->isPrivateHost((string) $parts['host'])) {
            throw new LogicException('Plain HTTP is allowed only for a local or private VSCU bridge endpoint.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout((int) ($settings['connect_timeout'] ?? 5))
                ->timeout((int) ($settings['timeout'] ?? 20))
                ->post($endpoint.'/trnsSales/saveSales', $payload);
        } catch (ConnectionException $exception) {
            throw $exception;
        }

        if (! $response->successful()) {
            return [
                'http_status' => $response->status(),
                'resultCd' => null,
                'resultMsg' => 'The VSCU bridge returned an HTTP error. Check the VSCU logs before retrying.',
            ];
        }

        $result = $response->json();
        if (! is_array($result)) {
            return ['http_status' => $response->status(), 'resultCd' => null, 'resultMsg' => 'The VSCU bridge returned an invalid response.'];
        }

        return [...$result, 'http_status' => $response->status()];
    }

    private function isPrivateHost(string $host): bool
    {
        if (in_array(strtolower($host), ['localhost', 'vscu'], true)) {
            return true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
