<?php

namespace App\Services;

use App\Models\BusinessConfiguration;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoiceTaxCalculator
{
    /**
     * @param  list<array{category_code:string,base_minor:int,default_revenue_account:string}>  $lines
     * @return array{configuration_id:?int,subtotal_minor:int,tax_minor:int,total_minor:int,lines:list<array{net_minor:int,tax_minor:int,line_total_minor:int,tax_code:?string,tax_treatment:string,tax_rate:?string,tax_account_code:?string,revenue_account_code:string}>}
     */
    public function calculate(array $lines, string $issuedOn): array
    {
        $configuration = BusinessConfiguration::query()
            ->where('configuration_key', 'tax_setup')
            ->where('review_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $issuedOn))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();

        if ($configuration === null) {
            return $this->withoutTax($lines);
        }

        $tax = $configuration->configuration;
        if (($tax['registration_status'] ?? 'unknown') !== 'vat_registered') {
            $result = $this->withoutTax($lines);
            $result['configuration_id'] = $configuration->id;

            return $result;
        }
        if (! in_array($tax['price_mode'] ?? null, ['inclusive', 'exclusive'], true)) {
            throw new LogicException('Approved VAT configuration must specify whether prices include or exclude tax.');
        }
        if (! in_array($tax['rounding_mode'] ?? null, ['line', 'invoice'], true)) {
            throw new LogicException('Approved VAT configuration must specify its rounding method.');
        }

        $prepared = [];
        foreach ($lines as $index => $line) {
            $category = collect($tax['categories'] ?? [])->first(function (array $candidate) use ($line, $issuedOn): bool {
                return ($candidate['code'] ?? null) === $line['category_code']
                    && ($candidate['effective_from'] ?? '') <= $issuedOn
                    && (($candidate['effective_to'] ?? '') === '' || $candidate['effective_to'] >= $issuedOn);
            });
            if (! is_array($category) || ($category['treatment'] ?? 'unconfigured') === 'unconfigured') {
                throw new LogicException("Approved tax configuration has no effective category for {$line['category_code']}.");
            }

            $treatment = $category['treatment'];
            $rate = $treatment === 'taxable' ? (string) ($category['rate'] ?? '') : null;
            $rateUnits = $treatment === 'taxable' ? $this->rateUnits($rate) : 0;
            $taxAccount = $treatment === 'taxable' ? (string) ($category['output_account_code'] ?? '') : null;
            $revenueAccount = filled($category['revenue_account_code'] ?? null) ? (string) $category['revenue_account_code'] : $line['default_revenue_account'];
            if ($treatment === 'taxable') {
                $this->assertAccount($taxAccount, 'liability', 'output tax');
            }
            $this->assertAccount($revenueAccount, 'revenue', 'revenue');

            $denominator = $tax['price_mode'] === 'inclusive' ? 1_000_000 + $rateUnits : 1_000_000;
            $numerator = $line['base_minor'] * $rateUnits;
            $groupKey = implode('|', [$category['code'], $treatment, (string) $rateUnits, (string) $taxAccount, $revenueAccount, (string) $denominator]);
            $prepared[$index] = [
                'base_minor' => $line['base_minor'], 'tax_code' => (string) $category['code'], 'tax_treatment' => $treatment,
                'tax_rate' => $rate, 'tax_account_code' => $taxAccount, 'revenue_account_code' => $revenueAccount,
                'numerator' => $numerator, 'denominator' => $denominator, 'group_key' => $groupKey,
                'tax_minor' => 0,
            ];
        }

        $groups = collect($prepared)->groupBy('group_key');
        foreach ($groups as $indices) {
            $first = $indices->first();
            if ($first['tax_treatment'] !== 'taxable') {
                continue;
            }
            if ($tax['rounding_mode'] === 'line') {
                foreach ($indices->keys() as $index) {
                    $prepared[$index]['tax_minor'] = $this->roundRatio($prepared[$index]['numerator'], $prepared[$index]['denominator']);
                }

                continue;
            }

            $floors = $indices->mapWithKeys(fn (array $line, int $index): array => [$index => intdiv($line['numerator'], $line['denominator'])]);
            $target = $this->roundRatio($indices->sum('numerator'), $first['denominator']);
            $remaining = $target - $floors->sum();
            $remainderOrder = $indices->keys()->sortByDesc(fn (int $index): int => $prepared[$index]['numerator'] % $prepared[$index]['denominator'])->values();
            foreach ($indices->keys() as $index) {
                $prepared[$index]['tax_minor'] = $floors[$index];
            }
            foreach ($remainderOrder->take($remaining) as $index) {
                $prepared[$index]['tax_minor']++;
            }
        }

        $calculated = [];
        foreach ($prepared as $line) {
            $inclusive = $tax['price_mode'] === 'inclusive';
            $net = $inclusive ? $line['base_minor'] - $line['tax_minor'] : $line['base_minor'];
            $gross = $inclusive ? $line['base_minor'] : $line['base_minor'] + $line['tax_minor'];
            $calculated[] = [
                'net_minor' => $net, 'tax_minor' => $line['tax_minor'], 'line_total_minor' => $gross,
                'tax_code' => $line['tax_code'], 'tax_treatment' => $line['tax_treatment'],
                'tax_rate' => $line['tax_rate'], 'tax_account_code' => $line['tax_account_code'],
                'revenue_account_code' => $line['revenue_account_code'],
            ];
        }

        return [
            'configuration_id' => $configuration->id,
            'subtotal_minor' => array_sum(array_column($calculated, 'net_minor')),
            'tax_minor' => array_sum(array_column($calculated, 'tax_minor')),
            'total_minor' => array_sum(array_column($calculated, 'line_total_minor')),
            'lines' => $calculated,
        ];
    }

    /** @param list<array{category_code:string,base_minor:int,default_revenue_account:string}> $lines
     * @return array{configuration_id:?int,subtotal_minor:int,tax_minor:int,total_minor:int,lines:list<array{net_minor:int,tax_minor:int,line_total_minor:int,tax_code:?string,tax_treatment:string,tax_rate:?string,tax_account_code:?string,revenue_account_code:string}>}
     */
    private function withoutTax(array $lines): array
    {
        $calculated = array_map(fn (array $line): array => [
            'net_minor' => $line['base_minor'], 'tax_minor' => 0, 'line_total_minor' => $line['base_minor'],
            'tax_code' => null, 'tax_treatment' => 'not_configured', 'tax_rate' => null,
            'tax_account_code' => null, 'revenue_account_code' => $line['default_revenue_account'],
        ], $lines);

        return [
            'configuration_id' => null, 'subtotal_minor' => array_sum(array_column($calculated, 'net_minor')),
            'tax_minor' => 0, 'total_minor' => array_sum(array_column($calculated, 'line_total_minor')), 'lines' => $calculated,
        ];
    }

    private function rateUnits(string $rate): int
    {
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $rate, $matches)) {
            throw new LogicException('Approved taxable category has an invalid tax rate.');
        }
        $units = ((int) $matches[1] * 10_000) + (int) str_pad($matches[2] ?? '', 4, '0');
        if ($units <= 0) {
            throw new LogicException('Approved taxable category must have a positive tax rate.');
        }

        return $units;
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        return $remainder * 2 >= $denominator ? $quotient + 1 : $quotient;
    }

    private function assertAccount(string $code, string $type, string $purpose): void
    {
        $exists = DB::table('chart_accounts')->where('code', $code)->where('account_type', $type)->where('is_active', true)->exists();
        if (! $exists) {
            throw new LogicException("Configured {$purpose} account {$code} is missing, inactive or has the wrong account type.");
        }
    }
}
