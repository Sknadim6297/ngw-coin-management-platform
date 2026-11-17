# Next Generation Wealth (NGW)
### Coin Management & Financial Operations Platform

A modular, security-focused coin management platform built with **CodeIgniter 4, PHP, MySQL, and CodeIgniter Shield**.

NGW provides member administration, internal coin inventory management, purchase requests, blockchain payment verification infrastructure, wallet accounting, coin locking, and financial operations monitoring.

## Technology Stack

- **Backend:** PHP 8.2+, CodeIgniter 4.7.6
- **Authentication:** CodeIgniter Shield
- **Database:** MySQL
- **Frontend:** Bootstrap 5, JavaScript, HTML, CSS
- **Blockchain Integration:** EVM-compatible ERC-20 transaction verification
- **Testing:** PHPUnit
- **Deployment Target:** Windows IIS / Plesk

## Key Features

### Member Management
- Secure member registration and authentication
- Role-based access control
- Member profiles and dashboards
- Sponsor validation and member identification

### Coin Inventory & Pricing
- Administrative coin inventory management
- Append-only inventory ledger
- Dynamic coin pricing in USDT
- Price change history
- Inventory allocation tracking

### Coin Purchases
- Member purchase requests
- Administrative approval and rejection
- Server-side price snapshots
- Verified-payment approval controls
- Transaction-safe inventory allocation

### USDT Deposit Infrastructure
- Configurable blockchain payment networks
- Deposit request and evidence management
- EVM ERC-20 transaction verification provider
- Confirmation and transaction validation
- Deposit-to-purchase reconciliation
- Duplicate payment protection

### Coin Locking
- Configurable locking plans
- Available and locked coin balances
- Lock maturity tracking
- Member unlocking workflows
- Append-only ownership accounting

### Financial Operations
- Payment health monitoring
- Deposit integrity checks
- Reconciliation reports
- Financial alerts
- Finance-specific access controls
- Dual-control remediation review
- Audit trails and notification outbox

## Security Architecture

- Shield authentication and permissions
- CSRF protection and server-side validation
- Database transactions and row locking
- Idempotent financial operations
- Append-only transaction history
- Secure payment evidence storage
- Financial separation of duties
- Sensitive configuration redaction

## Local Development

1. Clone the repository.
2. Install PHP dependencies with `composer install`.
3. Configure a local `.env` file.
4. Create the MySQL database.
5. Run `php spark migrate --all`.
6. Configure local authentication/bootstrap data using documented project commands.
7. Start the application using `php spark serve`.

## Automated Testing

Run:

`php vendor/bin/phpunit --no-coverage`

Phase 5 reported baseline: **142 tests, 409 assertions, 0 failures**.

Phase 6 wallet suite (focused): **MemberWalletTest 15/15 (81 assertions)**; wallet + dashboard analytics **25/25 (162 assertions)**. Full-suite count grows with later modules — re-run `php vendor/bin/phpunit --no-coverage` locally for the current total.

These figures describe reported local test runs, not a verified production deployment.

Member wallet: `/member/wallet`, `/member/wallet/transactions` (CSV export).  
Admin wallets: `/admin/wallets`, `/admin/wallets/{memberId}`, `/admin/wallets/{memberId}/transactions`, `/admin/wallets/reconcile`.

Phase 7A referrals: `/member/referrals`, `/member/genealogy`, `/admin/referrals`, `/admin/genealogy` (read-only integrity).

Phase 7B mining simulation (non-payable): `/admin/mining`, `/admin/mining/rates`, `/member/mining`, CLI `php spark mining:simulate --date=YYYY-MM-DD`. Actual mining posting is not activated.

## Project Status

**Active Development — Not Production Ready**

Implemented through Phase 6: internal member coin wallet overview, ledger history/detail, CSV export, admin wallet search/audit, and read-only wallet reconciliation. No ROI, mining rewards, commissions, withdrawals, or external transfers.

The blockchain verification architecture is implemented, but live USDT payments are disabled pending trusted RPC configuration, wallet validation, operational checks, and applicable compliance review.

## Important Notice

This repository contains software infrastructure for internal coin accounting and payment verification workflows. It does not guarantee financial returns or represent a live investment service.

Never commit production credentials, private keys, wallet seed phrases, database backups, or sensitive member information.

## License

Proprietary — All rights reserved unless a separate license is explicitly provided.
