# Daily Mining Posting Scheduler — `mining:post` (Phase 13A)

**Status: implemented and tested on the isolated DB `coin_platform_phase13a` only.
Automatic posting is DISABLED (`Platform::miningAutoPostEnabled = false`). Do not create a production
cron / Scheduled Task until the authorization checklist below is signed off.**

## Command

```text
php spark mining:post [--date yesterday|today|YYYY-MM-DD] [--group <db group>] [--confirm <phrase>]
                      [--allow-production] [--scheduled] [--dry-run] [--limit 2000] [--actor 0]
```

> Options are **space separated**. CodeIgniter's CLI does not parse `--opt=value`; `mining:post`
> refuses that syntax instead of silently falling back to the live database.

| Mode | Behaviour |
|---|---|
| no `--confirm` | **Dry-run** (read-only, nothing persisted) |
| `--group phase13a --confirm APPLY_STAGING` | Real posting on the isolated test DB (time-boxed sandbox window, synthetic members only) |
| live DB | Refused unless **all** gates pass (see below) |
| `--scheduled` | Scheduler entry. Exits `0` with `SKIPPED` while auto-posting is not authorized |

Default business date is `yesterday` (the completed day, Asia/Kolkata). Future dates are refused.

## Gates for LIVE posting (all required)

1. `Platform::miningPostingEnabled = true`
2. For `--scheduled`: `Platform::miningAutoPostEnabled = true`
3. All `mining*Status` policy flags in `Platform.php` equal `approved`
4. `--allow-production`
5. `--confirm I_AUTHORIZE_PRODUCTION_CHANGE`
6. An approved monthly mining rate exists for the business month (dual control)
7. Active DB connection equals the classified target DB (isolation guard)

## Safety design

* **Idempotency**: ledger key `mining-daily:{user_id}:{business_date}` (unique index). Re-running a date
  creates zero new credits.
* **Locking**: `ops_job_locks` row `mining_post:{date}` (TTL `miningPostLockTtlSeconds`). A concurrent run exits
  `SKIPPED (skipped_locked)`. Stale `running` rows from crashed runs are marked `abandoned`.
* **Retry**: transient errors (deadlock, lock-wait timeout, lost connection) are retried up to
  `miningPostMaxAttempts` with linear back-off (`miningPostRetryDelaySeconds` × attempt). Safe because credits are idempotent.
  Permanent errors are not retried.
* **Per-member isolation**: in scheduler mode one failing member is reported in `errors`; others are still posted.
* **Fail-closed population cap**: more than 2000 non-root members → refused (engine scan is capped at 2000).
* **Reconciliation** after every run (expected vs posted); mismatches → exit code 1.
* **Logging**: JSON lines in `writable/logs/mining-post.log` (+ CI4 log + `audit_logs` event `mining.post_run`),
  run history in `mining_daily_runs` (`run_type = post`).
* Exit codes: `0` = completed / dry-run / skipped; `1` = refused, failed, completed with errors or mismatch.

## Example schedule (DO NOT ACTIVATE until authorized)

Windows Task Scheduler (daily 00:20 IST):

```text
Program:   C:\xampp\php\php.exe
Arguments: spark mining:post --scheduled --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE
Start in:  C:\xampp\htdocs\coin-platform
Settings:  Do not start a new instance if one is running; stop after 30 minutes
```

Linux cron:

```cron
20 0 * * * cd /var/www/coin-platform && /usr/bin/php spark mining:post --scheduled --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE >> writable/logs/mining-post-cron.log 2>&1
```

Monitor: alert on exit code ≠ 0, on a missing `mining_daily_runs` row for yesterday, and on `completed_with_*` statuses.

## Authorization checklist (before enabling in production)

- [ ] Business approves monthly %, day-divisor, denomination (COIN/USDT), approval-day, locked/matured/sold-coin eligibility, rounding → set all `mining*Status = approved`
- [ ] Dual-control approved mining rate for each month
- [ ] Decide as-of-date balance policy (engine uses CURRENT balances) — see report blockers
- [ ] Fix/accept divisor precision (see report finding)
- [ ] Missing mandatory lock on live (`PUR-…`) remediated
- [ ] Backup + dry-run on a production snapshot restored to a staging DB
- [ ] Written authorization, then set `miningPostingEnabled` and `miningAutoPostEnabled` via `.env` and create the task

## Test tooling (isolated DB only)

```text
php spark mining:post-verify        # recreates coin_platform_phase13a, seeds, runs the real command, writes docs/phase13a-mining-post-report.md
vendor\bin\phpunit tests\feature\Phase13AMiningPostTest.php
```
