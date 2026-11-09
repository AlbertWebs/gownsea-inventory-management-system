# GOWNSEA Production Readiness Questionnaire

**For:** GOWNSEA business, finance and operations representatives  
**Purpose:** Collect the decisions, source data and access arrangements needed to complete accounting cutover, provider verification and production readiness.  
**Prepared:** 14 October 2026

> **Draft status:** Recommended answers in this questionnaire are proposals for client review. They are not signed-off business policy, accountant approval, confirmed company facts, or authorization to enable production settings. For each proposal, approve it or write the replacement answer in the response field.

## How to complete

- Enter an answer under each question. Write **Not applicable** or **To be confirmed** where appropriate, and name the person responsible and target date for anything deferred.
- Accounting and tax answers must be reviewed and signed by GOWNSEA's accountant or authorized finance approver.
- Do not put passwords, API keys, PINs, passkeys, recovery codes or other secrets in this file or send them by email. Arrange secure, out-of-band transfer after the relevant accounts are created. The credentials previously included in `Gownsea.pdf` should be considered exposed and rotated.
- Attach supporting schedules or references using an approved, access-controlled channel. Do not add customer-level financial data to a public repository or issue tracker.

## 1. Late-return penalty accounting

The application calculates late fees from the policy saved on each hire. Dated journal postings and approved reversal handling are not enabled until the accounting treatment is approved.

1. **When does a late-return charge become recognizable in the accounts?** Choose one and explain any exception:
   - [ ] Each billable day, as the charge becomes enforceable (**recommended**, only where the hire agreement creates an enforceable right and the accountant approves)
   - [ ] On physical return
   - [ ] On formal assessment or notice to the customer
   - [ ] Other: ____________________
   - Supporting hire-agreement clause/accounting reference: ____________________
   - Client/management decision: [ ] Approve recommendation [ ] Change it: ____________________
   - Accountant approval/reference: ____________________

2. **What date should be used for the journal entry?** Address charges spanning a month-end or closed period.
   - **Recommended:** Use the date the charge becomes recognizable under the approved policy. Configure an effective-dated policy; lock closed periods; post approved adjustments to the current open period where appropriate; prohibit unrestricted backdating and rewriting posted journals.
   - Client/finance decision or exception: ____________________

3. **Which chart-of-account codes should be used?** Confirm the account name and code for each:
   - Assessed penalty receivable: **1100 – Customer Receivables (proposed; verify existing account)**. Approved: ____________________
   - Penalty income: **4010 – Late Return Penalty Income (proposed; verify existing account)**. Approved: ____________________
   - Waivers/contra-income: **4090 – Penalty Waivers / Contra Income (proposed; check for code conflict)**. Approved: ____________________
   - Refund or reversal clearing, if separate: **1195 – Refund Clearing (proposed; check for code conflict)**. Approved: ____________________
   - Cash/bank/M-Pesa: use existing approved cash/clearing account code(s): ____________________
   - **Recommended flow:** Recognition Dr 1100 / Cr 4010; collection Dr cash/clearing / Cr 1100; approved waiver Dr approved contra-income / Cr 1100. Link refunds to the original payment and reverse only entries still applicable.

4. **Confirm the operational penalty rules** (including any institution/product/contract exceptions):
   - Daily amount or rate: **KES 100 per gown per day (illustrative proposal only; management must approve)**. Approved: ____________________
   - Grace period: **1 calendar day (proposed)**. Approved: ____________________
   - Maximum charge: **100% of original hire charge (proposed)**. Approved: ____________________
   - Return date: **charge only after grace period and where the signed agreement makes that day billable (proposed)**. Approved: ____________________
   - Partial returns: **stop accrual for returned quantities (proposed)**. Approved: ____________________
   - Partial days: **no hourly prorating initially; define a documented daily cutoff (proposed)**. Approved cutoff: ____________________
   - Disputed charges: **flag for review and suspend collection actions while under review (proposed)**. Approved: ____________________
   - Policy precedence: **contract → product → institution → global default (proposed)**. Approved: ____________________

5. **Who may approve waivers, and are there approval limits?** Include required reason/evidence and any amount threshold for a second approver.
   - **Proposed roles/thresholds:** branch staff may request only; branch manager may approve through KES 1,000; finance manager may approve KES 1,001–5,000; authorized director/delegated approver may approve above KES 5,000. Require reason, evidence and audit history; requester cannot approve their own request.
   - Approve or replace roles/thresholds: ____________________

6. **How should an approved waiver be posted?** Confirm debit/credit accounts and posting date, including for a fee that has already been recognized.
   - **Recommended:** If already recognized, post on approval date (or approved open-period date): Dr approved waiver/contra-income / Cr 1100 Customer Receivables. If not yet recognized, reduce the operational assessment without a journal reversal.
   - Accountant-approved treatment: ____________________

7. **How should penalty refunds be handled?** Confirm approval, original-payment linkage, accounts and reversal date, including fees previously recognized and paid.
   - **Recommended:** Require approval and reason; link to the original charge/payment; cap at the refundable amount; reverse the appropriate settlement and recognition entries once. Do not reverse income again if it was already reversed by a waiver or credit note.
   - Accountant-approved treatment: ____________________

8. **How should hires already overdue on the launch/cutover date be treated?** Confirm the source date, calculation method, disputed/waived amounts, opening receivable date and approving person.
   - **Recommended:** Calculate the approved net amount as of the cutover date using the policy applicable to each hire. Verify due date, returned quantities, collections, disputes and waivers. Import only approved outstanding balances and do not duplicate receivables already recorded.
   - Finance/accountant decision and cutover date: ____________________

9. **Approve the late-fee accounting setup:**
   - Accountant name/title: ____________________
   - Approval date: ____________________
   - Supporting policy or agreement reference: ____________________

## 2. Tax, statutory details and eTIMS

Tax calculations and statutory reporting remain disabled until GOWNSEA's registration information and accountant-approved mappings are supplied.

1. **Legal business details to display on invoices and credit notes:**
   - Registered legal name: ____________________
   - Trading name (if different): ____________________
   - Registered address: ____________________
   - KRA PIN (provide through the approved secure channel): ____________________

2. **What is GOWNSEA's tax registration status and effective date?** Complete each applicable row.

   | Tax obligation | Registered? | Registration/reference | Effective date | Supporting document/reference |
   | --- | --- | --- | --- | --- |
   | VAT | | | | |
   | Other (name it) | | | | |

   **Recommended:** Configure from GOWNSEA's actual iTax profile and effective dates. Support registered and non-registered configurations; do not infer registration status.

3. **Provide an accountant-approved tax classification for each supply type.** Include category/code, rate or treatment, account mapping and effective dates. Do not infer a category from the examples below.

   | Supply type | Approved category/treatment | Rate | Effective from/to | Output tax account | Revenue account | Accountant/reference |
   | --- | --- | --- | --- | --- | --- | --- |
   | Gown hire | | | | | | |
   | Gown/accessory sales | | | | | | |
   | Custom orders | | | | | | |
   | Late-return penalties | | | | | | |
   | Other services (specify) | | | | | | |

   **Recommended:** Use separate effective-dated tax codes for each supply type. The accountant must classify each actual supply, including the contractual and tax character of penalties. Do not assume a rate or treatment.

4. **Are customer-facing prices tax-inclusive or tax-exclusive?** State whether this differs by supply type.
   - **Recommended default:** Tax-inclusive customer-facing retail prices, subject to management/accountant approval; support tax-exclusive institutional/corporate quotations when approved.
   - Approved answer and exceptions by supply type: ____________________

5. **Confirm document arithmetic rules:**
   - Discounts: **apply before tax where legally appropriate** (proposed). Approved answer: ____________________
   - Advance payments: **track as customer advances and apply to invoices** (proposed). Approved answer: ____________________
   - Refundable security deposits: **keep separate from revenue; accountant to confirm tax timing** (proposed). Approved answer: ____________________
   - Tax rounding: **use exact/decimal arithmetic and reconcile invoice totals** (proposed). Exact method/level: ____________________
   - Refunds, cancellations and credit notes: **link to original invoice/payment and follow approved reversal/eTIMS rules** (proposed). Approved answer: ____________________

6. **What eTIMS route has GOWNSEA selected?**
   - [ ] KRA solution
   - [ ] Approved third-party integrator (name): ____________________
   - [ ] To be selected
   - Required invoice/credit-note fields and numbering rules: ____________________
   - Test/sandbox onboarding contact and target date: ____________________
   - **Recommended:** Evaluate the KRA solution against GOWNSEA's volume and integration needs. If using an approved third-party integration, confirm onboarding and test support. Test submissions, failures, retries, credit notes and reconciliation before production.

7. **Approve the tax/statutory setup:**
   - Accountant name/title: ____________________
   - Approval date: ____________________
   - Supporting tax advice or iTax profile reference: ____________________

## 3. Historical invoice and opening-balance cutover

The backfill tool can preview eligible hires and completed custom orders, but the local development database is not a valid source for this review. A current, authorized database snapshot is needed.

1. **Who will provide the current source database snapshot and when?**
   - Data owner/contact: ____________________
   - Snapshot date/version: ____________________
   - Secure transfer location/process: ____________________
   - **Recommended owner:** GOWNSEA's designated data owner with the development team. Record the snapshot date, schema version, checksum/backup identifier and secure transfer record.

2. **Where should the isolated finance review take place, and who may access it?**
   - Environment/location: ____________________
   - Authorized reviewers: ____________________
   - **Recommended:** Restricted staging/finance-review environment; Finance Manager, appointed accountant and designated technical lead only. Log access to customer-level records.

3. **Which invoice date treatment is approved?**
   - [ ] Original hire handover/custom-order completion date
   - [ ] Approved cutover/opening-balance date (recommended starting position)
   - [ ] Other: ____________________
   - Cutover date, if applicable: ____________________
   - Are any historical periods approved for restatement? ____________________
   - **Recommended starting position:** Use an approved cutover/opening-balance date while preserving original handover/completion dates for history. Do not automatically post to closed periods; accountant decides whether correction or restatement is needed.

4. **Who will reconcile each candidate against source records?** Confirm review includes customer, branch, hire/order date, quantities/rates, receipts, refunds, deposit applications and remaining balances.
   - Finance reviewer(s): ____________________
   - Reconciliation schedule/reference: ____________________
   - **Recommended:** Finance leads; branch managers verify operational source records; development supplies the dry-run report.

5. **What is the process for exceptions** such as missing source documents, cancellations, overpayments, duplicate records or closed-period entries?
   - **Recommended:** Maintain a controlled exception register with classification, owner, evidence and resolution/exclusion approval. Do not silently alter amounts to force reconciliation.
   - Approved owner/process: ____________________

6. **Who approves the final candidate population and opening receivables control total?**
   - Finance approver/name/title: ____________________
   - Approval date: ____________________
   - Hire count and total: ____________________
   - Custom-order count and total: ____________________
   - Opening receivables control total/reference: ____________________
   - **Recommended:** Finance Manager or appointed accountant signs the final counts, invoice totals and receivables control total after review. Values must come from the reviewed database dry-run.

7. **Cutover recovery sign-off:**
   - Pre-cutover backup identifier: ____________________
   - Restore verification evidence/reference: ____________________
   - Staging reconciliation approver/date: ____________________
   - **Recommended:** Require a verified pre-cutover backup and successful staging restore. Technical lead signs restore readiness; Finance signs the financial reconciliation before production migration.

## 4. SMS and M-Pesa provider verification

Provider adapters are implemented, but credentials and live end-to-end delivery have not been verified.

1. **SMS provider:** Confirm the provider/account GOWNSEA will use (currently expected to be Onfon).
   - Provider/account name: ____________________
   - Technical/business contact: ____________________
   - Are the credentials previously stored in `Gownsea.pdf` revoked and replaced? [ ] Yes [ ] No
   - Rotation completion date/reference: ____________________
   - Delivery callback/status endpoint requirements and provider confirmation: ____________________
   - **Recommended:** Continue with Onfon after confirming the active account, pricing, sender ID, delivery reports and API functionality. Rotate credentials previously included in shared documentation.

2. **SMS sandbox test:**
   - Sandbox enabled/contact: ____________________
   - Approved test phone number(s), exchanged through a secure channel: ____________________
   - Consent to send test messages confirmed by recipient? [ ] Yes [ ] No
   - Expected sender ID and delivery-status behavior: ____________________
   - Test owner and target date: ____________________
   - **Recommended test cases:** successful delivery, invalid destination, provider error, retry, callback handling and duplicate delivery-status update. Test hire confirmation, return reminders, overdue notices, transfer notices and payment receipts with recipients who consent to testing.

3. **Daraja/M-Pesa:**
   - Safaricom business onboarding/status: ____________________
   - [ ] Sandbox first (recommended)  [ ] Production after onboarding  [ ] Not required
   - Shortcode/till/paybill type (do not provide secrets here): ____________________
   - Approved transaction types: ____________________
   - Approved sandbox/test phone(s), exchanged securely: ____________________
   - Callback URL/domain owner and HTTPS availability: ____________________
   - Reconciliation contact/process for timeouts or unmatched callbacks: ____________________
   - Test owner and target date: ____________________
   - **Recommended:** Use Safaricom Daraja sandbox first; move to production after merchant onboarding and acceptance. Test approved STK Push/C2B flows, idempotent callbacks, provider IDs, timeouts and unmatched payments. Do not treat client-side success as payment confirmation.

## 5. Staging, performance and device acceptance

The local test suite does not establish production-like database behavior, concurrent throughput, field-device camera support or Core Web Vitals.

1. **Representative operating scale:**
   - Expected serialized assets at launch / within 12 months: ____________________
   - Branches/warehouses at launch / within 12 months: ____________________
   - Typical and peak concurrent staff: ____________________
   - Largest expected bulk hire/transfer: ____________________
   - Expected daily transactions/scans: ____________________

   **Illustrative engineering test profile—not confirmed GOWNSEA volumes:** 50,000 serialized assets; 10 warehouse/branch locations; 50 concurrent staff; a 1,000-item largest bulk operation; 10,000 scans per day. Replace or approve these assumptions: ____________________

2. **Who can provide or approve representative, anonymized staging data?**
   - Owner/contact: ____________________
   - Data preparation/approval date: ____________________
   - **Recommended:** Operations approves representative anonymized data; development prepares/loads branches, institutions, gown categories, hires, partial returns, transfers, payments and exceptions.

3. **Performance acceptance:** The engineering brief proposes p95 targets of 300 ms for ordinary reads, 700 ms for standard writes, 250 ms for barcode lookup, 700 ms for scan confirmation and 400 ms for global search (excluding third-party calls where specified), plus dashboard LCP of 2.5 seconds on a representative device/network.
   - [ ] Approve these targets
   - [ ] Approve revised targets (describe): ____________________
   - Business-critical workflows to prioritize: ____________________
   - Acceptance approver: ____________________
   - **Recommended priority:** barcode scanning, issue/return, inventory transfers and payment confirmation. Measure provider response time separately from application latency.

4. **Supported field devices for camera and scanner validation:**
   - Phone/tablet models and operating systems: ____________________
   - Browser versions: ____________________
   - Barcode hardware/scanner models, if used: ____________________
   - Test location/contact and available test gowns/labels: ____________________
   - Acceptance owner/date: ____________________
   - **Recommended baseline:** current Android phones, current desktop browsers and GOWNSEA-approved USB/Bluetooth barcode scanners. Confirm exact models; test camera scanning in warehouse lighting, weak connectivity and with the labels used in the field.

## 6. Production operations and AWS preparation

The AWS/S3 templates and deployment scripts are prepared; AWS resources have not been provisioned. Do not place credentials in this questionnaire. Prefer temporary federated access and an EC2 instance role; use Systems Manager rather than distributing long-lived SSH keys where possible.

1. **AWS account and ownership:**
   - AWS account owner/team: ____________________
   - Preferred deployment region: ____________________
   - [ ] Approve `af-south-1` (Cape Town)  [ ] Use another region: ____________________
   - Person/team that will grant temporary deployment access: ____________________
   - Expected access window/contact method: ____________________
   - **Recommended:** GOWNSEA owns the AWS account; developers receive temporary least-privilege deployment access. Proposed region is `af-south-1` (Cape Town), subject to cost, latency and data-governance review. Prefer MFA, temporary federation, instance roles and Systems Manager over long-lived access keys or distributed SSH keys.

2. **Hosting choices and endpoints:**
   - EC2 instance/OS requirements or constraints: ____________________
   - Managed PostgreSQL host/version or provisioning owner: ____________________
   - Redis/cache host/version or provisioning owner: ____________________
   - Domain and DNS owner: ____________________
   - TLS certificate/DNS validation contact: ____________________
   - Production email provider and sender domain: ____________________

   **Recommended architecture:** EC2 on a supported Linux LTS; managed PostgreSQL on Amazon RDS; managed Redis-compatible cache/queue service if workload warrants it; private S3 for backups; client-controlled domain and DNS; automated HTTPS certificates; verified transactional email; automated staging-to-production release pipeline. Restrict database access to the application and approved administrative paths.

3. **Backup and recovery:** Current proposed defaults are daily backups, 90-day S3 retention, 24-hour RPO and 8-hour RTO.
   - [ ] Approve these defaults  [ ] Provide revised values: ____________________
   - Backup data owner and authorized restore approvers: ____________________
   - Isolated restore-test environment/contact: ____________________
   - Restore drill date/acceptance approver: ____________________
   - **Recommended:** Consider point-in-time database recovery and a shorter RPO if the business cannot accept losing up to 24 hours of stock/financial changes. Confirm retention and recovery targets with management.

4. **Production monitoring and support:**
   - On-call/incident contact and escalation method: ____________________
   - Who monitors failed jobs, scheduler, provider failures and backup recency? ____________________
   - Support hours and response expectations: ____________________
   - Data retention/privacy contact and required retention periods: ____________________
   - **Recommended:** Assign a named technical incident owner and GOWNSEA escalation contact; monitor errors, failed jobs, scheduler, provider failures, backup age, storage, database health and security events. A two-hour initial response for critical incidents is a proposal only and requires a support agreement. Obtain approved data-retention periods from GOWNSEA's privacy/compliance contact.

## 7. Consolidated approvals

| Area | Approver name/title | Decision/reference | Date | Follow-up owner/date |
| --- | --- | --- | --- | --- |
| Late-fee accounting | | | | |
| Tax/statutory/eTIMS | | | | |
| Historical invoice cutover | | | | |
| SMS/Daraja provider tests | | | | |
| Performance/device acceptance | | | | |
| Backup/recovery targets | | | | |
| Production operations | | | | |

**Client representative:** ____________________  
**Role:** ____________________  
**Date completed:** ____________________

## 8. Development-team configuration recommendations

These are implementation recommendations, not additional client decisions unless noted above.

- Keep company/legal identity centralized for invoices, credit notes, statements and receipts.
- Organize Settings into Finance & Accounting (accounts, periods and recognition), Penalty Policies (rates, caps and grace periods), Tax & eTIMS (tax codes, invoices and credits), Payments (M-Pesa, receipts and refunds), Notifications (SMS, email and templates), Inventory Operations (branches, scanning and transfers), Security & Approvals (roles, thresholds and audit), and Infrastructure (monitoring, backups and integrations).
- Require appropriate authorization and audit history for financial configuration changes. Give financial settings effective dates and preserve the policy snapshot linked to each hire/invoice; never silently alter posted transactions.
- Keep unresolved legal identifiers, actual operating volumes, provider account details, approver names and migration totals pending until GOWNSEA supplies or verifies them.
