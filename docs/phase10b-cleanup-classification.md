# Phase 10B — Cleanup Classification

No production financial records truncated. No blind deletes.

## Required runtime

- Admin controllers/services/views for dashboard, members, coins, payments, earnings (sim), wallets, reports, settings, support
- `PaymentDestinationChangeService` + proposal tables
- AuthGroups permissions including `finance.payment.*`

## Required development / test

- `tests/feature/Phase10BAdminProductionReadinessTest.php` and prior admin feature tests
- Seeders for root sponsor bootstrap

## Safe-to-remove artifact (not deleted)

- Orphan unrouted controllers `PaymentSettingsController`, `PaymentNetworkController` — **needs manual review** before delete (legacy dual-control UI may still be useful as reference)
- Legacy view `app/Views/admin/payment_settings/index.php` — superseded by payment-details; keep until dual-control UI proven in production

## Needs manual review

- Host-specific finance role assignments
- Whether platform admin should retain `finance.monitor.view` in multi-role orgs

## Removed in Phase 10B

- None (classification + redirects only)

## Excluded from deploy package

- `tests/`, phpunit configs, `.git`, local `.env`, `writable/{logs,session,cache}/*`, IDE folders
