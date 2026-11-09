# GOWNSEA Operations

Laravel 13, PHP 8.3+, Vue 3, TypeScript and Inertia application for GOWNSEA inventory operations. Local runtime is configured for SQLite; use PostgreSQL and Redis for a production deployment. The current implementation is **not yet production complete**. See [implementation progress](docs/IMPLEMENTATION_PROGRESS.md) and [deployment readiness](docs/DEPLOYMENT.md) for implemented scope and open release gates.

## Local setup

```sh
composer install
cp .env.example .env
php artisan key:generate
npm ci
php artisan migrate --seed
npm run build
```

Create a staff account with the built-in interactive command:

```sh
php artisan gownsea:create-staff-user
```

Run local web and queue processes in separate terminals:

```sh
php artisan serve
php artisan queue:work --tries=3
```

Vite development mode (separate terminal): `npm run dev`. For Laravel's schedule, run `php artisan schedule:work` locally. Do not commit `.env` or real credentials.

## Checks

```sh
php artisan test --compact
vendor/bin/pint --test
npm run build
```

## Current workflows

Session login, location scoped dashboard and inventory, serialized opening stock, branch transfers, bulk issue up to 200 gowns, individual returns and inspection, customer/location administration, manual hire receipts, and operational summaries. The detailed feature gaps are tracked in `docs/IMPLEMENTATION_PROGRESS.md`; the presence of a page does not mean its domain feature is complete.
