# Phase 13B — Mining accuracy, historical ownership, precision, batching and scheduler reliability

Scope: local only. Every financial mutation ran on the isolated databases `coin_platform_phase13b` (marker-guarded, dedicated
MySQL account, synthetic `SYN…` members) or the PHPUnit database `coin_platform_test`. The live `coin_platform` database was only
read (SELECT-only fingerprint, identical before and after). No production `.env`, credentials, Member IDs, migrations, ownership,
wallet balances or mining credits were changed; all production kill-switches remain `false`.

Machine-generated evidence: `docs/phase13b-validation-results.md`, `writable/phase13b-mining-validation-results.json`
(125 checks, 0 failed, including the performance stage). Operations: `docs/operations/MINING_POST_SCHEDULER_V2.md`.
Pending decisions: `docs/phase13b-business-decisions-required.md`.

## A. Existing architecture review (what 13A left)

| Finding in 13A | Risk | 13B action |
|---|---|---|
| Eligibility from **current** `member_coin_balances` | Back-dated runs and members who bought/sold after the date got wrong income | As-of ledger snapshot (`MiningEligibleOwnershipSnapshotService`) |
| Daily factor truncated to 12 dp, then 8 dp | Income systematically low (e.g. 1000 × 3 %/31) | `MiningMath` — one exact quotient, rounded once |
| Hard 2000-member cap (engine scan capped) | Larger populations refused | Frozen population + keyset batches, no cap |
| `--opt=value` silently ignored by CI4 | `--group=phase13b` would run against **live** DB group | `CliArgs` guard on 16 commands |
| Single status for dry-run / posted | Scheduler could treat a dry run as success | `SCHEDULER_STATUS` + strict exit codes |

Preserved unchanged: ProductionSafetyGate / FinancialSandboxService gates, `--allow-production` + phrase for live, per-date
`ops_job_locks` lock, idempotency key `mining-daily:{user_id}:{business_date}` with unique index, transient-only retry, JSON logging.

**Known limitation (not changed):** the legacy commands `mining:daily` / `mining:reconcile` (`MiningDailyEngineService::dryRun/reconcile`)
still use current balances and the old cap. The as-of / batched path is `mining:post` (and its dry-run). They must not be used for
production posting.

## B. Files changed

New: `app/Libraries/{MiningMath,MiningCalculationPolicy,CliArgs}.php`, `app/Exceptions/MiningBlockedException.php`,
`app/Services/{MiningEligibleOwnershipSnapshotService,MiningBatchPostingService,MiningPostAlertService,Phase13BVerificationService}.php`,
`app/Commands/{MiningPhase13bVerify,MiningPostHealth}.php`, migration `2026-11-18-994001_Phase13BMiningPostBatchTables`,
`tests/feature/Phase13BMiningAccuracyTest.php`, docs listed above.
Modified: `MiningPost` (rewritten), `MiningPostingRunnerService`, `MiningPostVerificationService`, `MiningRateService::previewAmount`,
`FinancialSandboxService` (allow-list `phase13b`), `Config/{Platform,Database}.php`, 15 other Spark commands (argument guard + usage text),
older docs that showed `--opt=value`. **No production migration was run** (live DB still lacks 993001/994001).

## C. Historical ownership calculation

Eligible coins for business date D = ownership at the end of D (Asia/Kolkata), rebuilt from the append-only
`member_coin_ownership_transactions` chain (previous/new total, available, locked, reserved) and cross-checked against approved
`coin_purchase_requests.reviewed_at`. Locked **and** available coins count; not-yet-approved coins do not; sales reduce ownership from their ledger time;
inactive members are excluded. If the chain is broken, an approval timestamp straddles the cutoff, or ledger evidence is missing, the member
becomes `BLOCKED_HISTORICAL_DATA` — never a guess — and the day stays `PARTIAL`.

Timestamps kept separate: request creation, approval (`reviewed_at`), ledger effective time (UTC), mining business date (IST), posting time
(`mining_wallet_ledger.created_at`). Policy options (approval-day full/exclude, reserved include/exclude, matured-locked include/exclude) are
policies, exercised only with named test fixtures. The scheduler posts the **previous completed IST day**; an incomplete day is refused (`BLOCKED`).

Cases (policy `T-CALENDAR-HALFUP`, rate 3 %, October = 31 days; dates posted in the order 17, 15, 16, 14 Oct so that back-dated runs are proven not to see later events).
All 64 per-member/date rows passed against an independent integer oracle, and wallet = ledger sum for every member:

| Case | Member | Story | Eligible 14 / 15 / 16 / 17 Oct | Daily income when 100 eligible |
|---|---|---|---|---|
| A | SYN000001 | 100 bought 15 Oct, +50 bought 16 Oct (15 Oct must use 100, not today's 150) | 0 / 100 / 150 / 150 | 0.09677419 |
| B | SYN000002 | 100 coins, fully locked | 100 each day | 0.09677419 |
| C | SYN000003 | 40 available + 60 locked | 100 each day | 0.09677419 |
| D | SYN000004 | registered, never purchased | 0 each day | 0 |
| E | SYN000005 | 100 coins but account inactive | 0 each day | 0 |
| F | SYN000006 | purchases of 10 (10 Oct), 20 (12 Oct), 30 (17 Oct) | 30 / 30 / 30 / 60 | 0.02903226 per 30 |
| G | SYN000007 | partial sale **before** the date (100 bought, 30 sold 12 Oct) | 70 each day | 0.06774194 |
| H | SYN000008 | partial sale **after** 15 Oct (30 sold 17 Oct) | 100 / 100 / 100 / 70 | 0.09677419 |
| H2 | SYN000009 | sale reserved 14 Oct, settled 17 Oct | 100 / 100 / 100 / 70 | 0.09677419 |
| I | SYN000010 | lock movement only (100 available moved to locked 14 Oct) | 100 each day | 0.09677419 |
| J | SYN000011 | back-dated posting after later transactions: 100 (10 Oct), +200 (16 Oct), sale 50 (17 Oct) | 100 / 100 / 300 / 250 | 0.09677419 |
| K | SYN000012 | ledger evidence removed in a separate stage, then restored | see section 8 of the generated results and section "Case K" below | 0.09677419 |
| L1 | SYN000013 | approved 00:30 IST 15 Oct (= 14 Oct 19:00 UTC) | 0 / 100 / 100 / 100 | 0.09677419 |
| L2 | SYN000014 | approved 23:59:59 IST 15 Oct (= 18:29:59 UTC) | 0 / 100 / 100 / 100 | 0.09677419 |
| L3 | SYN000015 | approved 00:00:00 IST 16 Oct (= 15 Oct 18:30:00 UTC, still 15 Oct in UTC) | 0 / 0 / 100 / 100 | 0.09677419 |
| M | SYN000016 | 100 locked, lock matured 12 Oct (maturity-policy probe) | 100 each day | 0.09677419 |

**Case K:** with the ownership evidence deleted, the run exited 40 (PARTIAL); the member was marked `BLOCKED_HISTORICAL_DATA` (`approved_purchase_without_ledger_credit`), received **no** credit, the other 13 eligible members were credited, and the day never became `COMPLETED`. After the evidence was restored, a re-run credited exactly that one member (exit 0, `COMPLETED`).
Full per-case table (Member ID, date, eligible, rate, divisor, expected, actual, wallet, ledger ref, result): `docs/phase13b-validation-results.md` §3.

## D. Monthly mining precision

`daily = eligible × percent ÷ (100 × divisor)` evaluated once at 40 decimals with bcmath, then rounded **once** to 8 dp (policy: `down` or `half_up`).
No premature truncation, no floats, no residual redistribution, no final-day adjustment. Illustration (1000 coins, 3 %, 31 days):
`half_up` 0.96774194, `down` 0.96774193, fixed-30 divisor 1.00000000. Neither divisor mode nor rounding is approved for production (see decisions doc);
comparative previews are in generated results §6. 19 precision cases matched an independent integer implementation.

## E. 28/29/30/31-day tests

February 28 (2027) and 29 (2028), April 30, January 31, December 31 → January 1 all use the calendar divisor of the **rate's effective month**; fixed-30
always 30. Whole-month totals under the calendar divisor reproduce exactly the monthly percent (within rounding units); fixed-30 does not. Covered in
`Phase13BMiningAccuracyTest::testMonthDivisorsAcrossFebruaryAprilJanuaryAndDecemberJanuary` and the verification precision matrix.

## F. Batch processing

Population frozen per date; keyset batches on `user_id`; durable per-member status (`mining_post_members`) is the checkpoint; `--max-batches` /
`--max-runtime` end an invocation `PARTIAL`; a re-run resumes only missing members. A changed policy version or rate on resume fails closed.
A partial day never gets `finished_at` and is never `COMPLETED`.

## G. 2000+ member results (measured here, local machine, synthetic data, batch 500)

| Members | Wall s | Peak MB | Queries | Batches | Credited | Repeat run credited | Ledger rows | Duplicate groups | Result |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
| 100 | 4.43 | 10 | 1,028 | 1 | 100 | 0 | 100 | 0 | PASS |
| 2000 | 40.71 | 22 | 20,061 | 4 | 2000 | 0 | 2000 | 0 | PASS |
| 2001 | 41.56 | 22 | 20,082 | 5 | 2001 | 0 | 2001 | 0 | PASS |
| 5000 | 102.24 | 32 | 50,127 | 10 | 5000 | 0 | 5000 | 0 | PASS |
| 10000 | 227.19 | 54 | 100,237 | 20 | 10000 | 0 | 10000 | 0 | PASS |

Totals matched the independent oracle to 8 dp in every run (e.g. 10000 members: 387.07744516). Throughput ≈ 44 members/s (~10 queries per
member). Memory grows slowly with population (frozen id list), 54 MB at 10000.

## H. Scheduler reliability

19 real child-process (`php spark mining:post`) scenarios, all PASS: scheduled-yesterday POSTED (0); repeat / manual same-date 0 new credits;
concurrent invocation SKIPPED (20); interrupted batch PARTIAL (40) then resume POSTED with no duplicates or skips; DB connection failure FAILED
(50, nothing written); missing rate BLOCKED (30); auto-post disabled SKIPPED (20); posting kill-switch BLOCKED (30); sandbox without confirmation
SKIPPED (20); historical-ownership mismatch and reconciliation mismatch both PARTIAL (40) and clear after repair; policy change on resume BLOCKED then
resume with the original policy POSTED; incomplete business day BLOCKED; dry run reports DRY_RUN (10) and is never counted as posted.
Alert hooks write only local files/events (no external notification configured).

## I. CLI syntax audit

CI4 supports only `--option value`. All 16 financial commands were audited with real processes: unknown option, `--opt=value` and missing value each exit 2
with nothing executed. The parser matrix covers equals syntax, missing value, value swallowed by an option, duplicates, conflicts, short options, bad numbers and
dates, unknown groups. A malformed group never falls back to `coin_platform` (exit 2). Help text and older docs corrected.

## J. Retry and resume results

Transient failures (deadlock/lock wait/lost connection) retry with back-off; permanent failures do not. 5000-member interrupt test: run 1 stopped after 3 batches
(exit 40, 1500 credited, 56.35 s); run 2 completed the remaining 3500 (exit 0, 99.94 s); total 5000 credits, 0 duplicates, 0 skips.

## K. Idempotency verification

Zero duplicate credits per member per date across all stages (checked by `GROUP BY` on member/date and unique idempotency key); repeat and manual runs create 0 new rows.

## L. Ledger reconciliation

After every posted day: expected (per-member frozen amount) = posted ledger sum, wallet balance = ledger sum for every member, ownership balances untouched by mining.
Injected mismatches are detected (PARTIAL) and clear after repair.

## M. Financial kill-switch verification

`miningPostingEnabled`, `miningAutoPostEnabled`, `miningP2pEnabled`, `commissionPostingEnabled`, `commissionWithdrawalEnabled`, `commissionBlockchainPayoutEnabled`, `coinSellingEnabled`
are all `false` before and after; the sandbox window is closed after every use. Live `coin_platform` fingerprint (members 2, ownership 5.00000000, mining ledger 0, wallet 0, migrations through 2026-11-17-992001) unchanged.

## N–O. Regression and focused PHPUnit

All executed on the PHPUnit database `coin_platform_test` (never the full suite, as instructed). Each file was run once, sequentially, after the last code change; no test was skipped.

| Test file | Tests | Assertions | Failures / errors | Warnings | Result |
|---|---:|---:|---:|---:|---|
| `Phase13BMiningAccuracyTest` (new) | 15 | 112 | 0 | 1 | PASS |
| `Phase13AMiningPostTest` | 6 | 108 | 0 | 1 | PASS |
| `MiningSimulationTest` | 10 | 29 | 0 | 1 | PASS |
| `Phase11AAutoLockMiningTest` | 12 | 47 | 0 | 1 | PASS |
| `Phase11CMiningP2pTest` | 21 | 57 | 0 | 1 | PASS |
| `Phase12EFinancialPolicyTest` | 10 | 45 | 0 | 1 | PASS |
| `Phase12FControlledRemediationTest` | 10 | 52 | 0 | 1 | PASS |
| **Total** | **84** | **450** | **0** | **7** | **PASS** |

The only warning in each file is "No code coverage driver available" (coverage was not requested).

Isolated-database end-to-end runs:

| Run | Result |
|---|---|
| `php spark mining:phase13b-verify --perf` (isolated `coin_platform_phase13b`) | 125 checks passed, 0 failed |
| `php spark mining:post-verify` (Phase 13A, isolated `coin_platform_phase13a`), re-run after all 13B changes | 40 checks passed, 0 failed |

The 13B verify was **not** repeated after the last change (the only later changes were this report, the docs and the member genealogy view, none of which affect posting code).

**Not covered by automated tests (stated honestly):**
* The 10000-member run and the 12-scenario scheduler simulation are only in the isolated-database verify (not PHPUnit); the PHPUnit file covers the same logic at small scale (5 members, batch size 2).
* Transient-error retry (deadlock) and DB-connection-loss behaviour are proven by the 13A tests and the verify's child-process scenario (exit 50), not re-implemented in the 13B PHPUnit file.
* No test exercises a real external alert channel (none is configured).
* The genealogy page redesign made in this session was syntax-checked only, not visually checked in a browser.

## P. Performance

See G; batch-size comparison on 2000 members (single run each, wall clock includes the as-of snapshot and the reconciliation re-verify):

| Batch size | Wall s | Batches | Queries | Peak MB |
|---:|---:|---:|---:|---:|
| 100 | 45.55 | 20 | 20,173 | 20 |
| 250 | 47.40 | 8 | 16,089 | 20 |
| 500 | 35.13 | 4 | 16,061 | 20 |
| 1000 | 52.53 | 2 | 16,047 | 20 |

Timings are single runs on a shared developer machine and are noisy (the web servers on this machine were also running); only the query count trend (fewer
queries as batches grow, flat beyond 250) is reliable. No batch size is recommended as an approved production value.

## Q. Business decisions pending / R. Production activation blockers

See `docs/phase13b-business-decisions-required.md` (16 decisions; all eight `mining*Status` flags remain `pending_business_decision`).

## S. Recommended Phase 13C

1. Resolve the policy decisions and encode them as approved settings with an audit trail.
2. Production-copy dry run: run `mining:post` dry-run for the last N days against a **restored backup copy** and review every `BLOCKED_HISTORICAL_DATA` member.
3. Retire or reroute `mining:daily` / `mining:reconcile` so the old current-balance path cannot post.
4. Alert delivery (approved channel) and the `mining:post-health` watchdog on a schedule.
5. Controlled migration rehearsal (993001, 994001) on the backup copy, then production change window.
