# Phase 13A — `mining:post` isolated test report

Generated: 2026-11-18T07:18:38+00:00  
Isolated DB: `coin_platform_phase13a` — **Overall: PASS** (40 passed / 0 failed)

## 1. Setup

- Business dates: yesterday=`2026-11-17` today=`2026-11-18`
- Test-only approved monthly rate: **3.00000000%** (dual control; created by admin, approved by a different approver; isolated DB only)
- Production kill-switches before: `{"miningPostingEnabled":false,"miningP2pEnabled":false,"commissionPostingEnabled":false,"commissionWithdrawalEnabled":false,"commissionBlockchainPayoutEnabled":false,"coinSellingEnabled":false,"sandbox_window_open":false}`
- Production kill-switches after: `{"miningPostingEnabled":false,"miningP2pEnabled":false,"commissionPostingEnabled":false,"commissionWithdrawalEnabled":false,"commissionBlockchainPayoutEnabled":false,"coinSellingEnabled":false,"sandbox_window_open":false}`

## 2. Expected vs actual credits and wallet balances

| Member | Kind | Owned | Locked | Available | D1 expected | D1 actual | D2 expected | D2 actual | Wallet balance | Ledger rows |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| SYN000001 | LOCKED_ONLY | 100.00000000 | 100.00000000 | 0.00000000 | 0.10000000 | 0.10000000 | 0.10000000 | 0.10000000 | 0.20000000 | 2 |
| SYN000002 | LOCKED_ONLY | 250.50000000 | 250.50000000 | 0.00000000 | 0.25050000 | 0.25050000 | 0.25050000 | 0.25050000 | 0.50100000 | 2 |
| SYN000003 | AVAILABLE_ONLY | 40.00000000 | 0.00000000 | 40.00000000 | 0.04000000 | 0.04000000 | 0.04000000 | 0.04000000 | 0.08000000 | 2 |
| SYN000004 | MIXED | 50.00000000 | 20.00000000 | 30.00000000 | 0.05000000 | 0.05000000 | 0.05000000 | 0.05000000 | 0.10000000 | 2 |
| SYN000005 | NO_PURCHASE | 0.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0 |
| SYN000006 | INACTIVE | 60.00000000 | 60.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0 |
| SYN000007 | STARTS_TODAY | 80.00000000 | 80.00000000 | 0.00000000 | 0.00000000 | 0.00000000 | 0.08000000 | 0.08000000 | 0.08000000 | 1 |
| **Total** | | | | | **0.44050000** | **0.44050000** | **0.52050000** | **0.52050000** | **0.96100000** | |

## 3. Scheduler executions (real `php spark mining:post`)

| Run | Exit | Status | Credited | Duplicates | Attempts | Expected total | Posted total | Mismatches |
|---|---:|---|---:|---:|---:|---:|---:|---:|
| d1_first | 0 | completed | 4 | 0 | 1 | 0.44050000 | 0.44050000 | 0 |
| d1_second | 0 | completed | 0 | 4 | 1 | 0.44050000 | 0.44050000 | 0 |
| d1_manual | 0 | completed | 0 | 4 | 1 | 0.44050000 | 0.44050000 | 0 |
| d2_first | 0 | completed | 5 | 0 | 1 | 0.52050000 | 0.52050000 | 0 |
| d2_second | 0 | completed | 0 | 5 | 1 | 0.52050000 | 0.52050000 | 0 |

## 4. Safety scenarios

| Scenario | Exit | Status / message |
|---|---:|---|
| dry_run_default | 0 | dry_run DRY-RUN only — no wallet was credited. |
| wrong_confirm | 1 | CodeIgniter v4.7.6 Command Line Tool - Server Time: 2026-11-18 07:18:17 UTC+00:00    === mining:post ===  SCHEDULER_STATUS=BLOCKED EXIT_CODE=1  BLOCKED — Sandbox posting requires --confirm APPLY_STAGING |
| scheduled_unauthorized | 0 | skipped_disabled Scheduled posting not authorized (missing --confirm APPLY_STAGING). |
| future_date | 1 | deIgniter v4.7.6 Command Line Tool - Server Time: 2026-11-18 07:18:19 UTC+00:00    === mining:post ===  SCHEDULER_STATUS=BLOCKED EXIT_CODE=1  BLOCKED — Refusing to post mining for a future business date (2099-01-01). |
| no_approved_rate | 1 | CodeIgniter v4.7.6 Command Line Tool - Server Time: 2026-11-18 07:18:21 UTC+00:00    === mining:post ===  SCHEDULER_STATUS=BLOCKED EXIT_CODE=1  BLOCKED — No approved mining rate for 2020-01. Nothing posted. |
| population_over_limit | 1 | 6 Command Line Tool - Server Time: 2026-11-18 07:18:25 UTC+00:00    === mining:post ===  SCHEDULER_STATUS=BLOCKED EXIT_CODE=1  BLOCKED — Member population (7) exceeds the explicit --limit (3). Refusing a partial run. |
| bad_flag_post | 2 | CodeIgniter v4.7.6 Command Line Tool - Server Time: 2026-11-18 07:18:25 UTC+00:00    [mining:post] Unknown option "--post".  Nothing was executed. See: php spark help mining:post |
| live_scheduled_default | 0 | skipped_disabled miningAutoPostEnabled=false — automatic mining posting is disabled. |
| live_manual_full_confirm | 1 | mand Line Tool - Server Time: 2026-11-18 07:18:26 UTC+00:00    === mining:post ===  SCHEDULER_STATUS=BLOCKED EXIT_CODE=1  BLOCKED — Mining posting kill-switch is OFF (miningPostingEnabled=false). No credit performed. |
| unknown_group | 2 | l - Server Time: 2026-11-18 07:18:28 UTC+00:00    [mining:post] Invalid value "not_a_group" for --group (allowed: default, phase12a, phase12b, phase13a, phase13b).  Nothing was executed. See: php spark help mining:post |
| equals_syntax | 2 | be ignored.  [mining:post] Unsupported syntax "--confirm=APPLY_STAGING". Use "--confirm " (space-separated); "=" is not parsed by the CI4 CLI and would be ignored.  Nothing was executed. See: php spark help mining:post |
| lock_contention | 0 | skipped_locked Another mining:post run holds mining_post:2026-11-17. |

## 5. Reconciliation

```json
{
    "d1": {
        "rows": 7,
        "mismatches": 0,
        "date": "2026-11-17",
        "mismatch_rows": []
    },
    "d2": {
        "rows": 7,
        "mismatches": 0,
        "date": "2026-11-18",
        "mismatch_rows": []
    }
}
```

- Duplicate credit groups: `0`
- Ledger vs wallet mismatches: `0`

## 6. Run history (`mining_daily_runs`, run_type=post)

| Run ref | Date | Status | Members | Eligible | Expected | Posted |
|---|---|---|---:|---:|---:|---:|
| MPR-20261118071833-EA2006 | 2026-11-17 | completed | 7 | 4 | 0.44050000 | 0.44050000 |
| MPR-20261118071834-9ED476 | 2026-11-17 | completed | 7 | 4 | 0.44050000 | 0.44050000 |
| MPR-20261118071835-F07B26 | 2026-11-17 | completed | 7 | 4 | 0.44050000 | 0.44050000 |
| MPR-20261118071836-CF7B05 | 2026-11-18 | completed | 7 | 5 | 0.52050000 | 0.52050000 |
| MPR-20261118071837-267504 | 2026-11-18 | completed | 7 | 5 | 0.52050000 | 0.52050000 |

## 7. Live DB untouched (read-only fingerprint)

- Before: `{"database":"coin_platform","mining_wallets":0,"mining_wallet_ledger":0,"mining_daily_runs":0,"mining_daily_calculations":0,"mining_rate_configs":1,"member_coin_balances":1,"ops_job_locks":1,"mining_wallets_sum":"0.00000000","member_coin_balances_sum":"5.00000000"}`
- After: `{"database":"coin_platform","mining_wallets":0,"mining_wallet_ledger":0,"mining_daily_runs":0,"mining_daily_calculations":0,"mining_rate_configs":1,"member_coin_balances":1,"ops_job_locks":1,"mining_wallets_sum":"0.00000000","member_coin_balances_sum":"5.00000000"}`

## 8. Precision finding (informational)

```json
{
    "case": "1000 coins @ 3% monthly, 31-day month",
    "engine_amount": "0.96774193",
    "exact_amount": "0.96774193",
    "engine_shortfall": "0.00000000",
    "cause": "Phase 13A defect (MoneyMath::mul() cut the 12-dp daily factor to 8 dp) \u2014 FIXED in Phase 13B: amounts now use MiningMath (40-dp intermediate, single final rounding); shortfall must be 0.",
    "affects_this_test": "No \u2014 test dates fall in a 30-day month where the factor terminates exactly."
}
```

## 9. All checks

| Result | Check | Detail |
|---|---|---|
| PASS | `platform_kill_switches_off_by_default` | miningPostingEnabled=false miningAutoPostEnabled=false |
| PASS | `seed_members_created` | 7 synthetic members |
| PASS | `test_only_rate_approved_dual_control` | rates=MRC-20261118-254DE9B0 @3.00000000% monthly |
| PASS | `locked_coins_present_in_cohort` | A locked=100.00000000 D available/locked=30.00000000/20.00000000 |
| PASS | `kill_switch_blocks_postDay_without_authorization` | Mining posting is disabled (kill-switch). |
| PASS | `kill_switch_blocks_direct_wallet_credit` | creditDailyMining refused without window |
| PASS | `dry_run_default_exit0_status_dry_run` | status=dry_run |
| PASS | `dry_run_reports_expected_total` | expected_total=0.44050000 |
| PASS | `wrong_confirm_refused` | exit=1 |
| PASS | `scheduled_without_authorization_skipped` | status=skipped_disabled |
| PASS | `future_date_refused` | exit=1 |
| PASS | `missing_rate_refused` | exit=1 |
| PASS | `population_over_limit_refused` | exit=1 |
| PASS | `post_flag_rejected` | exit=2 |
| PASS | `live_db_scheduled_default_skipped_disabled` | status=skipped_disabled |
| PASS | `live_db_manual_full_confirm_still_refused_by_kill_switch` | exit=1 |
| PASS | `unknown_group_refused` | exit=2 |
| PASS | `equals_syntax_refused_not_silently_live` | exit=2 |
| PASS | `lock_contention_skipped_not_failed` | status=skipped_locked |
| PASS | `no_wallet_rows_created_by_safety_scenarios` | ledger rows 0→0 |
| PASS | `no_post_run_rows_created_by_refused_scenarios` | mining_daily_runs 0→0 |
| PASS | `d1_first_run_completed` | status=completed |
| PASS | `d1_first_credited_count` | credited=4 expected=4 |
| PASS | `d1_second_run_zero_new_credits` | credited=0 duplicates=4 |
| PASS | `d1_manual_third_run_zero_new_credits` | credited=0 |
| PASS | `d1_ledger_rows_unchanged_after_repeat` | rows 4→4 sum 0.44050000→0.44050000 |
| PASS | `wallet_balances_unchanged_after_repeat` | wallet snapshot identical after 2 repeat runs |
| PASS | `d2_first_credited_count` | credited=5 expected=5 |
| PASS | `d2_second_run_zero_new_credits` | credited=0 |
| PASS | `expected_equals_actual_all_members` | 7 members compared |
| PASS | `zero_duplicate_credits_per_member_per_date` | duplicate groups=0 |
| PASS | `ledger_sum_equals_wallet_balance_for_every_member` | mismatches=0 |
| PASS | `only_synthetic_members_have_wallets` | synthetic=5 total=5 |
| PASS | `ownership_ledger_untouched_by_mining_post` | member_coin_balances identical before/after posting |
| PASS | `reconcile_d1_zero_mismatches` | mismatches=0 |
| PASS | `reconcile_d2_zero_mismatches` | mismatches=0 |
| PASS | `run_history_persisted` | post runs recorded=5 (expected 5 real executions) |
| PASS | `scheduler_log_written` | lines=12 |
| PASS | `kill_switches_still_off_after_run` | {"miningPostingEnabled":false,"miningP2pEnabled":false,"commissionPostingEnabled":false,"commissionWithdrawalEnabled":false,"commissionBlockchainPayoutEnabled":false,"coinSellingEnabled":false,"sandbox_window_open":false} |
| PASS | `live_database_untouched` | coin_platform mining/ownership counts+sums identical before and after |
