# Phase 10A — Member Panel Production Deployment Checklist

Do **not** deploy automatically. Do **not** enable mining accrual, commission posting, or withdrawals without a separate authorization phase.

## Pre-flight

- [ ] PHP 8.2+ with extensions: `intl`, `mbstring`, `json`, `mysqlnd`/`mysqli`, `curl`, `openssl`, `fileinfo`, `gd` or `imagick` (QR/evidence), `exif` optional
- [ ] Composer install: `composer install --no-dev --optimize-autoloader`
- [ ] Document root = `public/` (IIS/Plesk/Apache)
- [ ] `public/web.config` present for IIS; Apache uses `public/.htaccess`
- [ ] Writable: `writable/cache`, `writable/logs`, `writable/session`, `writable/uploads` (and payment evidence path)
- [ ] Copy `env.production.example` → `.env`; set secrets; never commit `.env`
- [ ] `CI_ENVIRONMENT = production`; debug toolbar disabled
- [ ] `app.forceGlobalSecureRequests = true` behind HTTPS
- [ ] `encryption.key` generated uniquely per environment
- [ ] DB credentials least-privilege; backups verified before migrate

## Database

- [ ] Backup production DB
- [ ] Run migrations: `php spark migrate`
- [ ] Confirm Shield + platform + Phase 10A email/wallet tables exist
- [ ] Seed root sponsor only if empty (never re-seed over live members)
- [ ] Kill-switches remain `false` for posting / withdrawals / blockchain payout
- [ ] Payment networks: no demo/`is_demo` destinations approved for display

## Security

- [ ] CSRF enabled (non-testing)
- [ ] Secure / HttpOnly / SameSite cookies
- [ ] Rate limits on auth + member profile/password/payment routes
- [ ] File upload MIME/size validation for deposit evidence
- [ ] No public access to `app/`, `writable/`, `tests/`, `.env`, `vendor/` source maps
- [ ] Rotate bootstrap admin password after first login

## Member smoke (staging)

- [ ] Register + login + logout
- [ ] Dashboard KPIs match wallet/purchase services
- [ ] Profile update; pending email verify; wallet BEP20 + audit
- [ ] Password change
- [ ] Buy coins → payment submit stays pending
- [ ] Purchases / deposits ownership scoped
- [ ] Wallet invariant: owned = available + locked
- [ ] Locking 1000-day policy; no early unlock
- [ ] Referrals / genealogy isolation
- [ ] Mining + commissions labeled Simulation; zero fake credits
- [ ] Withdrawals UI Not Available; POST blocked
- [ ] Support ticket create / reply / isolation

## Rollback

- [ ] Restore DB backup
- [ ] Redeploy previous release package
- [ ] Keep kill-switches OFF

## Exclude from production package

- `tests/`, `phpunit.xml*`, `.git/`, `docs/` (optional), local `.env`, `writable/logs/*`, `writable/session/*`, `writable/cache/*`, SQL dumps, IDE folders, `node_modules` if unused
