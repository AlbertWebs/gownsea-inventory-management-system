# Accountant review and cutover packet

**Status:** decisions and source-record review required before accounting cutover. This document describes current application behavior; it does not set Kenyan tax treatment or accounting policy.

## 1. Late-return charges

### Current application behavior

- Each hire line snapshots its effective policy when it is reserved or issued. The snapshot contains a per-day amount, grace days, cap and whether the return date is billable. Policy precedence is contract, product, institution, then default.
- The operational receivable calculates fees as of the selected date for units still with a customer. Partial bulk returns reduce the accruing quantity. Returned units carry a frozen assessed amount; collection and approved waiver reduce the customer balance.
- The daily amount is currently derived from the policy snapshot. It is not written to a dated accrual ledger or journal. The journal credits account `4010` (“Collected penalty revenue”) when a penalty receipt is recorded. An unpaid daily accrual therefore appears in operational balances, not in posted journal balances. Waivers are audited operational adjustments but do not currently post a journal entry; penalty refunds reverse the collected-penalty revenue and cash entry.

### Proposed posting choices for approval

Choose the recognition trigger by reference to GOWNSEA's customer hire agreement and adopted reporting framework. If a daily charge becomes enforceable each billable day, the proposed entry is Dr `1100 Customer receivables` / Cr `4010 Penalty income` on that day, subject to the accountant's assessment of variable consideration and any constraint. If the right becomes enforceable only when GOWNSEA assesses or communicates the charge, retain the daily amount as an operational estimate and post on that assessment date instead. IFRS 15 describes revenue as consideration expected in exchange for transferred goods/services and constrains variable consideration where a significant reversal is not highly improbable; the accountant must confirm whether and how that framework applies to these contractual charges ([IFRS 15 overview](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-15-revenue-from-contracts-with-customers/), [IFRS Foundation paper on variable consideration](https://www.ifrs.org/content/dam/ifrs/meetings/2024/march/iasb/ap6a-ifrs15-pir-transaction-price.pdf)).

Under either choice, collection should settle the receivable (Dr cash/clearing, Cr `1100`) instead of recognizing penalty income a second time. An approved waiver after recognition needs an auditable debit to the approved reversal/contra-income account and credit to receivables. Refunds must reverse the original recognition and cash settlement consistently. These are proposed journal mechanics; approve the accounts and dates below before they are implemented.

### Recommended starting position (not yet approved)

To minimize premature revenue recognition and avoid reopening closed periods, use these as the accountant discussion defaults:

- Keep daily late-fee calculations as operational estimates while a hire remains open. Recognize the charge in the GL when it is formally assessed at return or communicated to the customer, unless the signed hire terms and accountant determine that an enforceable right arises earlier.
- Record a formally assessed but unpaid fee in receivables and the approved penalty-income account on the assessment/notice date. Collections settle receivables; they do not create penalty income a second time.
- Require a reason and authorized manager approval for waivers, with finance approval for amounts above a threshold set by management. Reverse any previously recognized receivable and income through a traceable contra-income/reversal entry. Refunds reverse the original settlement and the appropriate receivable/revenue entries.
- At launch, reconcile already-overdue hires to source records and carry only the approved net amount as a dated opening receivable at the cutover date. Do not generate a series of backdated daily GL entries for pre-launch days.
- For un-invoiced historical hire/custom-order work, prefer a reviewed opening-balance cutover entry over posting into closed historical periods. Preserve each source event date for audit and reporting. Finance may approve original dates instead after reviewing the candidate population and period-close implications.

These are recommendations for approval, not configured policy. The accountant should replace any item that conflicts with GOWNSEA's signed customer terms, adopted reporting framework, tax advice or closed-period controls. No GL policy or historical backfill date changes until the approval table and source-record review are complete.

### Accountant decisions needed

| Decision | Approved treatment / reference |
| --- | --- |
| Recognition trigger: each billable day, return/assessment, or another event | |
| Date used for each entry and treatment of late returns spanning a closed period | |
| Debit account for assessed but unpaid amounts | |
| Credit account for assessed penalty income, and whether it differs from account `4010` used for collections | |
| Account and posting date for a waiver after recognition | |
| Account and posting date for a refund after recognition/payment | |
| Treatment of grace days, return-day inclusion, cap, partial returns, disputes, waivers and refunds | |
| Tax/statutory treatment of each penalty category | |
| Opening treatment for hires already overdue at cutover | |
| Approval date, accountant name, supporting policy/reference | |

Do not enable dated GL accrual postings until the recognition trigger, journal date, reversal/waiver handling and chart-account mapping are approved together. The operational fee calculation and the accounting journal must use the same approved policy snapshot and effective date.

## 2. Tax and statutory mappings

The current quotation writer sets `tax_minor` to zero. Hire and custom-order invoice schemas store a total and line amounts without tax components. Tax registration, statutory accounts and eTIMS integration are not configured in this application.

KRA's current guidance describes a 16% general VAT rate for taxable supplies, 0% for specific listed zero-rated supplies, and separate exempt supplies; KRA also describes the KES 5 million annual taxable-supplies registration threshold and voluntary registration below it subject to conditions. KRA says persons engaged in business must onboard eTIMS, including businesses that are not VAT registered. These are current KRA summaries, not a classification of GOWNSEA's specific supplies: confirm GOWNSEA's tax obligations and each product/service category with its accountant and current iTax profile ([KRA VAT guidance](https://www.kra.go.ke/individual/filing-paying/types-of-taxes/value-added-tax), [KRA tax obligations](https://www.kra.go.ke/individual/individual-pin-registration/93-tax-obligations), [KRA eTIMS guidance](https://www.kra.go.ke/online-services/etims)).

Before enabling tax-bearing documents or electronic invoicing, provide written, effective-dated instructions for:

- Legal entity name, address and registration identifiers to print on sales documents.
- Registration status and effective dates for each applicable tax obligation.
- Product/service categories and their treatment, rates, exemptions or other classifications, with the accountant's supporting basis.
- Whether displayed prices include tax, rounding rules, and treatment of discounts, deposits, refunds and credit notes.
- Output/input tax accounts and mappings to the approved chart of accounts.
- Required invoice, credit-note and electronic invoicing fields/processes, selected eTIMS solution/integration and test onboarding path.

No rate or tax category should be inferred from sample data or hardcoded before these inputs are approved.

**Recommended implementation sequence:** keep tax disabled in calculations and documents until GOWNSEA's registration profile and the accountant's effective-dated category/account matrix are received; implement the matrix as data/configuration rather than embedding rates in invoice code; then verify tax invoices, credit notes and rounding against the selected eTIMS onboarding/test path before production use. This is an implementation recommendation only and does not assign a tax category to any GOWNSEA supply.

## 3. Historical invoice backfill

### Current eligibility and posting behavior

- Hire candidates must have an `issued_at` timestamp, an eligible handed-over/current status, at least one hire line, no existing invoice and no disallowed line status. The invoice amount is each line's quantity multiplied by its snapshotted unit rate. Its date is the original handover date.
- Completed custom orders with a completion timestamp, positive agreed total and no invoice are eligible. The invoice amount is the agreed total, dated on the original completion date.
- The dry-run now shows each candidate's source reference, customer, location, event date, invoice amount, charge receipts net of refunds, applied deposits and remaining balance. It does not create invoices or journal entries.
- Confirmation creates immutable invoices, balanced journal entries and receipt/refund/deposit allocations. Existing pre-invoice revenue/advance entries may be reclassified. The operation is idempotent; a source whose net settlements exceed its invoice amount fails and needs reconciliation.
- Journal dates use original handover/completion dates. Finance must explicitly decide whether historical periods may be restated or whether an approved opening-balance/cutover-date approach is required before confirmation.

### Review procedure

1. Restore a current database snapshot into an isolated finance-review environment and apply the current migrations there. Do not use a live database for the first preview.
2. Run `php artisan gownsea:invoice-backfill --dry-run --no-ansi` and retain the complete candidate list in an approved, access-controlled finance location. It includes customer names and financial balances; do not commit it to the repository or attach it to a public issue.
3. Reconcile every candidate's handover/completion date, customer, branch, line quantity/rate, receipts, refunds, deposit applications and remaining balance to source records. Investigate overpayments, missing documents, prior-period entries, cancellations and duplicate/incorrect records.
4. Finance approves the reviewed population, historical-date treatment and opening receivables schedule. Record the approver and the snapshot/database version reviewed.
5. Take and verify a pre-cutover backup. Run the command with `--confirm-reviewed` first in staging; reconcile invoice counts, totals, allocations, account balances and period reports. Repeat in production only under the approved release and recovery plan.

### Cutover approval

| Cutover decision | Approved value / reference |
| --- | --- |
| Database snapshot/environment and review date | |
| Eligible hire count and total | |
| Eligible custom-order count and total | |
| Historical periods approved for posting, or approved cutover date | |
| Opening receivables schedule reference and control total | |
| Exceptions resolved or excluded, with reasons | |
| Pre-cutover backup identifier and restore verification | |
| Finance approver and date | |

## 4. Local review limitation

The repository's current `database/database.sqlite` is not migrated through the invoice-table migration: the dry-run stops with `no such table: hire_invoices`, before it can list candidates or write anything. No migration or backfill was run against that file. Its records are therefore not suitable for accountant review; use an isolated, current database snapshot as described above.
