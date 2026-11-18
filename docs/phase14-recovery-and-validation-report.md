# Phase 14 — NGW Mining & Level Commission Recovery and Automation Report

Generated: 2026-11-18 (Asia/Kolkata). Staging evidence: `writable/phase14-validation-results.json`.
Status: **NO-GO FOR AUTOMATIC ACTIVATION — READY FOR STAGED, DUAL-CONTROLLED AUTHORIZATION STEPS ONLY**
**STOPPED BEFORE PRODUCTION FINANCIAL ACTIVATION.** No production balance, ledger, policy, switch, or cron was activated.

---

## 1. Actual production database identity & deployment boundary
- **Remote host (`https://ngw.zodianwealth.info`, IP `209.42.20.174`, `LiteSpeed`):** Verified via read-only HTTP `GET` (`/login`, `/admin/login`): remote host currently runs with `CI_ENVIRONMENT = development` (CI Debugbar active) and `app.baseURL = 'https://ngw.in.net/'`. Direct remote SSH / filesystem / cron / remote DB connection is **NOT VERIFIED** from this workstation (no SSH private key or remote DB credentials present).
- **Local imported production database (`localhost:3306` → `ng_database`):** 73 tables, 45 migrations applied (`2026-11-17-992001` latest), 10 users (`ROOT0001`, inactive `NGW000001`, 8 active members), 11 purchases (`8` approved = `$65.00` / `1,300` coins @ `0.05` USDT), `8` active `LOCK1000` locks (`1,300` locked, `0` missing), `0` mining ledger rows, `0` commission ledger rows.
- **Hardening prepared (not deployed remotely):**
  - `app/Config/Filters.php` disables `'toolbar'` when `ENVIRONMENT === 'production'`.
  - `app/Services/DeploymentPreflightService.php` checks for legacy `ngw.in.net` and non-local `CI_DEBUG=true`.
  - `deploy/production.env.example` provides a reversible production `.env` template (`CI_ENVIRONMENT=production`, `app.baseURL='https://ngw.zodianwealth.info/'`, `app.forceGlobalSecureRequests=true`, all 8 switches `false`).
  - `ProductionSafetyGate`, `FinancialSandboxService`, and `MiningPostingRunnerService` updated so **`ng_database`** (and configurable `platform.liveDatabase`) is protected as a live DB instead of failing classification.

## 2. Exact reason mining did not generate
1. `miningPostingEnabled = false` and `miningAutoPostEnabled = false` (`Config\Platform`).
2. `0` rows in `ng_database.mining_rate_configs` (no approved November 2026 rate).
3. All `12` mining business policies remain `PENDING_BUSINESS_APPROVAL` (Hub migration `993001` unapplied).
4. Phase 13B batch/resume tables (`994001`) absent in `ng_database`.
5. `ProductionSafetyGate::LIVE_DATABASE` hardcoded `'coin_platform'`, rejecting `'ng_database'`.
6. No scheduler installed locally (remote `NOT VERIFIED`) and no alert destination configured.
7. Single admin (`user_id = 1`, `ROOT0001`) blocks dual-control approvals until a second real `finance_approver` exists.

## 3. Exact reason commissions did not generate
1. `commissionPostingEnabled = false` (`commissionAutoPostOnApprovalEnabled = false` added fail-closed).
2. Existing plan `CPL-20261118-D5EEC007` in `ng_database` is **`draft`** (`approved_by = NULL`) and has **`L3 = 2%`** (not `3%`), so all 24 `commission_purchase_calculations` rows failed with `NO_APPROVED_PLAN`.
3. `CoinPurchaseService::approveRequest()` only called `onPurchaseApproved()` (`calculateForPurchase()`), never `postPurchase()` — no production credit trigger existed.
4. `commission_purchase_calculations` has `UNIQUE (purchase_ref, sponsor_level)` and immutable `NO_APPROVED_PLAN` rows.
5. `CommissionPurchaseEngineService::postedAmountForLine()` had a lookup bug (`commission:` prefix instead of `comm-post:` / `:L{level}` reference id).
6. Single admin (`user_id = 1`) blocks dual-control plan approval.

## 4. Approved vs pending business rules
- **Production (`ng_database` / `Config\Platform`):** **0 approved** (all 12 mining policies + commission policies remain pending/unapproved; all 8 switches `false`).
- **Rehearsed & dual-approved on isolated clone (`ng_database_phase14`) ONLY:**
  - Mining (`FPV-MIN-20261118-7B1771`, rate `MRC-20261118-1A4F2EBF`): 3% monthly (`3.00000000`), `calendar_days`, `COIN`, `exclude_approval_day` (following IST business date), `down` rounding, `none` residual, locked/post-maturity/reserved included, `reserve_inventory_tracked`, `compounding = false`, effective `2026-11-18` IST.
  - Commission (`FPV-COM-20261118-230CDC`, plan `CPL-20261118-3259AE55`): L1 `10%`, L2 `5%`, L3 `3%`, base `approved_purchase_total_usdt`, truncate 8dp, `active_sponsors_only`, `ROOT0001` eligible, effective `2026-11-17`. Unapproved `10/5/2` draft `CPL-20261118-D5EEC007` left `draft`.

## 5. Eligible historical credits (verified on `ng_database` / `ng_database_phase14_snapshot`)
- **Mining `2026-11-17` IST:** Under `exclude_approval_day` (following IST business date), **0 members eligible** (`0.00000000` COIN owed; first 6 purchases were approved *during* `2026-11-17` IST). Backdating before `2026-11-18` explicitly refused.
- **Mining `2026-11-18` IST:** **6 active members (`NGW000002`, `NGW000003`, `NGW981234`, `NGW981235`, `NGW981236`, `NGW981237`) holding `700.00000000` locked coins** are eligible (`NGW981238` 500 coins & `NGW981239` 100 coins were approved `02:55` & `03:09` IST on `2026-11-18`, so they first earn on **`2026-11-19` IST**).
- **Level Commissions (`2026-11-17` effective):** All **8 approved, deposit-verified purchases (`$65.00000000` USDT)** have `CAPTURED` sponsor snapshots: `21` eligible sponsor slots (`ROOT0001` on 4 slots, active members on 17 slots) and `3` unallocated top-of-tree slots.

## 6. Proposed missing mining amounts (clone proposal `MRP-20261118-77CE1292`, 3% / 30 days `down`)
- `2026-11-17` IST: **`0.00000000` COIN**
- `2026-11-18` IST: **`0.70000000` COIN** (`NGW000002` `0.10`, `NGW000003` `0.10`, `NGW981234` `0.10`, `NGW981235` `0.10`, `NGW981236` `0.20`, `NGW981237` `0.10`). Inventory available before = `2,998,700.00000000`.

## 7. Proposed missing commission amounts (10% / 5% / 3%, `ROOT0001` eligible)
- Total owed: **`11.15000000` USDT** across 21 ledger credits (`0.00000000` posted in `ng_database`):
  - `NGW981236`: **`3.25000000` USDT**
  - `NGW981235`: **`2.65000000` USDT**
  - `NGW000003`: **`2.40000000` USDT**
  - `NGW000002`: **`1.30000000` USDT**
  - `ROOT0001`: **`1.05000000` USDT**
  - `NGW981238`: **`0.50000000` USDT**

## 8. Code fixes prepared
- `app/Config/Filters.php`, `app/Services/DeploymentPreflightService.php`, `deploy/production.env.example` (production Debugbar guard + canonical `baseURL` validation & hardening template).
- `app/Services/ProductionSafetyGate.php`, `app/Services/FinancialSandboxService.php`, `app/Services/MiningPostingRunnerService.php`, `app/Config/Database.php`, `app/Libraries/CliArgs.php`, `app/Commands/MiningPost.php`, `app/Commands/MiningPostHealth.php` (protects `ng_database`; adds isolated `phase14` / `phase14_snapshot`).
- `app/Config/Platform.php` (adds fail-closed `commissionAutoPostOnApprovalEnabled = false` + pending policy flags).
- `app/Database/Migrations/2026-11-18-995001_Phase14RecoveryAndAutomationTables.php` (append-only `commission_calculation_revisions`, `commission_post_batches`, `commission_post_items`, `mining_recovery_proposals`, `mining_recovery_proposal_items`).
- `app/Services/CommissionPurchaseEngineService.php` (fixes `postedAmountForLine()` lookup bug, preserves `NO_APPROVED_PLAN`, adds revision-aware `reconcile()`).
- `app/Services/CommissionPostingRunnerService.php` + `app/Services/CoinPurchaseService.php` + `app/Commands/CommissionsRecoverPlan.php` + `app/Commands/CommissionsPost.php` (append-only superseding revisions, controlled batch/event posting with `max_batches`/`max_runtime`/resume/retry/idempotency/reconciliation).
- `app/Services/MiningRecoveryService.php` + `app/Commands/MiningRecover.php` (dual-control recovery proposals executed via Phase 13B `MiningPostingRunnerService`).
- `app/Services/Phase14VerificationService.php` + `app/Commands/Phase14Verify.php` + `tests/feature/Phase14RecoveryAndAutomationTest.php`.

## 9. Tests, backup/restore & reconciliation results
- **Fresh backup of `ng_database`:** `writable/phase14-private/ng_database-full-20261118-104142.sql` (`227,685` bytes, 73 tables, completion marker present, SHA-256 `fd7ee9fde83885aad5e3434bb7be69711affc24c9667fbe418853d26f57d9c8b`). Restored identically into `ng_database_phase14_snapshot` & `ng_database_phase14` (`CHECKSUM TABLE` 73/73 identical; `CHECK TABLE QUICK` OK).
- **`php spark phase14:verify`:** 26/26 PASS (68.97 s; all dual-control refusals, mining `0.70000000` COIN + duplicate 0 + no compounding, commission 24 append-only revisions keeping 24 `NO_APPROVED_PLAN` rows intact, interrupted batch 10 -> resumed +11 -> duplicate 0 -> `$11.15000000` reconciled, future purchase `$0.90` gated & posted, live switches SKIPPED 20 / BLOCKED 30, snapshot pristine, `ng_database` & `coin_platform` unchanged, temporary RO user dropped).
- **PHPUnit (executed):** `Phase14RecoveryAndAutomationTest.php` passed **6 tests, 49 assertions, 0 failures**. Regression batch (`Phase11BCommissionEngineTest`, `Phase13CMiningPolicyReadinessTest`, `Phase13C1MiningProposalTest`, `Phase14RecoveryAndAutomationTest`) launched concurrently — check `writable/p14-regress.log` for final status; full PHPUnit suite was NOT run.

## 10. Scheduler readiness
- Phase 13B resumable engine verified (`mining:post --group phase14 --date 2026-11-18` exit `0`, duplicate exit `0` / `0` new credits; future date exit `30`; live `--scheduled` exit `20` `SKIPPED`).
- Local machine has no cron/Task Scheduler installed; remote server cron/PHP path on `209.42.20.174` remains `NOT VERIFIED`.

## 11. Production blockers
1. Remote server access (`209.42.20.174`) needed to verify/apply `.env` fix (Debugbar + `baseURL`), pull fresh server dump, apply migrations (`993001`, `994001`, `995001`), and verify PHP CLI path/cron.
2. Creation of a second real `finance_approver` account (self-approval blocks `user_id = 1` today).
3. Formal owner dual-control approvals on Hub policies, `3%` Nov 2026 mining rate, and `10/5/3` commission plan (disabling `CPL-20261118-D5EEC007`), plus decision on whether `coin_inventory` needs explicit debiting on mining credits or cap-only tracking.
4. Alert destination configuration (real email/channel).
5. Explicit authorization to persist `commission_calculation_revisions` on live and toggle switches sequentially per `docs/phase14-production-activation-runbook.md`.

## 12. Commands requiring separate approval
Listed step-by-step in `docs/phase14-production-activation-runbook.md` (Stages 1A, 1B, 2, 3, 4).

## 13. Final GO / NO-GO recommendation
- **NO-GO for automatic production activation right now.**
- **GO for controlled production preparation** once remote server access is provided, 2nd approver is created, `.env`/migrations are applied, and Stage 1–4 authorizations are signed sequentially.
- **Note:** **Zero production income has been generated in `ng_database` or `https://ngw.zodianwealth.info`.** All recovered credits (`0.70000000` COIN and `$11.15000000` USDT) occurred strictly on the isolated clone `ng_database_phase14`.
