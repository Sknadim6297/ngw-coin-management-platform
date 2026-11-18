# Phase 14 — NGW Production Activation Runbook (MINING & LEVEL INCOME)

**Status:** STAGING REHEARSAL PASSED — **PRODUCTION NOT ACTIVATED (STOPPED BEFORE LIVE EXECUTION)**
**Website:** `https://ngw.zodianwealth.info`
**Timezone:** `Asia/Kolkata`

Do **not** enable all financial switches simultaneously. Do **not** activate withdrawals (`commissionWithdrawalEnabled`), blockchain payouts (`commissionBlockchainPayoutEnabled`), P2P (`miningP2pEnabled`), or coin selling (`coinSellingEnabled`) as part of mining/commission recovery.

---

## Preconditions (MANDATORY BEFORE ANY PRODUCTION STEP)

1. **Real Production Access & Deployment Hardening (Phase 1):**
   - Apply `deploy/production.env.example` values to live `.env` on `209.42.20.174`:
     - `CI_ENVIRONMENT = production` (disables CodeIgniter Debugbar & error display; reinforced by `app/Config/Filters.php`).
     - `app.baseURL = 'https://ngw.zodianwealth.info/'` (fixes current `https://ngw.in.net/` mismatch).
     - `app.forceGlobalSecureRequests = true`.
     - `platform.liveDatabase = ng_database` (or actual production DB name).
     - Keep **all 8 financial switches = `false`**.
   - Deploy updated codebase (including `ProductionSafetyGate`, `FinancialSandboxService`, `CommissionPurchaseEngineService`, `CommissionPostingRunnerService`, `MiningRecoveryService`, migration `995001`, and `Filters.php`).
2. **Fresh Production Backup & Verified Restore (Phase 2):**
   - Take fresh `mysqldump --single-transaction --routines --triggers` directly from the live production host DB, verify SHA-256 + completion marker + restore test, store outside `public/`.
3. **Database Migrations (Maintenance Window):**
   - Apply pending migrations (`2026-11-17-993001`, `2026-11-18-994001`, `2026-11-18-995001`). Additive tables only.
4. **Dual-Control Second Admin Account:**
   - Currently `ng_database` has only `user_id = 1` (`ROOT0001`). Create/designate a **second real `finance_approver` account** so creator != approver != activator.
5. **Alert Destination & Scheduler Time:**
   - Owner/ops must specify a monitored email/alert destination and scheduler run window (suggested: `01:30 Asia/Kolkata` daily).
6. **Business Policy Approvals (Phase 3 — Dual Control via Hub + Rate/Plan tables):**
   - **Mining Hub Policy (`financial_policy_versions`):** 3% monthly (`3.00000000`), `calendar_days`, `COIN`, `exclude_approval_day` (following IST business date), `down` rounding, `none` residual, `include` locked, `include_all` post-maturity, `include` reserved, `reserve_inventory_tracked` supply (Note: if `coin_inventory` reserve debiting on mining credits is required in DB rather than cap-tracking, complete that migration before go-live!), `compounding_enabled = false`, `effective_from = 2026-11-18`.
   - **Mining Rate (`mining_rate_configs`):** Create draft `2026-11` @ `3.00000000` (`monthly`) and dual-approve by 2nd admin.
   - **Commission Hub Policy (`financial_policy_versions`):** L1 `10%`, L2 `5%`, L3 `3%`, `approved_purchase_total_usdt`, truncate 8dp, `active_sponsors_only`, `root_eligible = true` (owner confirmed), `effective_from = 2026-11-17`.
   - **Commission Plan (`commission_plans`):** Do **NOT** approve draft `CPL-20261118-D5EEC007` (which has L3 = `2%`). Disable it and create + dual-approve a new **`10% / 5% / 3%`** plan (`effective_from = 2026-11-17`).
   - **Platform Config Flags (`app/Config/Platform.php` or `.env`):** Set all mining/commission policy status flags to `'approved'` ONLY after Hub & rate/plan approvals are recorded.

---

## Stage 1 — Historical Recovery Authorization (SEPARATE EXPLICIT APPROVAL)

### 1A. Level Commission Recovery (`$11.15000000` USDT across 8 approved purchases)
1. Preview superseding revisions (read-only):
   - `php spark commissions:recover-plan --plan <APPROVED_10_5_3_REF>`
2. Generate append-only revisions (only after explicit approval; preserves immutable `NO_APPROVED_PLAN` rows):
   - Requires explicit authorization before persisting revisions (`--persist` in `CommissionsRecoverPlan` currently blocks live DBs; add/approve a live-authorized maintenance command or dual-approved run).
3. Dry-run commission recovery:
   - `php spark commissions:post --plan <APPROVED_10_5_3_REF> --dry-run --strict-exit`
4. Enable **ONLY** `platform.commissionPostingEnabled = true` (keep `commissionAutoPostOnApprovalEnabled = false` until Stage 2).
5. Execute recovery batch:
   - `php spark commissions:post --plan <APPROVED_10_5_3_REF> --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --batch-size 50 --strict-exit`
6. Verify `php spark commissions:reconcile` → `0` mismatches, wallet sum == ledger sum == `$11.15000000` USDT.

### 1B. Mining Historical Recovery (`2026-11-18` IST = `0.70000000` COIN; `2026-11-17` IST = `0.00000000` COIN)
1. Read-only assessment:
   - `php spark mining:recover --date 2026-11-17 --effective-from 2026-11-18` (must confirm 0 owed)
   - `php spark mining:recover --date 2026-11-18 --effective-from 2026-11-18` (must show 6 members / 700 coins / `0.70000000` COIN)
2. Create & dual-approve recovery proposal:
   - Proposer: `php spark mining:recover --date 2026-11-18 --effective-from 2026-11-18 --propose --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --actor <PROPOSER_UID>`
   - Approver (different user): `php spark mining:recover --approve <MRP_REF> --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --actor <APPROVER_UID>`
3. Enable **ONLY** `platform.miningPostingEnabled = true` (keep `miningAutoPostEnabled = false`).
4. Dry-run & execute approved recovery proposal via Phase 13B engine:
   - `php spark mining:post --date 2026-11-18 --dry-run --strict-exit`
   - `php spark mining:recover --execute <MRP_REF> --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --actor <APPROVER_UID>`
5. Re-run `php spark mining:post-health --date 2026-11-18` → exit `0`.

---

## Stage 2 — Commission Purchase-Event Posting Authorization (SEPARATE EXPLICIT APPROVAL)

1. Confirm Stage 1A reconciliation is clean (`0` mismatches).
2. Enable `platform.commissionAutoPostOnApprovalEnabled = true` (or keep `false` and schedule `commissions:post` event worker if batch-only posting is preferred).
3. Approve one controlled live purchase and confirm `commission_purchase_calculations` (`CALCULATED_NOT_POSTED` → `POSTED`) and `commission_wallet_ledger_entries` match.

---

## Stage 3 — Daily Mining Posting Authorization (SEPARATE EXPLICIT APPROVAL)

1. Confirm Stage 1B manual day runs complete cleanly (`COMPLETED`, exit `0`).
2. Run manual end-of-day dry-run & single-day post for yesterday IST (`2026-11-19` IST once `2026-11-18` ends at `18:30 UTC`):
   - `php spark mining:post --date yesterday --dry-run --strict-exit`
   - `php spark mining:post --date yesterday --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --strict-exit`

---

## Stage 4 — Scheduler Activation Authorization (SEPARATE EXPLICIT APPROVAL)

1. Enable `platform.miningAutoPostEnabled = true`.
2. Install production cron job (after verified PHP CLI path on server, e.g. `01:30 IST` = `20:00 UTC` previous day):
   - Daily posting: `/usr/bin/php /path/to/app/spark mining:post --scheduled --strict-exit --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE`
   - Missed-run watchdog (e.g. `02:15 IST`): `/usr/bin/php /path/to/app/spark mining:post-health --date yesterday`
3. Emergency stop procedure: set `platform.miningAutoPostEnabled = false`, `platform.miningPostingEnabled = false`, `platform.commissionPostingEnabled = false`, `platform.commissionAutoPostOnApprovalEnabled = false` in `.env` immediately.
