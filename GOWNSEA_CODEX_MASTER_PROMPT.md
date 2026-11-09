# GOWNSEA — MASTER CODEX ENGINEERING BRIEF

> ROLE: You are the principal software architect, senior Laravel engineer, senior product designer, QA lead, DevOps engineer and security reviewer responsible for shipping a production-ready GOWNSEA Operations Platform. Implement it, do not merely propose it. Never represent unfinished work as complete.

## 0. NON-NEGOTIABLE INPUTS AND DESIGN REFERENCE

1. Read this entire document before coding. Inspect the repository, existing application, `.env.example`, composer/package dependencies, tests and git status. Preserve existing work. If no app exists, bootstrap a new Laravel application.
2. The **approved UI reference** is `design-reference/gownsea-approved-dashboard.png` (copy the supplied dashboard screenshot to this path). **Match this screenshot as closely as technically possible** at its reference desktop viewport: layout proportions, black/charcoal sidebar, red active navigation, GOWNSEA wordmark treatment, pale/off-white content background, header search, notification and profile controls, hero with graduation imagery, greeting and quote, four KPI cards, red Issue Gowns button, gold Scan Return button, revenue chart, return-status donut, today's priorities, recent transactions and upcoming events. Use screenshot comparison at the reference viewport and iterate. Treat screenshot text, dates, customer names and numeric values as illustrative, not production data.
3. Site/brand reference: `https://gownsea.com/`; brand red `#D42127`, charcoal `#17191D`, canvas `#F7F7F5`, white `#FFFFFF`, optional restrained warm gold `#D7B878`. Do not scrape copyrighted brand assets indiscriminately; use authorized logo, product and graduation photos supplied in project assets. If assets are missing, create clean placeholders and clearly list assets required; do not substitute fake logos.
4. The product must have **all features in this brief**, fully wired end-to-end. Never build static mockups as the finished implementation. No nonfunctional links, fake API integrations, or seeded demo figures on production dashboards.
5. **Multi-location operation is mandatory from day one:** GOWNSEA operates a central warehouse and multiple branches. Implement real warehouse-to-branch, branch-to-branch, and branch-to-warehouse transfers, location-scoped stock, staff custody, customer custody and complete gown traceability; this is NOT a future-ready placeholder.
6. Use Kenyan business context: KES, `Africa/Nairobi`, E.164 phone normalization for Kenyan numbers, institution-based hiring, local SMS and optional Safaricom Daraja M-Pesa. Do not assume live credentials exist.

## 1. PRODUCT PURPOSE

Build a unified **central warehouse + multiple branches** GOWNSEA inventory, gown hiring, sales, CRM, custom-order, production, delivery, communications and finance management system. Reduce manual entry, gown losses, double allocation, late returns, missed follow-ups, missed order deadlines and financial inaccuracies. All authorized staff must see a consistent, responsive, fast interface with accurate, traceable records.

## 2. MANDATORY TECH STACK AND ARCHITECTURE

**Frontend decision:** Use **Vue 3 + TypeScript + Inertia.js + Laravel** as the default and implement it consistently across the entire application. **React 19 + TypeScript + Inertia.js + Laravel** is an acceptable alternative **only if the existing repository already uses React or there is a compelling documented compatibility reason**. Inspect the repository first; never mix Vue and React, never silently revert to Blade/Alpine for the primary app, and do not create two parallel frontends. Laravel remains the authoritative backend for all business rules. Use Tailwind CSS, Vite, accessible headless components, Lucide icons and a maintained charting library. Use typed Inertia page props and shared components; choose Pinia (Vue) or a lightweight React store only for genuinely shared client state. Prefer server state from Laravel/Inertia; avoid duplicated client caches.

- Backend: supported stable Laravel/PHP, PostgreSQL, Redis for queues/cache/rate limiting, Laravel scheduler and Horizon when supported. Use Laravel policies, Form Requests, jobs, notifications, events, actions/services, DTOs and resource serialization as appropriate. Laravel Sanctum for separate authenticated API/PWA endpoints if needed; Inertia web authentication must be secure and session-based.
- Architecture: **modular monolith** with bounded contexts Identity & Access, Locations & Transfers, Catalog & Inventory, Hiring & Returns, Sales & Payments, CRM, Custom Orders & Production, Logistics, Messaging, Finance & Reporting, Audit & Administration. Thin controllers, explicit domain transitions and transactional application services. Avoid unnecessary microservices and abstraction.
- All physical asset movements and financial postings are authoritative server-side operations with immutable audit/ledger history; maintain indexed derived balances and reconciliation jobs. Implement pessimistic row locking or equivalent atomic constraints and idempotency keys on issue, return, transfer, payments and webhooks.
- Laravel migrations, factories, realistic demo seeders restricted to development, policy tests, feature tests, browser tests, typed frontend checks, API documentation, `.env.example`, CI and deployment documentation are mandatory.
- Responsive installable PWA: secure camera barcode/QR scanning with manual entry fallback, service worker for static assets only, explicit online/offline indicators; never pretend a stock or money mutation succeeded offline. Document browser hardware/scanner compatibility.
- Kenyan context: KES, `Africa/Nairobi`, Kenyan phone number normalization, configurable SMS provider, optional Safaricom Daraja integration. No live credentials are assumed.

### 2.1 Speed, responsiveness and execution requirements — release gates

Build for **fast perceived and actual execution**, including warehouses with many thousands of serialized gowns, busy branches and simultaneous bulk institutional hires. These are engineering acceptance targets under a documented baseline environment, not unmeasured promises:

1. Target p95 backend response **<= 300 ms** for ordinary indexed reads and **<= 700 ms** for standard writes (excluding third-party calls) under a realistic staging load; justify and document any exception. Target p95 barcode lookup **<= 250 ms**, p95 scan confirmation **<= 700 ms**, and p95 global search **<= 400 ms** with appropriate indexed queries. Define load profiles and measure actual results.
2. Initial desktop dashboard: aim for LCP **<= 2.5 s** on a representative modern device/network; minimize JavaScript, code-split routes, lazy-load heavy charts, use Vite optimized production assets, cache safe reference data, and prevent layout shifts. Target Core Web Vitals with measured Lighthouse/browser results.
3. **Never block a user-facing command** on SMS, PDF generation, email, heavy report compilation or third-party API calls. Commit the primary transaction, enqueue outbox-backed asynchronous effects, immediately return truthful status, and show eventual delivery/payment reconciliation state.
4. Use server-side pagination, indexed filters and sorting, debounced global search, virtualization only for genuinely long UI lists, lazy images, efficient thumbnails, database EXPLAIN plans and N+1 prevention. Do not load the entire inventory into the browser.
5. For large warehouse/branch transfers and 200+ gown hires, implement bulk scan/import workflows, chunking, set-based queries where safe, bounded transactions, progress states and partial-error recovery. Prevent duplicate scans and stale-state races.
6. Use optimistic UI only when safe; never show irreversible stock, custody or financial operations as successful until the server confirms. Keyboard-first scanning, autofocus, audible/visual success and error feedback, and minimal taps are essential.
7. Add query profiling, slow-query logging (redacted), queue latency monitoring, DB connection/pool checks, Redis cache invalidation rules, indexes for `(location_id, status)`, serialized code, academic level, institution, transaction dates and lookup fields, and targeted materialized/cached dashboard aggregates when needed.
8. Run load tests for concurrent issuing, receiving, scanning, 200-item bookings, branch transfers and dashboards. Record measured p50/p95/p99 latency, throughput, failure rate and environment in `docs/PERFORMANCE.md`. Fix bottlenecks before claiming the performance gates pass.

## 3. DESIGN SYSTEM — REPRODUCE THE APPROVED IMAGE

### 3.1 Desktop dashboard

At the reference desktop viewport, reproduce the supplied image composition:
- Left fixed/collapsible sidebar approximately 17% width with dark charcoal-to-black surface; prominent GOWNSEA branding, small graduation wear subtitle, red active Dashboard pill; vertical icons/labels: Dashboard, Inventory, Hiring & Returns, Customers & CRM, Custom Orders, Sales & Payments, Finance, Reports, Settings. Include SMS/Notifications, Production/Delivery and Staff/Audit access logically via submenu or secondary navigation without compromising screenshot composition. Elegant graduation-gown photo/quote near bottom when authorized assets exist.
- White header with menu collapse, wide global search placeholder `Search gowns, customers, orders, or scan barcode...`, notifications bell with unread count, profile avatar, name, role and dropdown.
- Spacious canvas; date eyebrow; large greeting with GOWNSEA highlighted in red; subtitle; graduation image montage and right-aligned premium italic quotation. Do not hardcode screenshot date.
- KPI row: Total Inventory, Currently Hired, Due Today, Overdue. Cards white, soft border/shadow, rounded corners, light tinted icons, prominent numeric counts, small contextual footers, clickable drill-downs. Define metrics unambiguously.
- Two stacked, prominent CTA buttons at right: red `Issue Gowns` and warm-gold `Scan Return` with appropriate icons, arrows and real workflows.
- Second row: `Revenue Overview` with filter, actual chart and revenue/profit distinction; `Return Status` donut with accurate non-overlapping categories; `Today's Priorities` actionable queue with overdue returns, due today, due custom orders, pending SMS.
- Third row: `Recent Transactions` compact sortable/filterable table with date, customer, institution, items, due date, amount, status and actions; `Upcoming Events` list of graduation events, dates and expected quantities.
- Use image as visual source of truth for alignment, density, typographic scale, border radii, color usage and hierarchy. Keep operational text readable and AA-level contrast. Never use color as the only status indicator.

### 3.2 Shared design language

- Brand red `#D42127` only for selected navigation, primary CTAs and meaningful accents; charcoal navigation, white cards, near-white background, gold for secondary premium accents. Status semantics: green success, amber warning, red overdue/error, neutral pending, with text labels/icons.
- Use Inter or similarly readable UI font for data; restrained editorial/serif typography only for brand statements/hero. Consistent spacing, responsive grids, subtle elevation, focus rings, skeletons, helpful empty states, clear error messages, toasts and confirmation dialogs.
- Reward user actions with tasteful 120–250ms transitions and confirmations, but respect reduced-motion settings. Do not animate financial totals misleadingly.
- Tables: sticky headers where useful, search, filters, sorting, pagination, column visibility where valuable, bulk actions with confirmations, export, and links to record details. Ensure large datasets remain performant.
- Forms: keyboard friendly, inline validation, progress indication for multi-step flows, sensible defaults, autosave drafts only where safe, prevention of duplicate submission, contextual help, confirmation for destructive operations.
- Detail pages: summary header, contextual actions, tabs (overview/history/payments/notes/documents/audit as applicable), right-side drawer for quick view.
- Product catalog supports both image-card and dense table modes, maintaining filters; detail displays barcode, category, size, institution, condition, current location, availability and full movement timeline.
- Build reusable primitives: app shell, sidebar, topbar, breadcrumbs, stat card, chart card, status chip, data table, filter bar, detail drawer, timeline, form field, modal, toast, empty state, loading skeleton, barcode scanner overlay, print layout.

### 3.3 Responsive/PWA

- Desktop fidelity to screenshot; tablet collapsible sidebar; mobile bottom navigation for key workflows and/or accessible drawer, sticky actions, full-width forms, swipe-safe layouts and no horizontal overflow.
- Mobile storekeeper landing actions: `Scan Return`, `Issue Gown`, `Search Inventory`, `Today's Returns`. Camera barcode scan with permission handling, flashlight when supported, manual entry fallback, scanner hardware/keyboard wedge support, duplicate-scan feedback, accessible status feedback and retry handling.
- Support install prompt when browser permits; handle poor connectivity and loading/failed states gracefully. No promises of offline completion for sensitive transactions.
- Design screens for Dashboard, Inventory, Item Detail, Issue Gowns Wizard, Return Scanner, Hiring Detail, CRM Pipeline, Lead Detail, Customers, Quotations, Sales/Payments, Custom Orders Board, Order Detail, Production, Deliveries, Finance, Reports, Notifications, Staff/Roles, Settings and Audit Logs.

## 4. AUTHENTICATION, STAFF AND SECURITY

Roles: Super Admin, Management, Storekeeper, Sales/CRM, Finance, Production, and other configurable authorized employees. Implement fine-grained permissions by action and resource; roles are defaults, not sole security boundary. Enforce on every backend endpoint, query and export, including branch/location scopes.

Secure login, password reset, optional 2FA for privileged users, session revocation, CSRF, XSS protections, server-side validation, secure file uploads, throttling, encrypted sensitive fields where warranted, secret management and audit logging. Track actor, timestamp, IP/device/session metadata where appropriate, before/after values (redact secrets), reason and linked transaction for sensitive changes: stock adjustments, due dates, waivers, payments, refunds, custom order revisions, tracking numbers, deletion/cancellation, role changes. Prefer void/reversal over destructive deletion for financial and stock records. Backup, retention, restore testing and data privacy controls must be documented. Avoid exposing customer data in logs.

## 5. MASTER DATA, INVENTORY AND STOCK MOVEMENTS

- **Mandatory academic gown classification:** College/TVET, Certificate, Diploma, Bachelors (Bachelor’s Degree), Masters (Master’s Degree), and PhD (Doctoral). These six levels must be seeded as configurable, stable reference data with canonical codes (`COLLEGE_TVET`, `CERTIFICATE`, `DIPLOMA`, `BACHELORS`, `MASTERS`, `PHD`) and user-friendly labels. College/TVET denotes an institution/programme category that may overlap award levels; therefore preserve it as a requested business classification while also modeling institution type and qualification/award level separately so filtering does not lose meaning. Allow an asset/product variant to record both business classification and actual qualification level where applicable. Never force a false one-to-one mapping.
- Product categories: gowns, caps, hoods, stoles, tassels and configurable graduation accessories/other items. Fields: product name, SKU, type, size, color, institution, academic level where relevant, description, photos, quantity, purchase/manufacturing cost, selling price, hire rate, reorder threshold, condition and active status.
- Distinguish **serialized physical assets** (each with unique ID/barcode/QR, lifecycle, history) from **bulk fungible stock** (SKU, location, quantity, movements). Never conflate product SKU with physical unit ID. Generate printable labels, single/batch labels, scan/search identifiers; support large batches.
- Locations/warehouses and inventory status: available, reserved, issued/hired, returned pending inspection, cleaning, under repair, damaged, missing, sold, written off; status model must be mutually consistent. Track staff custody/employee issue and return as well as customer rentals.
- All receipts, procurement/stock-in, reservation, allocation, issuance, return, inspection, repair, transfer, sale, adjustment and write-off create auditable movement entries. Stock on hand/available/reserved/issued must reconcile; avoid negative stock unless a strictly controlled exception is explicitly authorized.
- Low-stock and discrepancy alerts, cycle counts, reconciliation, adjustment approval, movement history and inventory loss/damage/repair reports.
- Prevent double-issue and overlapping reservation for the same serialized item with database-level locking/constraints and transaction boundaries. For bulk SKU, atomically reserve/decrement available quantities with row locks and concurrent test coverage.

## 5A. MANDATORY MULTI-LOCATION WAREHOUSE, BRANCH AND CUSTODY ARCHITECTURE

**Business reality:** GOWNSEA operates a central warehouse and multiple retail/service branches. Every serialized gown must be traceable at any moment: in warehouse storage, at a branch, reserved, with a staff member, in transit between locations, hired to a customer/institution, returned awaiting inspection, in cleaning/repair, missing, sold or written off. This is a first-release core feature, not an optional enhancement.

### 5A.1 Locations and organizational hierarchy
- Model one GOWNSEA company with a configurable central warehouse and any number of branches; support optional sublocations (storage rooms, shelves, racks, dispatch/receiving areas, cleaning/repair zones). Each location has stable ID/code, type, address, active status, manager, contact and timezone where relevant.
- Allow authorized management to create, edit, deactivate and configure branches and warehouses. Prevent deletion of locations with historical movements. A user may be assigned to one or multiple locations; central management can see consolidated data.
- Every inventory quantity, physical asset, reservation, issue, return, stocktake, movement, and transfer must have explicit source/destination or custody attribution as appropriate. Never rely on a single global `quantity` as the operational truth.

### 5A.2 Exact gown identity, position and custody
- Each individually tracked gown has a permanent unique asset ID/barcode/QR and product/variant association. Store **last confirmed physical location**, current custody holder/type, lifecycle status, last scan/verification timestamp, and immutable chronological event history. Distinguish physical location from administrative ownership, intended destination and financial responsibility.
- Define custody types such as warehouse, branch, employee, customer, institution, courier/carrier, repair vendor and unknown. For customer/institution custody, link to active hire and due date; for staff custody link to authorized handover; for transit link to a transfer/shipment.
- Provide a global **Find a Gown** search by barcode, asset ID, SKU, size, color, institution, customer, hire reference, branch and warehouse. Result shows exact known location/custodian, status, originating branch, transfer/hire reference, last verified time and full movement timeline. Never label a planned destination as confirmed physical location.
- Support optional shelf/rack/bin location at warehouse/branch level; scanning at handover or stocktake refreshes verification, not historical identity. Missing or stale location must be flagged as `unverified`/`missing`, never silently assumed.

### 5A.3 Transfers and chain of custody
- Implement transfers: warehouse -> branch, branch -> warehouse, branch -> branch, and authorized warehouse/branch sublocation moves. Workflow: draft request -> approval where configured -> picking/reservation -> scan/pack -> dispatch/handover -> in transit -> destination scan/count -> receipt/inspection -> completed; support rejected, cancelled, partial receipt and discrepancy states.
- Each transfer has unique reference, source, destination, requester, approver, picker, dispatcher, carrier/driver, receiver, timestamps, expected/received item IDs or bulk quantities, notes, attachments and optional waybill. Capture who physically handed over and accepted items.
- On dispatch, assets must **leave available stock at origin** and enter **in-transit custody**; they must **not become available at destination** until receipt is confirmed. Partial receipts leave outstanding assets in transit or exception state. Branch staff cannot issue unreceived items.
- For serialized items, scan and verify every item or controlled batch before dispatch and upon receipt. Detect wrong-branch receipts, duplicate scans, missing items, unexpected items, damaged items and conflicting allocations. Provide discrepancy resolution with supervisor approval and audit trail.
- For bulk non-serialized stock, use atomic quantity transfer with source reservation and destination receipt; record shortages/overages and adjustments separately. No negative balances or double allocation. Transfers must not be counted as sales, income or expenses merely because custody changed.
- Prevent transfer of assets actively hired out, sold, written off or otherwise unavailable unless a dedicated authorized recovery/correction workflow is used. Cross-branch customer returns are supported as an explicit configurable policy: accept at another branch, record actual receiving location and originating hire/branch, settle charges correctly, and optionally create a return-to-origin transfer.

### 5A.4 Branch operations and allocation
- Support warehouse replenishment requests from branches, approval, fulfillment, minimum/target stock thresholds, reorder alerts, branch stock allocations, stock aging and suggested replenishment based on demand.
- Hiring, booking, pickup and returns are branch-aware. Reservation availability is computed by physical location and dates; allow cross-branch fulfillment only through explicit transfer/reservation policy. A branch must never promise or issue stock physically elsewhere without a verified workflow.
- Branch-to-customer and employee-to-employee handovers create immutable custody events; bulk university dispatches track assigned branch/warehouse, recipient institution and accountable staff. For hired assets, customer custody remains linked to hire until scanned return or approved loss resolution.
- Sales, expenses, invoices, payments, deposits, penalties, custom orders, production, delivery and staff activities should be attributable to an originating/fulfilling branch when applicable, while central management can see company-wide consolidated reporting. Cross-branch fulfillment must not double-count revenue or stock.

### 5A.5 UI and reporting — preserve approved dashboard styling
- Preserve the screenshot-faithful dark sidebar, red accents, gold Scan Return action, charts and cards. Add a **location selector** (All Locations for permitted management; assigned locations for branch staff) to dashboard and relevant pages without disrupting the approved visual hierarchy.
- Add **Locations & Transfers** navigation with Warehouse Overview, Branch Inventory, Stock Transfer Requests, Dispatch, In Transit, Receiving, Stocktake, Discrepancies and Asset Traceability. Design scanning-first mobile/PWA interfaces for dispatch/receiving, with clear item counts, progress and discrepancy actions.
- Dashboard filters and KPIs must show stock by location, warehouse stock, branch stock, items in transit, gowns currently with customers, due/overdue by branch, low-stock branches, pending transfer approvals and unresolved discrepancies. Provide drill-down to actual records. Never sum the same physical asset into multiple location totals.
- Reports: consolidated and per-location stock position, serial asset whereabouts, transfer aging, transfer history, in-transit assets, custody/handover audit, warehouse-to-branch distribution, branch replenishment, discrepancy/loss, stocktake variance, branch hiring/returns, branch sales/revenue and branch profitability. Include location filters and Excel/PDF export. Preserve all original eleven reports; extend rather than replace them.

### 5A.6 Data design, concurrency and security
- Add normalized entities as appropriate: `locations`, `location_zones`, `user_location_assignments`, `asset_custody_events`, `stock_transfers`, `stock_transfer_lines`, `stock_transfer_asset_scans`, `transfer_discrepancies`, `stocktake_sessions`, `stocktake_lines`, `replenishment_requests` and `location_stock_balances` (projections). Keep the existing `physical_assets` and append-only `stock_movements` model as the authoritative movement history.
- Enforce single current custody per serialized asset, one open transfer allocation per asset, transactional transitions, row-level locks, idempotent scan/dispatch/receipt commands and unique event references. Never let an asset be simultaneously available at warehouse and branch or hired and in transit. Reconciliation jobs compare physical-asset state, custody ledger, stock movements and projections; flag and resolve discrepancies without silently rewriting history.
- Authorize by **role AND location scope** on controllers, services, search, dashboards, reports, APIs and exports. Only permitted managers may approve transfers, write-offs, discrepancy resolutions and cross-location corrections. Audit actor, device, timestamp, source/destination, reason, evidence and before/after state.

### 5A.7 Required multi-location acceptance tests
1. Warehouse has 500 available gowns; dispatch 100 to Branch A -> warehouse available decreases by 100, 100 become in transit, Branch A available remains unchanged until verified receipt.
2. Branch A receives 97 of 100 -> exactly 97 become available there, 3 remain unresolved/in transit or discrepancy; no phantom stock.
3. Transfer 10 gowns Branch A -> Branch B -> all 10 have continuous scan/custody histories; Branch B cannot hire them before receipt.
4. Scan any gown at any point -> show last confirmed location or current named customer/staff/courier custodian, linked transaction and full timeline.
5. Hire gown at Branch B -> item is in customer custody and unavailable company-wide; returning at Branch A follows configured cross-branch policy and preserves original hire and actual return location.
6. Two staff members concurrently attempt transfer/hire/receive same asset -> only one valid state transition commits; retries are idempotent.
7. A Branch A user cannot see or alter restricted Branch B data; central authorized management can see consolidated records.
8. Company-wide total assets = location-held assets + in-transit + customer/staff/external custody + exception/disposition categories under a clearly documented mutually exclusive reconciliation model; no double counting.
9. Branch and consolidated financial/stock reports reconcile; internal transfers never generate fictitious sales revenue.
10. Warehouse/branch stocktakes record scanned items, shortages, extras and supervisor-approved adjustments while retaining original history.

### 5B. Academic gown taxonomy, variants and cross-location availability

- Admins can configure the six business-facing classifications without changing code; seed all six with stable machine-readable identifiers, sorting order, active state and display labels. Support optional additional levels in future without losing historic assignments. Do not hardcode classification labels in frontend conditionals.
- Product variant identity should account for gown type, academic classification, institution, faculty/school where relevant, size, color, trim/hood/stole specification and rental/sale price. A barcode identifies **one physical asset**, not merely a class of gowns. Bulk accessories may use lot/quantity tracking.
- Provide classification filters in inventory catalogue, global search, issue/return screens, branch stock views, warehouse transfers, procurement, quotes, rentals, custom orders and reports. Allow combined filters: academic level + institution + size + location + condition + availability + due date.
- Display consolidated availability and **per-location** availability for each classification/variant. Branch replenishment recommendations must consider upcoming institutional graduation events, reservations, expected returns and stock in transit, without double counting.
- Preserve historical classification snapshots on transactional line items when catalog metadata changes; prevent deleting a classification referenced by active assets or history. Enforce referential integrity and write migration/factory/test coverage.
- Required acceptance tests: each of six classifications is selectable; search/filters and bulk booking use correct variant; a PhD gown at Branch A cannot be issued from Branch B without a completed transfer; classification-level reports aggregate accurately across warehouse and branches; college/TVET and diploma can coexist as institution type versus award level.

## 6. GOWN HIRING, BOOKING, ISSUANCE AND RETURNS

- Hire customer (individual or institution), name, normalized phone, email, institution, graduation/event date, line items and quantities, unit hire charges, refundable security deposit, balances, booking date, issue date/time, expected return date/time, pickup location, notes and assigned staff. Support individual and bulk institutional hires (e.g. 200 gowns) without 200 separate manual transactions.
- Lifecycle: draft/quote, reserved, awaiting payment (if required), ready, issued/partially issued, partially returned, returned pending inspection, completed, overdue, cancelled/disputed. Keep payment and physical status independent. Availability and dates must be checked when confirming issue.
- Issue wizard: find/select customer, select/scan items or bulk quantities, validate availability and overlaps, calculate charges/deposit, record or request payment, confirm handover, generate numbered invoice/hiring receipt/agreement and enqueue confirmation SMS. Support partial payment, multiple payment methods, partial issuance and staff custody.
- Return wizard: scan every serialized gown barcode/ID; automatically resolve correct active hire and customer, reject mismatches/double scans, show due date, elapsed overdue days, applicable penalty, prior payments, unreturned items and expected quantities; allow review/confirmation, partial returns, damage/missing/repair classification and photos/notes, customer balance update and return receipt. For bulk stock, provide controlled counted return with discrepancy record. Return timestamp from server; store timezone-aware timestamps.
- Returned items may require inspection/cleaning before being `available`; do not automatically make damaged or uninspected items rentable. Provide explicit configurable auto-available policy for clean confirmed returns.
- Overdue detection, due today/upcoming and still pending per customer/institution. Penalty rules configurable by product/institution/contract where authorized; define grace period, rate, per-day basis, cap, timezone and whether return day counts. Outstanding penalty accrues on a daily read/calculation or controlled accrual job until returned or approved adjustment; freeze final assessed amount at return and preserve calculation basis. Handle partial returns at item level. Waivers/adjustments require permission, reason and audit.
- Record deposits held, charges, payments, refunds, penalties assessed/waived/paid and customer balance separately. Refundable deposits are liabilities until applied/returned, not revenue. Return settlement can allocate deposit against authorized charges and refund remainder with auditable entries.
- Prevent concurrent issue/return double processing using transactions, locks, idempotency keys and tests. Generate print-friendly receipts and downloadable PDFs.

## 7. SMS AND CUSTOMER COMMUNICATIONS

- Provider abstraction for Kenyan SMS gateway; templates editable by authorized staff, placeholders validated and previewable, consent/opt-out policy where applicable, delivery log and provider status callbacks if supported.
- Automated event messages: hire issued (items/due date/return instructions), configurable reminders before due, overdue alerts, penalties/balances, successful return/settlement, custom order confirmation, ready for collection, dispatched, delivered and delayed. Manual individual/group messages with preview, access control and safeguards.
- Configurable reminder schedule, throttling, suppression for settled/returned items, no duplicate sends, queues with retries/backoff, provider cost logging, failure visibility, and audit of template edits. Never send external SMS within an uncommitted database transaction; use after-commit dispatch.
- In development/test, use fake adapters; in production, require configured credentials and explicit provider verification. Show queued/sent/delivered/failed accurately, not assumed delivery.

## 8. CRM, INSTITUTIONS, SALES LEADS AND QUOTATIONS

- Customer and institution profiles (universities, colleges, TVETs, schools, procurement officers, registrars, individual graduands), contacts, phone, email, location, institution, interests, source (referral, website, social, tender, direct, other), history, notes and attachments.
- Leads pipeline: New Lead, Contacted, Quotation Sent, Negotiation, Won, Lost. Kanban + table, lead owner, estimated value, probability if configured, conversations, tasks, follow-up date, next action, staff reminders, conversion analytics and lost reason.
- Quotations: numbered, line items, taxes if configured, validity, revisions, approval, print/PDF, accepted/declined; convert won lead to linked customer and hire/sale/custom order without re-entering customer data. Do not duplicate customer profiles; implement merge safeguards.
- Track pipeline value, won/lost value, conversion rates, institutional sales, pending follow-ups and customer hiring/purchase history.

## 9. CUSTOM ORDERS, PRODUCTION AND DELIVERY

- Unique custom order number; customer/institution, contact, request, item type, size, color, quantity, detailed measurements, design specs, attachments/reference photos, special requirements, order date, agreed price, deposit, balance, payment status, assigned production owner, expected completion date and priority.
- Statuses: New, Quoted, Deposit Paid, In Production, Ready for Collection, Dispatched, Delivered, Completed, Cancelled, Overdue. **Overdue is a computed condition, not a destructive replacement for lifecycle status**; deposit/payment is a separate dimension. Display both clearly.
- Workflow: inquiry -> quotation -> approval/deposit -> production -> fittings/alterations/quality control -> ready -> collection or dispatch -> delivery confirmation -> completion. Allow legitimate skipped/alternate transitions only with authorization and audit.
- Production notes, milestones, fitting appointments, alterations, materials/labor/other direct costs, quality checks, approval records, communications and due-date alerts. Record specification, price and date revisions with before/after values, authorizer, reason and customer confirmation when relevant.
- Courier/provider, tracking number, dispatch date, delivery address, delivery status, delivery confirmation and date delivered. Support collection in person. Customer SMS on confirmation, readiness, dispatch, delivery or delay.
- Active/completed/cancelled/due/overdue views, production board, timeline and profit per custom order. Make sure revenue, direct costs, deposits, payments and balances feed finance correctly.

## 10. SALES, PAYMENTS, DEPOSITS AND FINANCE

- Sales of gowns/accessories and services; numbered invoices, line items, discounts with permissions, tax configuration, partial payments, receipts, refunds/credit notes, outstanding balances and payment allocations. Selling serialized/bulk stock creates correct stock movement and COGS.
- Payment methods: cash, bank transfer, M-Pesa and other configurable types. Implement optional Daraja STK/C2B integration behind adapters and feature flags; verify callbacks, store raw references safely, reconcile amounts, guard against duplicate/replayed callbacks and mismatched customer/order, and never mark a payment paid from client-side success alone.
- Track revenue from hiring, sales, custom orders and other services; cost of purchased/manufactured goods, direct production costs and operational expenses (transport, labor, repairs, SMS, delivery, other). Expense entry with category, vendor, receipt attachment, date, approver and allocation to business activity where appropriate.
- Implement a clear documented **accrual/cash recognition policy**: invoices/charges vs receipts vs recognized revenue vs cash flow vs refundable deposit liability. Penalties assessed are receivables, not collected cash; do not classify unpaid penalties as collected revenue. Refundable deposits are not revenue until validly applied to earned charges. Track gross profit, operating expenses and net profit accurately; hiring asset purchase should not automatically be expensed as COGS on each rental. If depreciation is not in scope, show transparent management-profit assumptions and avoid false accounting precision.
- Financial ledger with traceable source references, balanced allocations and immutable reversals; currency KES, decimal-safe arithmetic (integer minor units or exact decimals, never binary floating point for money). Reconciliation tools for unpaid invoices, customer balances, deposit liabilities, refunds, penalties assessed/collected and payment exceptions.
- Reports: daily/weekly/monthly/annual; revenue/cost/gross profit/operating expense/net profit; period comparisons; by product, customer, institution, custom order and business activity where supportable. Exports Excel and PDF, with authorization and safe handling for large jobs.

## 11. MANAGEMENT DASHBOARD AND REPORTS

Dashboard must show real, clickable/drill-down KPIs: total inventory available; currently hired; due today; overdue items and penalties; damaged/missing/under repair; total sales/hiring/custom-order revenue; outstanding customer payments; expenses and estimated profit; active leads and follow-ups; active/due/overdue/completed custom orders; upcoming graduation events; expected returns and production due dates. Chart time filters must change the actual data query. Define denominator and categories so donut segments never double count; e.g. returned, issued-not-due, due-today, overdue, inspection, damaged, missing as mutually exclusive snapshot buckets.

Required reports (all filters, drill-down, authorized Excel/PDF exports):
1. Available inventory and stock movement.
2. Gowns hired out and pending returns.
3. Overdue gowns and penalties.
4. Customer hiring history.
5. Sales and revenue.
6. Payments and outstanding balances.
7. Expenses and profit & loss.
8. Inventory loss, damage and repair.
9. Leads, quotations and conversion.
10. Custom orders: statuses, due dates, production, tracking, payments and balances.
11. Staff activity and transaction history.

## 12. GRADUATION EVENTS AND OPERATIONAL CALENDAR

Record institution, event name/date/location, expected gown quantity, related leads/quotes/bookings, delivery/collection dates, notes and assigned owner. Show upcoming events on dashboard, calendar and relevant institution records; enable planning without double-booking physical stock.

## 13. ADMIN SETTINGS AND OPERATIONS

Configure company identity, logo, locations, numbering prefixes, product categories, sizes/colors/institutions, pricing, deposits, penalty rules, grace periods, return policy, notification templates and schedules, payment methods, integrations, user permissions, business hours/timezone, tax settings, reporting definitions and backup retention. Every impactful setting change is authorized and audited. Provide system health page for queues, scheduler, SMS errors, integrations, backup recency and reconciliation exceptions.

## 14. DATABASE AND INTEGRITY PLAN

Design migrations and relationships before screens. At minimum evaluate entities: users, roles/permissions, sessions, locations, institutions, customers/contacts, leads, lead_activities/tasks, quotations/lines, graduation_events, products/variants, physical_assets, stock_lots, stock_balances/projections, stock_movements, reservations, hires/lines, hire_asset_allocations, returns/lines, inspections, damage_incidents, penalties/rules/adjustments, invoices/lines, payments/allocations, refunds, deposit_liabilities, financial_entries, expenses, custom_orders/lines/specifications/revisions, production_tasks/fittings, shipments/tracking_events, sms_templates, notification_jobs/logs, attachments, audit_logs, settings and idempotency_keys. This is a conceptual inventory, not a demand for unnecessary tables; normalize thoughtfully.

Define explicit uniqueness, foreign keys, check constraints, indexed status/date/customer/institution fields, cascade restrictions, soft delete policy and tenancy/location boundaries. Create a data dictionary and entity relationship overview. Avoid polymorphic relations where they obscure financial integrity. Ensure money calculations and concurrency are transactional.

## 15. WORKFLOWS THAT MUST WORK END-TO-END

A. **Bulk hire:** create institutional booking for 200 gowns -> validate/reserve stock -> collect applicable payment/deposit -> confirm issue -> stock available decreases exactly 200, issued count rises exactly 200, customer/institution record links, receipt generated, SMS queued, finance records appropriate charges/cash/deposit separately. Concurrent requests cannot oversell.
B. **Barcode return:** scan individual asset -> resolve its open hire -> display customer/expected due date/penalties -> confirm returned condition -> stock movement and hire return update -> penalty freezes at correct amount -> outstanding balance and deposit settlement update -> receipt and SMS -> repeat scan does not duplicate.
C. **Partial return:** 80 of 200 returned -> exactly 120 remain outstanding; penalties accrue only to overdue unreturned items according to policy; damaged/inspection stock does not become available prematurely.
D. **Custom order:** lead -> quote -> accepted order -> deposit -> production tasks/fittings -> due reminders -> dispatch with courier/tracking -> delivery confirmation -> invoice/payment reconciliation -> accurate order margin and dashboard status.
E. **Sales:** sale reduces available stock exactly once, creates invoice/payment allocations and correct revenue/COGS according to policy; refund/return uses controlled reversal.
F. **Penalty waiver:** authorized approval and reason -> ledger adjustment and audit entry -> customer balance recalculated -> original assessment retained.
G. **SMS:** send only after committed issue/order state; delivery callbacks update logs; retry/replay cannot send duplicate messages.
H. **Role boundaries:** storekeeper cannot waive penalties or modify financial history without permission; finance cannot silently rewrite physical stock; every attempted forbidden action returns correct denial.

## 16. TESTING, QUALITY AND ACCEPTANCE

- PHPUnit/Pest unit, feature, integration and browser tests where available. Test policies, financial precision, penalty boundaries, return date timezone, daylight changes if relevant, concurrency/double issue, barcode duplicates, bulk stock reservation, partial returns, inspection, refunds, duplicate payment callbacks, SMS idempotency, permission denial and exports.
- Browser/e2e tests for core workflows and responsive desktop/tablet/mobile. Automated screenshot regression of dashboard against approved reference; manually inspect visual differences and correct them. Test camera scanning with real devices where available and manual fallback in CI.
- Accessibility: semantic markup, labels, focus visibility, keyboard navigation, reduced motion, appropriate contrast, understandable alerts and validation errors.
- Performance: indexed queries, eager loading/no N+1, cursor/paginated exports, caching only with invalidation, queue heavy jobs, avoid blocking critical issue/return actions on SMS or PDF generation. Include basic load/concurrency tests for institutional bulk operations.
- Security: dependency scanning, CSRF, authorization bypass tests, file upload restrictions, secrets not committed, secure headers, rate limiting, backup/restore runbook and audit coverage.
- CI: formatter/linter, static analysis, test suite, frontend build, migrations against clean database. No suppressed failing tests or `TODO` masking critical features.

## 17. SINGLE-PROMPT AUTONOMOUS EXECUTION PLAN — BUILD FROM START TO FINISH

1. **This document is the one master execution prompt. Do not stop after a plan, wireframe, first module or first phase.** Continue through every module and acceptance gate in this brief within available execution context, making reasonable documented implementation decisions autonomously. If context/time/tool limits interrupt work, persist a detailed `docs/IMPLEMENTATION_PROGRESS.md` with completed tasks, failing tests, remaining work and the exact next command; resume from that file on the next run. Never claim background execution or completion if work is unfinished. Inspect repository and assets. Summarize discovered state, risks, and concrete implementation order. If existing code is present, refactor incrementally and avoid destructive rewrites.
2. Establish architecture, migrations, design tokens, app shell, authorization and test infrastructure. Add an `AGENTS.md` with repo-specific engineering guidance if absent.
3. Implement Vue/TypeScript/Inertia (or the selected React alternative) dashboard visual shell to screenshot fidelity using real reusable components; mock fixtures are allowed only in isolated development story/test states, never the shipped dashboard.
4. Implement domain logic in dependency order: locations/warehouse/branches/transfers/custody -> catalog/assets/stock -> customers/institutions -> hire/reservation/issue/return -> invoices/payments/deposits/penalties -> CRM/quotes -> custom orders/production/delivery -> SMS/scheduler -> finance/reports -> admin/security/exports.
5. For each module: schema + constraints -> policies -> domain actions -> UI -> tests -> integration checks. Commit logically if git workflow permits.
6. Run the performance benchmarks and optimize until documented release gates pass or clearly report unmet gates with evidence. Finish production configuration, queue workers, scheduler, backups, observability, deployment scripts, user documentation, training notes and acceptance evidence.
7. At each checkpoint report: implemented files/features, tests executed/results, blockers, exact next actions. Do not say complete unless the acceptance tests pass. If environment lacks credentials or access, implement secure adapters and clearly mark integration verification as pending.

## 18. DELIVERABLES

Working Laravel + Vue 3/TypeScript/Inertia codebase (or documented existing React/TypeScript/Inertia alternative); migration/seed/factory set; responsive screenshot-faithful web UI and installable PWA; functional APIs; role/permission model; barcode label and return workflow; all eleven original reports plus multi-location transfer, custody and branch reports; Excel/PDF exports; SMS and payment integration adapters; automated tests and CI configuration; `.env.example`; setup/deployment/backup/restore docs; ERD/data dictionary; operational user guide; feature/acceptance checklist; list of outstanding external dependencies and any unverified integrations.

## 19. DEFINITION OF DONE

A feature is complete only when it has working UI, backend business logic, validated persistence, correct authorization, auditability where required, failure handling, test coverage, and cross-module reconciliation. Every number on the dashboard must be derived from actual authorized records. The approved visual must be closely matched. All eleven reports must return real data. All six requested academic classifications must work in search, stock, rentals, transfers and reporting. The Vue/React frontend choice must be consistent, and performance benchmarks must be measured and documented. Stock cannot be issued twice, and every serialized gown must have a single auditable current custody/location state across the warehouse, branches, transit and customers. Returns and payments must be idempotent. Deposits and assessed penalties must not inflate collected revenue. The system must be deployable and usable by GOWNSEA staff.

**BEGIN NOW:** inspect the repository and reference image; report initial findings briefly; then implement Phase 1 and proceed through the roadmap without waiting for approval on ordinary engineering choices. Ask only when a truly blocking business policy or missing credential/asset cannot be safely resolved by configurable defaults.
