# Daily Mining Posting Scheduler — `mining:post` (Phase 13B, v2)

**Status: implemented and verified on isolated databases only (`coin_platform_phase13a`, `coin_platform_phase13b`, PHPUnit DB).
Automatic posting is DISABLED in production (`Platform::miningAutoPostEnabled = false`, `miningPostingEnabled = false`).
No production scheduler / cron / Task Scheduler entry exists and none must be created until the activation checklist in
`docs/phase13b-business-decisions-required.md` is signed off.**

This document supersedes `MINING_POST_SCHEDULER.md` (Phase 13A) where they differ.

## 1. Command syntax (space-separated ONLY)

```text
php spark mining:post [--date yesterday|today|YYYY-MM-DD] [--group phase13b] [--confirm <phrase>]
                      [--allow-production] [--scheduled] [--dry-run] [--strict-exit]
                      [--limit N] [--batch-size N] [--max-batches N] [--max-runtime SECONDS]
                      [--allow-incomplete-day] [--actor N]
```

CodeIgniter 4 parses only `--option value`. `--option=value` is **silently turned into an option named `option=value` with no
value**, so e.g. `--group=phase13b` would have been ignored and the command would have run against the **default (live)
database group**. Phase 13B adds `App\Libraries\CliArgs`, a guard over the raw argv used by every financial Spark command. It
refuses (exit 2, nothing executed): `--opt=value`, unknown options, missing values, a value swallowed by the next option,
flags given a value, duplicate options, short options (`-g`), stray positional arguments, conflicting options
(`--dry-run` + `--confirm`), invalid numbers/dates and database groups that are not on the allow-list. A malformed group can
never select `coin_platform`.

Wrong → right:

| Refused | Use |
|---|---|
| `--group=phase13b` | `--group phase13b` |
| `--confirm=APPLY_STAGING` | `--confirm APPLY_STAGING` |
| `--limit=5` | `--limit 5` |

## 2. Modes and gates

| Mode | Behaviour |
|---|---|
| no `--confirm`, not `--scheduled` | **Dry-run** (nothing written; as-of preview) |
| `--group phase13b --confirm APPLY_STAGING` | Real posting on the isolated DB (time-boxed sandbox window, synthetic members only) |
| `--scheduled` | Scheduler entry. `SKIPPED` while `miningAutoPostEnabled=false` |
| live DB | Refused unless **all** Phase 13A live gates pass (kill-switches, 8 approved policy flags, `--allow-production`, phrase) |

The 13A gates, per-date lock (`mining_post:{date}`), idempotency key (`mining-daily:{user_id}:{business_date}`, unique) and
retry rules are unchanged. There is **no** hidden bypass: a test-only switch exists only inside the allow-listed isolated
databases, inside a time-boxed sandbox window that closes automatically.

## 3. Which business date does the scheduler post?

`--date yesterday` (default): the **completed** Asia/Kolkata business day. A date that is still in progress is refused
(`BLOCKED`) unless `--allow-incomplete-day` is given on a sandbox DB. Future dates are always refused. Recommended
(NOT approved) schedule: shortly after 00:00 IST for the previous day; see "Example" below.

## 4. Status values and exit codes (`--strict-exit`)

| SCHEDULER_STATUS | Meaning | Exit |
|---|---|---|
| `POSTED` | Day fully posted and reconciled (`COMPLETED`) | 0 |
| `DRY_RUN` | Preview only — **never** counted as posted | 10 |
| `SKIPPED` | Auto-post disabled, or another run holds the per-date lock (a re-run of an already `COMPLETED` day reports `POSTED` with 0 new credits) | 20 |
| `BLOCKED` | Policy/rate/date/historical-evidence refusal; nothing posted | 30 |
| `PARTIAL` | Some members unresolved (failed / blocked / interrupted); day **not** complete; re-run resumes | 40 |
| `FAILED` | Hard failure (DB lost, retry exhausted, reconciliation mismatch) | 50 |
| usage error | Bad CLI syntax | 2 |

Without `--strict-exit` the legacy 13A mapping applies. A scheduler wrapper should always pass `--strict-exit` and alert on
anything other than 0/20.

## 5. Day run states

`mining_post_day_runs` (one row per business date): `PENDING → RUNNING → COMPLETED | PARTIAL | FAILED`.
`COMPLETED` is written only when every frozen member is `CREDITED`/`SKIPPED`/zero and reconciliation matches; `finished_at`
is set only then. A partial day is **never** marked complete. Per-member rows live in `mining_post_members`
(`PENDING | CREDITED | SKIPPED | BLOCKED | FAILED`). A resume only works on members that are not yet credited; credited
members are never recomputed against a different policy — a changed policy version, rate or frozen cutoff on resume **fails
closed**.

## 6. Batching (replaces the 2000-member cap)

* Population is frozen once per date (stable keyset of non-root, active-or-evidenced members) so members registered during
  the run do not change the day.
* Keyset batches by `user_id` (`--batch-size`, default `Platform::miningPostBatchSize`, a **test value, not an approved
  production default**).
* Durable checkpoint after every batch; `--max-batches` / `--max-runtime` end the invocation cleanly as `PARTIAL`.
* Per-member idempotency means a re-run after a crash credits only what is missing.
* `--limit N` is now an optional hard cap that **refuses** (not truncates) a larger population.

## 7. Monitoring and alerts (local hooks only)

* Structured JSON lines: `writable/logs/mining-post.log` (events `run-started`, `batch`, `run-finished`, `command-exit`).
  Passwords, DB credentials and tokens are never written.
* Alerts: `writable/logs/mining-post-alerts.log`, CI4 log and event `mining_post_alert`. Codes: `nonzero_exit`,
  `partial_run`, `missing_rate`, `reconciliation_failed`, `excessive_retry`, `long_runtime`, `missing_scheduled_run`.
* Missing-run watchdog (read-only): `php spark mining:post-health --date yesterday [--group phase13b]` → exit 0 when the day
  is `COMPLETED`, 1 otherwise (and an alert).
* **No external notification (e-mail/webhook/chat) is configured.** Subscribe to the `mining_post_alert` event when that is
  authorized.

## 8. Example schedule (DO NOT ACTIVATE until authorized)

```text
Program:   C:\xampp\php\php.exe
Arguments: spark mining:post --scheduled --strict-exit --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE
Start in:  C:\xampp\htdocs\coin-platform
Settings:  Do not start a new instance if one is running; stop after 60 minutes
```

A second task later in the morning running `php spark mining:post-health --date yesterday` provides the missing-run alert.

## 9. Verification

* `php spark mining:phase13b-verify [--perf]` — isolated end-to-end verification (writes
  `writable/phase13b-mining-validation-results.json` and `docs/phase13b-validation-results.md`).
* `php spark mining:post-verify` — Phase 13A end-to-end regression (isolated DB).
* `php vendor/bin/phpunit tests/feature/Phase13BMiningAccuracyTest.php` — focused unit/DB tests.
