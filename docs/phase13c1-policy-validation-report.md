# Phase 13C.1 — Policy Validation Report

Date: 2026-11-18 (Asia/Kolkata). Evidence file: `writable/phase13c1-validation-results.json`
(command `php spark mining:phase13c1-verify`, 23 checks, 23 pass, 0 fail, ~18 s, final run after all code changes).

## Method and isolation

* Live `coin_platform` was touched only through a temporary random-password SELECT-only MariaDB account (grants verified,
  account dropped afterwards: `live_ro_account_dropped = true`). Live fingerprint (73 tables, 2 members, 1 approved
  purchase, owned 5, mining wallet 0, mining ledger rows 0, commission ledger rows 0, switches) was captured before and
  after: **identical**.
* All mutations ran on the marker-guarded, allowlisted clone `coin_platform_phase13c` (restored from a SELECT-only
  snapshot DB, migrated there). Synthetic cohort of 11 members with raw-SQL ledger chains. No external transfer, no real
  balance credited, no production cron, `.env` untouched.
* Configuration group `phase13c` posting used `mining:post --group phase13c --confirm APPLY_STAGING --strict-exit`.
* Working policy fixture for simulation: `exclude_approval_day` / `calendar_days` / `half_up` / include reserved /
  `include_all`. **`half_up`, include and `include_all` are fixtures, not proposals.**

## J. PHPUnit results (actually executed)

| Run | Files | Result |
|-----|-------|--------|
| Focused (new) | `tests/feature/Phase13C1MiningProposalTest.php` | **11 tests, 198 assertions, 0 failures** (run 3 times, repeatable) |
| Regression (worksheet changed) | `Phase13C1MiningProposalTest` + `Phase13CMiningPolicyReadinessTest` | **30 tests, 344 assertions, 0 failures/errors**; 1 runner warning: no code-coverage driver |

* The **full PHPUnit suite was NOT run** and is not claimed.
* `Phase12EFinancialPolicyTest` was not re-run: `FinancialPolicyService` was not modified this phase.
* Two issues were found by the new tests and fixed (both in the test file): the PHPUnit database is intentionally not
  allowlisted, so the draft test creates the same drafts through the Hub directly (the allowlist refusal is itself tested
  and the real method ran on the clone); and leftover test users are now cleaned in `setUp`.

New tests: four proposals map to existing ids/enums; owner labels and bad numbers are rejected as canonical values;
every month's actual calendar days (Feb 28/29); economic preview exact, compounding only hypothetical; no float arithmetic
in the proposal service; following-business-date rule at boundaries; rule agrees with the engine cutoff; Hub drafts stay
unapproved and the worksheet stays pending; draft creation refused on the live group; an approved record carrying an owner
label is rejected by the worksheet (`POLICY_VALUE_INVALID`); all seven switches off and production posting refused.

## Verification of the requested points (§11)

| Requirement | Result |
|-------------|--------|
| Four proposals represented | Yes — 4 Hub drafts on the clone, state `PROPOSED_NOT_APPROVED` |
| All four unapproved | Yes — approved/active = 0 before and after |
| No production rate activated | Yes — live still has one disabled 1 % rate; synthetic rates only on the clone |
| Existing approved policies not overwritten | Yes — none exist; live Hub tables absent |
| Unresolved decisions stay pending | Yes — 12/12 pending live; 12/12 pending on the clone after drafts |
| Previews mathematically correct | Yes — independent integer HALF_UP math equals engine for every eligible member/date |
| Asset types not mixed | Yes — wallet assets `["COIN"]` |
| Historical ownership unchanged | Yes — ownership ledger/balances identical before/after posting |
| Production posting blocked | Yes — switches false; verifier refuses live group; test covers it |
| All seven switches OFF | Yes (`kill_switches_all_false`) |

## Calendar days (Asia/Kolkata business month)

13 months checked, including Feb 2027 = 28 (non-leap) and Feb 2028 = 29 (leap). Engine divisor equals the calendar
days on both the first and last day of each month. Example daily credit for 1,000 coins at 3 % HALF_UP: 31-day
0.96774194, 30-day 1.00000000, 29-day 1.03448276, 28-day 1.07142857.

## Following-business-date rule (9 timestamp cases, all as expected)

| Case (UTC → IST) | Approval business date | First earning date |
|------------------|-----------------------|--------------------|
| 2026-11-17 06:45 UTC (12:15 IST) | 2026-11-17 | 2026-11-18 |
| 2026-11-16 18:31 UTC (00:01 IST on 17th) | 2026-11-17 | 2026-11-18 |
| 2026-11-17 18:29 UTC (23:59 IST) | 2026-11-17 | 2026-11-18 |
| 2026-11-17 18:30 UTC (00:00 IST on 18th) | 2026-11-18 | 2026-11-19 |
| 2026-11-17 19:00 UTC (00:30 IST on 18th) | 2026-11-18 | 2026-11-19 |
| 2026-10-31 18:29 UTC (month end 23:59 IST) | 2026-10-31 | 2026-11-01 |
| 2025-12-31 18:29 UTC (year end) | 2025-12-31 | 2026-01-01 |
| missing timestamp | — | `BLOCKED_MISSING_APPROVAL_TIMESTAMP` |
| future-dated (2027-01-15) | 2027-01-15 | `FUTURE_DATED_APPROVAL` (nothing accrues) |

Engine cross-check on the clone (11 synthetic cases × 3 dates, real `MiningEligibleOwnershipSnapshotService`): constant
holding, multiple purchases, locked, after-midnight approval, partial sale, full sale, pending sale reservation, inactive
member, missing approval timestamp (`BLOCKED_HISTORICAL_DATA`), future-dated approval, matured lock — all as expected.
Historical approvals were not modified.

## §10 Simulation results (isolated clone, synthetic data only)

| Scenario | Outcome |
|----------|---------|
| Constant ownership, multiple purchases, locked, partial sale, pending reservation, matured lock | Credits equal independent math (e.g. 1,000 coins, Oct, 31 days: 0.96774194) |
| Full sale / after-midnight approval / future-dated approval | No credit |
| Missing approval timestamp | Member BLOCKED, no credit; run becomes PARTIAL (exit 40) |
| Inactive member | SKIPPED, no credit (policy gap G4) |
| Month boundary + rate change (2026-10 = 3 %, 2026-11 = 4 % synthetic) | Run for 2026-11-01 used 4 % and 30-day divisor; credited 7 members, total 8.26666665 |
| Leap year | Covered by calendar-day checks (Feb 2028 = 29) |
| Duplicate posting | Re-run of 2026-10-31 credited 0 new rows; duplicate groups = 0 |
| Backdated posting (2026-10-30 after 2026-10-31) | Credited 6, total 5.12903227, correct |
| Mining wallet transfers (P2P) | Not enabled; architecturally verified that eligibility ignores the Mining Wallet |
| Historical correction | Not executed (no correction method exists — gap G6) |
| Wallet total vs ledger | 18.91182797 = 18.91182797 |
| Self-approval of rate | Refused for both rates |

Run totals: 2026-10-31 posted 5.51612905 (6 credited, 5 skipped, 1 blocked, PARTIAL); 2026-10-30 posted 5.12903227;
2026-11-01 posted 8.26666665.

## K. Production safety verification

* `coin_platform`: no write, no migration, no change to wallets/ledgers/purchases/locks/inventory/member IDs (fingerprint equal).
* All seven switches FALSE (`miningPostingEnabled`, `miningAutoPostEnabled`, `miningP2pEnabled`,
  `commissionPostingEnabled`, `commissionWithdrawalEnabled`, `commissionBlockchainPayoutEnabled`, `coinSellingEnabled`).
* Production `.env` not modified; no cron created; no deploy; no LOCK1000 remediation run; no secret printed or stored.
* Code changes were limited to proposal/verification services, one CLI command, one worksheet validation, one test file,
  and visibility changes (`private`→`protected`) in the Phase 13C verifier. **Posting logic was not altered** (no defect found).

## L. Blockers before Phase 13D

1. **CRITICAL — reward supply source (G1) unanswered**; no supply cap or inventory debit for mining.
2. Eight decisions have no owner value; four proposed values are unapproved.
3. Effective dates (rate month, approval-day rule start) not supplied.
4. Hub migration 993001 not applied in production → no production drafts or immutable approval history yet.
5. Real proposer/approver accounts and the approval action have not happened.
6. Issuance budget cap (G7) and mined-COIN treatment (G3, G9) undefined; sustainability unproven.
7. Alert destination and scheduler time undefined; no production cron by design.
8. Policy for one-bad-member PARTIAL days (G5), inactive members (G4), historical correction (G6).
9. Live inventory record is unverified (20,000 / 19,995 / 5).
10. Full PHPUnit suite not executed in this phase.

## Verdict

**APPROVAL_PACKAGE_PARTIALLY_READY** — the four proposals are documented, mapped to existing ids/enums, previewed and
verified on an isolated clone, but remain unapproved; 8 decisions and a CRITICAL supply-source question are open.
This is **not** PRODUCTION_READY. Stopped here; Phase 13D not started.
