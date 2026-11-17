# Phase 10B — Admin Panel Production Deployment Checklist

Do **not** deploy automatically. Do **not** enable commission posting, withdrawals, or blockchain payout without a separate authorization phase.

## Pre-flight (extends Phase 10A)

- [ ] Complete Phase 10A member checklist first
- [ ] PHP 8.2+, Composer `--no-dev`, document root = `public/`
- [ ] `env.production.example` → `.env` with unique `encryption.key`
- [ ] HTTPS + `app.forceGlobalSecureRequests = true`
- [ ] Migrations applied including payment destination proposals + Phase 10A email/wallet tables
- [ ] Kill-switches remain `false` in production `.env`

## Admin security

- [ ] Root admin password rotated after first login
- [ ] Assign `finance_approver` (separate user) for payment destination dual-control
- [ ] Confirm `finance_viewer` cannot approve payments or register members
- [ ] Payment Details: bootstrap wallet once, then dual-control for changes
- [ ] No demo / burn receiving wallets enabled
- [ ] CSRF on; debug toolbar off in production
- [ ] IIS: `public/web.config`; Apache: `public/.htaccess`

## Admin smoke (staging)

- [ ] Admin login / logout
- [ ] Dashboard KPIs
- [ ] Member list / register / profile
- [ ] Inventory add + price update
- [ ] Locking plans (admin group)
- [ ] Manual payment approve → purchase approve → coin credit → activation
- [ ] Payment Details dual-control approve by second user
- [ ] Payment Monitoring tabs (health, reconciliation, audit, alerts, allocations, remediation, notifications, preflight)
- [ ] Mining / commissions simulation only
- [ ] Wallets & Withdrawals read-only / disabled payout
- [ ] Reports tabs + CSV export permissions
- [ ] Settings whitelist (no kill-switch edits)
- [ ] Support ticket reply / status

## Exclude from production package

Same as Phase 10A: `tests/`, phpunit configs, `.git`, local `.env`, writable runtime contents, IDE folders.

## Rollback

Restore DB backup + previous release package. Keep kill-switches OFF.
