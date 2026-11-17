# Phase 11A — Mining Daily Scheduler (DRY-RUN ONLY)

Business timezone: `Asia/Kolkata` (`Platform::miningBusinessTimezone`).

## Kill-switches (must remain OFF)

- `platform.miningPostingEnabled = false`
- `platform.miningP2pEnabled = false`
- Commission posting / withdrawals / blockchain payouts remain OFF

`mining:daily` never posts wallet credits. Do not create a public HTTP “run mining” URL.

## Example Windows Scheduled Task (Plesk / IIS host)

Run daily after midnight IST (e.g. 00:15 Asia/Kolkata):

```text
Program: C:\xampp\php\php.exe
Arguments: C:\xampp\htdocs\coin-platform\spark mining:daily --dry-run --date=%DATE_IST%
Start in: C:\xampp\htdocs\coin-platform
```

Prefer generating the business date in IST inside the command (omit `--date` to use mining timezone “today”).

Recommended companion tasks (also read-only):

```text
php spark mining:status
php spark mining:reconcile
```

## Linux cron example

```cron
15 0 * * * cd /var/www/coin-platform && /usr/bin/php spark mining:daily --dry-run >> writable/logs/mining-daily.log 2>&1
```

## Operational notes

- Scheduler configured in OS = **UNVERIFIED** from codebase alone.
- Scheduler activated for live posting = **NO** (Phase 11A).
- Missed-run detection: compare `mining_daily_runs` business dates vs calendar.
- Concurrency: dry-run history uses unique `run_ref`; posting (future) must use ledger `idempotency_key`.
- Do not enable posting via cron flags; CLI refuses `--post` / `--credit` / `--execute`.
