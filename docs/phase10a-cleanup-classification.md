# Phase 10A — Cleanup Classification (Member Panel readiness)

No production financial records truncated. No blind deletes performed.

## Required runtime dependency

- `app/`, `public/`, `vendor/` (prod Composer), `writable/` structure
- `composer.json` / `composer.lock`
- All migrations under `app/Database/Migrations/`
- Legitimate seeders (`RootSponsorSeeder`, etc.)
- Shield + platform configs

## Required development / test dependency

- `tests/`, `phpunit.xml*`, `env` (local template)
- Dev Composer packages (`--dev`)
- Feature fixtures created only inside PHPUnit DB

## Safe-to-remove artifact (none deleted in 10A)

- None verified as orphaned without risk. Prefer exclusion from deploy package over deletion.
- No `.bak` / `.old` / SQL dump files found in repo root scan.

## Needs manual review

- Local `.env` (never deploy; contains secrets)
- `writable/logs/*`, `writable/session/*`, `writable/cache/*` (exclude contents)
- Any host-specific IIS bindings / SSL certs outside repo
- Demo `is_demo` payment network rows in DB (service already hides; admin must not approve)

## Exclude from production deployment package

- `tests/`
- `phpunit.xml`, `phpunit.xml.dist`
- `.git/`, `.github/` (optional CI keep separate)
- `.cursor/`, IDE folders
- `docs/` (optional; checklist can be kept ops-side)
- Local `.env`
- `writable/logs/*`, `writable/session/*`, `writable/cache/*` contents
- `env` development template secrets if any were uncommented locally

## Removed in Phase 10A

- None (classification-only; no verified unnecessary runtime files deleted)
