<?php

namespace App\Services;

use App\Models\BusinessConfiguration;
use Carbon\CarbonInterface;

class IntegrationSettings
{
    /** @return array<string,mixed> */
    public function onfon(): array
    {
        $configuration = config('services.onfon');
        $saved = $this->approvedConfiguration();

        foreach (['endpoint', 'client_id', 'api_key', 'access_key', 'sender_id'] as $key) {
            $value = $saved['onfon_'.$key] ?? null;
            if (filled($value)) {
                $configuration[$key] = $value;
            }
        }

        return $configuration;
    }

    /** @return array<string,mixed> */
    public function daraja(): array
    {
        $configuration = config('services.daraja');
        $saved = $this->approvedConfiguration();

        foreach (['enabled', 'environment', 'consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_token', 'transaction_type'] as $key) {
            $settingKey = 'daraja_'.$key;
            if (array_key_exists($settingKey, $saved)) {
                $configuration[$key] = $saved[$settingKey];
            }
        }

        return $configuration;
    }

    /** @param array<string,mixed> $configuration */
    public function darajaEnabled(array $configuration): bool
    {
        return (bool) ($configuration['enabled'] ?? false)
            && filled($configuration['consumer_key'] ?? null)
            && filled($configuration['consumer_secret'] ?? null)
            && filled($configuration['shortcode'] ?? null)
            && filled($configuration['passkey'] ?? null)
            && strlen((string) ($configuration['callback_token'] ?? '')) >= 32;
    }

    /** @return array<string,mixed> */
    public function etims(?CarbonInterface $date = null): array
    {
        $configuration = config('services.etims');
        $taxSetup = $this->configurationFor('tax_setup', $date);
        $businessProfile = $this->configurationFor('business_profile', $date);

        $configuration['tax_setup'] = $taxSetup['configuration'] ?? [];
        $configuration['business_profile'] = $businessProfile['configuration'] ?? [];
        $configuration['tax_setup_id'] = $taxSetup['id'] ?? null;
        $configuration['business_profile_id'] = $businessProfile['id'] ?? null;
        $configuration['ready'] = ($configuration['enabled'] ?? false)
            && ($configuration['provider'] ?? null) === 'kra_vscu'
            && ($taxSetup['configuration']['etims_solution'] ?? null) === 'kra'
            && filled($businessProfile['configuration']['kra_pin'] ?? null)
            && filled($configuration['vscu_endpoint'] ?? null);

        return $configuration;
    }

    /** @return array{configuration: array<string,mixed>, id: int}|null */
    private function configurationFor(string $key, ?CarbonInterface $date = null): ?array
    {
        $configuration = BusinessConfiguration::query()
            ->where('configuration_key', $key)
            ->where('review_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', ($date ?? now())->toDateString()))
            ->latest('effective_from')
            ->latest('id')
            ->first();

        if ($configuration === null) {
            return null;
        }

        return ['configuration' => $configuration->configuration, 'id' => $configuration->id];
    }

    /** @return array<string,mixed> */
    private function approvedConfiguration(): array
    {
        return BusinessConfiguration::query()
            ->where('configuration_key', 'provider_integrations')
            ->where('review_status', 'approved')
            ->latest('id')
            ->first()?->configuration ?? [];
    }
}
