# Performance baseline and release measurements

No representative staging dataset, load generator or Lighthouse/Core Web Vitals run is available. A local headless browser was used for dashboard layout and screenshot regression only. The current PHP runtime exposes PDO SQLite but not PDO PostgreSQL, and there is no configured Redis or production-like service. **The master prompt's latency, database-concurrency and Core Web Vitals gates have not been measured and must be treated as unmet.** Do not use local test timings as production evidence.

## Targets from the product brief

- Ordinary indexed reads p95 ≤ 300 ms; standard writes p95 ≤ 700 ms (excluding external providers).
- Barcode lookup p95 ≤ 250 ms; scan confirmation p95 ≤ 700 ms; global search p95 ≤ 400 ms.
- Dashboard LCP ≤ 2.5 s on a representative modern device/network.
- Measure p50/p95/p99, throughput, failure rate, DB query timings and queue latency for all scenarios.

## Required load profile and evidence

Run against a documented staging instance with thousands of serialized assets, multiple branches and concurrent staff. Record CPU/RAM, PHP workers, database size/version/indexes, Redis, network, browser/device and request mix. Exercise concurrent asset issue, duplicate return scans, branch dispatch/receive, global search, dashboards, and a 200 item institutional booking/return flow. Capture query plans for inventory filters and lookup fields; test cold and warm caches separately.

| Scenario | Result | Status |
| --- | --- | --- |
| Dashboard LCP / Core Web Vitals | Local reference-size screenshot reviewed; LCP/CWV not measured | Open |
| Indexed reads / writes p50 p95 p99 | Not measured against staging | Open |
| Barcode lookup and confirmation | Not measured on a supported camera device | Open |
| Concurrent issue / transfer / return | Not load tested | Open |
| 200 item serialized hire issue | Supported by the transactional issue path; not load tested | Open |
| Bulk quantity hire / partial return / inspection | Feature tests pass; not load tested | Open |

The production build code-splits Inertia pages and the dashboard chart module. The current build emitted a main entry of approximately 64.7 kB gzip and a dashboard chunk of approximately 75.0 kB gzip. These are build artifact sizes only, not page-load or LCP measurements. No production latency or Core Web Vitals gate is marked passed.

`performance/gownsea-readonly.js` provides a safe authenticated staging load profile for the dashboard and global-search read paths. It uses a short-lived staging session cookie, ramps to the configured VU count, and checks dashboard/search p95 targets and HTTP errors. Example: `BASE_URL=https://staging.example SESSION_COOKIE_NAME=gownsea-operations-session SESSION_COOKIE='<short-lived-session>' k6 run performance/gownsea-readonly.js`. Never point the script at production or use a staff session with write permissions. It does not measure write, barcode-camera, transfer, or full hire/return concurrency scenarios; those still need staging-specific scripts and controlled test data.
