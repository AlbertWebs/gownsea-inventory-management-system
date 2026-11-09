<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\CustomOrderInvoice;
use App\Models\EtimsSubmission;
use App\Models\HireInvoice;
use LogicException;

class EtimsPayloadBuilder
{
    /** @return array<string,mixed> */
    public function build(EtimsSubmission $submission): array
    {
        $document = $this->document($submission);
        $invoice = $document instanceof CreditNote
            ? $this->originalInvoice($document)
            : $document;
        $issuedOn = $document->issued_on;
        $settings = app(IntegrationSettings::class)->etims($issuedOn);
        $taxSetup = $settings['tax_setup'] ?? [];
        $profile = $settings['business_profile'] ?? [];

        if (! ($settings['ready'] ?? false)) {
            throw new LogicException('Approved KRA eTIMS settings, legal profile, and VSCU endpoint are required.');
        }

        $branchId = $taxSetup['etims_branch_id'] ?? '';
        $registrantName = $taxSetup['etims_registrant_name'] ?? '';
        $registrantId = $taxSetup['etims_registrant_id'] ?? '';
        $receiptType = $document instanceof CreditNote ? 'R' : ($taxSetup['etims_receipt_type_code'] ?? '');
        $paymentType = $taxSetup['etims_payment_type_code'] ?? '';
        $buyerAcceptance = $taxSetup['etims_buyer_acceptance'] ?? '';

        if (! filled($branchId) || ! filled($registrantName) || ! filled($registrantId)
            || ! filled($receiptType) || ! filled($paymentType) || ! in_array($buyerAcceptance, ['Y', 'N'], true)) {
            throw new LogicException('The approved tax setup is missing required KRA transaction fields.');
        }

        $lines = $document->lines()->orderBy('id')->get();
        if ($lines->isEmpty()) {
            throw new LogicException('The eTIMS document must contain at least one invoice line.');
        }

        $categories = collect($taxSetup['categories'] ?? [])->keyBy('code');
        $fallbackCategory = $this->categoryCode($invoice);
        $items = [];
        $taxGroups = [];
        $totalNetMinor = 0;
        $totalTaxMinor = 0;

        foreach ($lines as $index => $line) {
            $categoryCode = $line->tax_code ?: $fallbackCategory;
            $category = $categories->get($categoryCode);
            if (! is_array($category)) {
                throw new LogicException("No approved eTIMS mapping exists for tax category {$categoryCode}.");
            }

            $taxType = $category['etims_tax_type_code'] ?? null;
            foreach (['etims_item_code', 'etims_item_class_code', 'etims_packaging_unit_code', 'etims_quantity_unit_code'] as $required) {
                if (! filled($category[$required] ?? null)) {
                    throw new LogicException("The approved eTIMS mapping for {$categoryCode} is missing {$required}.");
                }
            }
            if (! in_array($taxType, ['A', 'B', 'C', 'D', 'E'], true)) {
                throw new LogicException("The approved eTIMS mapping for {$categoryCode} has no KRA tax type.");
            }

            $quantity = max(1, (int) $line->quantity);
            $grossMinor = (int) ($line->line_total_minor ?? $line->total_minor);
            $netMinor = (int) ($line->net_minor ?? $grossMinor - (int) $line->tax_minor);
            $taxMinor = (int) $line->tax_minor;
            $totalNetMinor += $netMinor;
            $totalTaxMinor += $taxMinor;
            $unitPrice = $this->money((int) round($grossMinor / $quantity));
            if (isset($taxGroups[$taxType]) && abs($taxGroups[$taxType]['rate'] - (float) ($line->tax_rate ?? 0)) > 0.0001) {
                throw new LogicException("KRA tax type {$taxType} cannot combine different approved rates on one invoice.");
            }
            $taxGroups[$taxType] ??= ['taxable_minor' => 0, 'tax_minor' => 0, 'rate' => (float) ($line->tax_rate ?? 0)];
            $taxGroups[$taxType]['taxable_minor'] += $netMinor;
            $taxGroups[$taxType]['tax_minor'] += $taxMinor;

            $items[] = [
                'itemSeq' => $index + 1,
                'itemCd' => $category['etims_item_code'],
                'itemClsCd' => $category['etims_item_class_code'],
                'itemNm' => mb_substr((string) $line->description, 0, 200),
                'bcd' => null,
                'pkgUnitCd' => $category['etims_packaging_unit_code'],
                'pkg' => $this->money($quantity),
                'qtyUnitCd' => $category['etims_quantity_unit_code'],
                'qty' => $this->money($quantity),
                'prc' => $unitPrice,
                'splyAmt' => $this->money($grossMinor),
                'dcRt' => 0,
                'dcAmt' => 0,
                'isrccCd' => null,
                'isrccNm' => null,
                'isrcRt' => null,
                'isrcAmt' => null,
                'taxTyCd' => $taxType,
                'taxblAmt' => $this->money($netMinor),
                'totTaxAmt' => $this->money($taxMinor),
                'totAmt' => $this->money($grossMinor),
            ];
        }

        $totals = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $type) {
            $group = $taxGroups[$type] ?? ['taxable_minor' => 0, 'tax_minor' => 0, 'rate' => 0];
            $totals['taxblAmt'.$type] = $this->money($group['taxable_minor']);
            $totals['taxRt'.$type] = $group['rate'];
            $totals['taxAmt'.$type] = $this->money($group['tax_minor']);
        }

        $customer = $document->customer;
        $originalSubmission = $document instanceof CreditNote
            ? EtimsSubmission::query()->where('document_type', $document->invoice_type === 'hire' ? 'hire_invoice' : 'custom_order_invoice')
                ->where('document_id', $document->invoice_id)->where('status', 'submitted')->first()
            : null;

        if ($document instanceof CreditNote && $originalSubmission?->remote_invoice_number === null) {
            throw new LogicException('A credit note cannot be sent before the original invoice is accepted by eTIMS.');
        }

        return [
            'tin' => $profile['kra_pin'],
            'bhfId' => $branchId,
            'trdInvcNo' => $document->number,
            'invcNo' => $submission->id,
            'orgInvcNo' => $originalSubmission?->remote_invoice_number ?? 0,
            'custTin' => null,
            'custNm' => mb_substr((string) $customer?->name, 0, 60),
            'salesTyCd' => 'N',
            'rcptTyCd' => $receiptType,
            'pmtTyCd' => $paymentType,
            'salesSttsCd' => $document instanceof CreditNote ? '05' : '02',
            'cfmDt' => now()->format('YmdHis'),
            'salesDt' => $issuedOn->format('Ymd'),
            'rfdDt' => $document instanceof CreditNote ? $issuedOn->format('YmdHis') : null,
            'rfdRsnCd' => $document instanceof CreditNote ? '06' : null,
            'totItemCnt' => count($items),
            ...$totals,
            'totTaxblAmt' => $this->money($totalNetMinor),
            'totTaxAmt' => $this->money($totalTaxMinor),
            'totAmt' => $this->money($document->total_minor),
            'prchrAcptcYn' => $buyerAcceptance,
            'remark' => $document instanceof CreditNote ? mb_substr((string) $document->reason, 0, 400) : null,
            'regrNm' => $registrantName,
            'regrId' => $registrantId,
            'modrNm' => $registrantName,
            'modrId' => $registrantId,
            'receipt' => [
                'custTin' => null,
                'custMblNo' => $customer?->phone,
                'rptNo' => $submission->id,
                'trdeNm' => mb_substr((string) ($profile['trading_name'] ?? ''), 0, 20),
                'adrs' => mb_substr((string) ($profile['registered_address'] ?? ''), 0, 200),
                'topMsg' => null,
                'btmMsg' => null,
                'prchrAcptcYn' => $buyerAcceptance,
            ],
            'itemList' => $items,
        ];
    }

    private function document(EtimsSubmission $submission): HireInvoice|CustomOrderInvoice|CreditNote
    {
        return match ($submission->document_type) {
            'hire_invoice' => HireInvoice::query()->with(['lines', 'customer'])->findOrFail($submission->document_id),
            'custom_order_invoice' => CustomOrderInvoice::query()->with(['lines', 'customer'])->findOrFail($submission->document_id),
            'credit_note' => CreditNote::query()->with(['lines', 'customer'])->findOrFail($submission->document_id),
            default => throw new LogicException('Unsupported eTIMS document type.'),
        };
    }

    private function originalInvoice(CreditNote $note): HireInvoice|CustomOrderInvoice
    {
        return match ($note->invoice_type) {
            'hire' => HireInvoice::query()->findOrFail($note->invoice_id),
            'custom_order' => CustomOrderInvoice::query()->findOrFail($note->invoice_id),
            default => throw new LogicException('Unsupported original invoice type.'),
        };
    }

    private function categoryCode(HireInvoice|CustomOrderInvoice $invoice): string
    {
        return $invoice instanceof HireInvoice ? 'gown_hire' : 'custom_orders';
    }

    private function money(int $minor): float
    {
        return (float) number_format($minor / 100, 2, '.', '');
    }
}
