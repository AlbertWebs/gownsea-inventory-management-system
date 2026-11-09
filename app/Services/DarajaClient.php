<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class DarajaClient
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    /** @return array<string,mixed> */
    public function initiateStkPush(string $phone, int $amountShillings, string $reference): array
    {
        $config = $this->settings->daraja();
        $baseUrl = $this->baseUrl();
        $shortcode = (string) $config['shortcode'];
        $timestamp = now(config('app.timezone'))->format('YmdHis');
        $password = base64_encode($shortcode.(string) $config['passkey'].$timestamp);
        $callbackUrl = route('daraja.stk.callback', ['token' => (string) $config['callback_token']], true);
        if (app()->isProduction() && ! str_starts_with($callbackUrl, 'https://')) {
            throw new RuntimeException('Daraja production callbacks must use HTTPS.');
        }

        $response = Http::acceptJson()->asJson()->withToken($this->accessToken())
            ->connectTimeout(5)->timeout(20)
            ->post($baseUrl.'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => $config['transaction_type'],
                'Amount' => $amountShillings,
                'PartyA' => $phone,
                'PartyB' => $shortcode,
                'PhoneNumber' => $phone,
                'CallBackURL' => $callbackUrl,
                'AccountReference' => Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?: 'GOWNSEA', 12, ''),
                'TransactionDesc' => 'GOWNSEA payment',
            ]);

        $response->throw();

        return $response->json() ?? throw new RuntimeException('Daraja returned a non-JSON response.');
    }

    /** @return array<string,mixed> */
    public function queryStkPushStatus(string $checkoutRequestId): array
    {
        $config = $this->settings->daraja();
        $shortcode = (string) $config['shortcode'];
        $timestamp = now(config('app.timezone'))->format('YmdHis');
        $password = base64_encode($shortcode.(string) $config['passkey'].$timestamp);

        $response = Http::acceptJson()->asJson()->withToken($this->accessToken())
            ->connectTimeout(5)->timeout(20)
            ->post($this->baseUrl().'/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);

        $response->throw();

        return $response->json() ?? throw new RuntimeException('Daraja returned a non-JSON status response.');
    }

    private function accessToken(): string
    {
        $config = $this->settings->daraja();
        $cacheKey = 'daraja.oauth.'.hash('sha256', $config['consumer_key'].':'.$config['environment']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return Cache::lock($cacheKey.'.lock', 20)->block(8, function () use ($config, $cacheKey): string {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $response = Http::withBasicAuth((string) $config['consumer_key'], (string) $config['consumer_secret'])
                ->acceptJson()->connectTimeout(5)->timeout(15)
                ->get($this->baseUrl().'/oauth/v1/generate', ['grant_type' => 'client_credentials'])->throw();
            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Daraja did not return an access token.');
            }

            Cache::put($cacheKey, $token, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 60)));

            return $token;
        });
    }

    private function baseUrl(): string
    {
        $environment = $this->settings->daraja()['environment'];
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('DARAJA_ENVIRONMENT must be sandbox or production.');
        }

        return $environment === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }
}
