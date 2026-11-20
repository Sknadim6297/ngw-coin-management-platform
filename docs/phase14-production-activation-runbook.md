# NGW Production Activation Runbook (wwwzeont_ngw_test_db)

**Status:** LOCAL CODE & TESTS READY — **PRODUCTION NOT ACTIVATED (STOPPED BEFORE LIVE EXECUTION)**
**Website:** `https://ngw.zodianwealth.info`
**Server Path:** `/home/wwwzeont/ngw.zodianwealth.info`
**Production Database:** `wwwzeont_ngw_test_db` *(contains "test" in its name, classified **LIVE** & fail-closed by `ProductionSafetyGate`)*
**Baseline Live Commit:** `9822372`
**Business Timezone:** `Asia/Kolkata`

**All 8 financial switches remain `false` in code until explicit phased authorization:**
- `miningPostingEnabled`, `miningAutoPostEnabled`, `miningP2pEnabled`
- `commissionPostingEnabled`, `commissionAutoPostOnApprovalEnabled`, `commissionWithdrawalEnabled`, `commissionBlockchainPayoutEnabled`, `coinSellingEnabled`

Do **not** enable withdrawals, P2P, coin selling, or blockchain payouts during mining/commission activation.

---

## Owner-Approved Mining Formula & Policy Baseline

| Rule | Owner-Approved Value | Engine Enum / Config | Status on Live DB |
|---|---|---|---|
| Monthly mining rate | `3%` (`3.00000000`) | `mining_rate_configs` (`rate_basis = monthly`) | **PENDING** (create draft & dual-approve) |
| Daily divisor | Actual calendar days (`28/29/30/31`) | `calendar_days` (`Platform::$miningMonthDayDivisorMode`) | Config default aligned; Hub record **PENDING** |
| Compounding | None (`false`) | Mining Wallet only; principal comes strictly from `member_coin_ownership_transactions` | Enforced by architecture; Hub record **PENDING** |
| Approval-day rule | Approval day excluded (`exclude_approval_day`) | `exclude_approval_day` (`Platform::$miningApprovalDayPolicy`) | Config default aligned; Hub record **PENDING** |
| First eligible mining date | Next `Asia/Kolkata` business date after approval | `MiningEligibleOwnershipSnapshotService` (`start_of_day`) & `MiningEligibilityService` | Enforced across engine, sim, dry-run, recovery, scheduler |
| Earning asset | `COIN` | `Platform::$miningEarningAsset = 'COIN'` | Config default aligned; Hub record **PENDING** |
| Business timezone | `Asia/Kolkata` | `Platform::$miningBusinessTimezone = 'Asia/Kolkata'` | Config default aligned |
| Mandatory `LOCK1000` coins | Included in eligible total owned | `total_owned_active` / `locked_coins = include` | Pending formal Hub record |
| Rounding / supply / maturity / post-sale | `down` (8 dp once), `reserve_inventory_tracked`, `include_all`, `include` | `MiningCalculationPolicy` (`MPOL-897706167700`) | Default in `Platform`; confirm & approve via Hub |

---

## Remaining Blockers Before Live Activation

1. **Code deployment:** Push approved local commit (on top of `9822372`) to GitHub `main` and `git pull --ff-only origin main` on live server.
2. **Production `.env` verification (server-only, never commit credentials):** Ensure `CI_ENVIRONMENT = production`, `app.baseURL = 'https://ngw.zodianwealth.info/'`, `app.forceGlobalSecureRequests = true`, `database.default.database = wwwzeont_ngw_test_db`, `platform.liveDatabase = wwwzeont_ngw_test_db`, and all 8 financial switches = `false`.
3. **Verified live DB restore point:** Take fresh `mysqldump --single-transaction --routines --triggers` directly from `wwwzeont_ngw_test_db` before migrations and verify completion marker + SHA-256 + test restore into a temporary/non-live verification schema.
4. **3 pending migrations:** Apply `2026-11-17-993001` (Phase 12E), `2026-11-18-994001` (Phase 13B), and `2026-11-18-995001` (Phase 14) — adds 9 additive tables without altering balances.
5. **Second real `finance_approver` account:** Dual-control requires `creator != approver != activator` (currently only `user_id = 1` / `ROOT0001` exists as admin).
6. **Mandatory `LOCK1000` remediation:** Purchase `PUR-10D7174B7FF83054` (`NGW000001`, `5.00000000 COIN`, approved `2026-11-17 06:45:44 UTC`) + any other approved purchases missing mandatory locks must be previewed and remediated.
7. **Formal dual-control policy & rate/plan approvals:**
   - Mining versioned Hub policy (`financial_policy_versions`), `3%` monthly rate (`mining_rate_configs`), and `Platform` policy status flags (`approved`).
   - Commission Hub policy + new `10% / 5% / 3%` `commission_plans` row (effective date explicitly confirmed, e.g. `2026-11-17`; disable any draft `10/5/2` plan).
   - Confirm rounding (`down`), post-maturity (`include_all`), post-sale (`include`), reward supply source (`reserve_inventory_tracked`), and `ROOT0001` commission eligibility.
8. **Alert destination & verified server PHP CLI binary:** Confirm exact cPanel PHP CLI binary path (e.g. `/usr/local/bin/php`) and monitored alert recipient/channel.

---

## Step 1 — LOCAL PC Commands (Review, Commit & Push Only After Approval)

```powershell
cd C:\xampp\htdocs\coin-platform
git status
git diff --stat
# Run PHP lint & focused automated safety/policy tests locally:
C:\xampp\php\php.exe vendor/bin/phpunit tests/unit/ProductionSafetyGateClassificationTest.php tests/feature/ApprovedMiningPolicyTest.php tests/feature/Phase14RecoveryAndAutomationTest.php tests/feature/Phase13C1MiningProposalTest.php tests/feature/Phase13CMiningPolicyReadinessTest.php --no-coverage

# ONLY after explicit approval:
git add -A
git commit -m "Implement approved exclude_approval_day mining policy, policy draft tooling, and live recovery guards"
git push origin main
```

---

## Step 2 — LIVE SERVER SSH Commands: Pre-Check & Code-Only Pull (Checkpoint 0)

```bash
cd /home/wwwzeont/ngw.zodianwealth.info
git status
git log --oneline -3
git fetch origin
git log --oneline HEAD..origin/main

# ONLY after verifying clean tree & explicit approval:
git pull --ff-only origin main

# Read-only verification (touches no DB rows):
php -r 'define("ENVIRONMENT","production"); require "vendor/autoload.php"; echo App\Services\ProductionSafetyGate::classify("wwwzeont_ngw_test_db"), PHP_EOL;'
php spark mining:prepare-policy-draft --effective-from 2026-11-18
```

Verify `.env` on server manually (never commit `.env` or print passwords):
- `CI_ENVIRONMENT = production`
- `app.baseURL = 'https://ngw.zodianwealth.info/'`
- `app.forceGlobalSecureRequests = true`
- `database.default.database = wwwzeont_ngw_test_db`
- `platform.liveDatabase = wwwzeont_ngw_test_db`
- Keep **all 8 financial switches `false`**.

---

## Step 3 — PHASE A: Verified Backup & 3 Pending Migrations (Checkpoint 1)

1. **Take & verify production backup outside `public/` (must complete BEFORE any migration):**
```bash
mkdir -p /home/wwwzeont/ngw_backups
BACKUP_FILE="/home/wwwzeont/ngw_backups/wwwzeont_ngw_test_db-$(date -u +%Y%m%d-%H%M%S).sql"
mysqldump --single-transaction --routines --triggers -u <DB_USER> -p wwwzeont_ngw_test_db > "$BACKUP_FILE"
tail -n 5 "$BACKUP_FILE"          # Must contain "-- Dump completed on ..."
sha256sum "$BACKUP_FILE" > "$BACKUP_FILE.sha256"
```
Verify test restore into a separate scratch database on server/local before proceeding.

2. **Check migration status (read-only) & apply pending 3 migrations (ONLY after backup + Checkpoint 1 approval):**
```bash
cd /home/wwwzeont/ngw.zodianwealth.info
php spark migrate:status
# Expected pending:
#   2026-11-17-993001  Phase12EFinancialPolicyVersions   (financial_policy_versions, financial_policy_events)
#   2026-11-18-994001  Phase13BMiningPostBatchTables     (mining_post_day_runs, mining_post_members)
#   2026-11-18-995001  Phase14RecoveryAndAutomationTables (commission_calculation_revisions, commission_post_batches, commission_post_items, mining_recovery_proposals, mining_recovery_proposal_items)

php spark migrate
php spark migrate:status
```
Confirm zero balance/ledger tables modified (all 9 tables are strictly additive).

---

## Step 4 — PHASE B: Mandatory `LOCK1000` Remediation (Checkpoint 2)

1. **Read-only discovery of all approved purchases missing mandatory locks (`PUR-10D7174B7FF83054`, etc.):**
```bash
php -r '
require "app/Config/Paths.php";
$db = \Config\Database::connect("default");
$rows = $db->query("SELECT p.purchase_ref, p.member_user_id, p.member_id, p.coin_quantity, p.total_usdt, p.reviewed_at FROM coin_purchase_requests p LEFT JOIN member_coin_locks l ON l.purchase_ref = p.purchase_ref AND l.lock_kind = \"mandatory_purchase\" WHERE p.status = \"approved\" AND l.id IS NULL ORDER BY p.id ASC")->getResultArray();
echo json_encode($rows, JSON_PRETTY_PRINT), PHP_EOL;
'
php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --dry-run
```
Verify for `PUR-10D7174B7FF83054` (`NGW000001`):
- `available_balance`: `5.00000000` -> `0.00000000`
- `locked_balance`: `0.00000000` -> `5.00000000`
- `balance` (total owned): `5.00000000` -> `5.00000000` (unchanged)
- Plan: `LOCK1000`, `duration_days`: `1000`, `locked_at`: `2026-11-17 06:45:44`

2. **Execute lock remediation (ONLY after Checkpoint 2 approval, for each verified missing purchase):**
```bash
php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --actor <ADMIN_UID>
# Re-run with --dry-run to confirm SAFE NO-OP / 0 missing locks remaining.
```

---

## Step 5 — PHASES C & D: Dual-Control Policy & Rate/Plan Approvals (Checkpoint 3)

1. **Create second real `finance_approver` account** (Admin UI -> Roles/Users, distinct from `user_id = 1`).
2. **Prepare versioned Mining policy draft (`status = draft`) & 3% Nov 2026 rate draft (`status = draft`):**
```bash
php spark mining:prepare-policy-draft --effective-from <APPROVED_EFFECTIVE_DATE> --persist --actor <ADMIN_1_UID> --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE
```
3. **Dual-control Mining approvals (Admin Panel, all switches STILL `false`):**
   - Admin 1 (`<ADMIN_1_UID>`) submits the mining Hub draft (`financial_policy_versions`).
   - Admin 2 (`<ADMIN_2_UID> != <ADMIN_1_UID>`) approves the mining Hub policy AND approves the `2026-11` `3.00000000%` monthly `mining_rate_configs` row.
   - Activator (`<ADMIN_1_UID> != <ADMIN_2_UID>`) marks the Hub policy `active` (records documentation readiness only; does NOT flip kill-switches).
4. **Dual-control Commission approvals (Admin Panel):**
   - Disable any draft `10/5/2` plan if present.
   - Admin 1 creates + submits `10% / 5% / 3%` `commission_plans` row (`effective_from = <APPROVED_COMMISSION_EFFECTIVE_DATE>`, `approved_verified_usdt`, `active_sponsors_only`) + `financial_policy_versions` commission draft (`approved_purchase_total_usdt`, truncate 8 dp, `root_eligible` per owner sign-off).
   - Admin 2 approves the commission plan + Hub policy; Admin 1 activates Hub policy.
5. **Update `.env` policy status flags on server to `'approved'` ONLY AFTER Step 5.3 & 5.4 succeed:**
```ini
platform.miningMonthlyPercentStatus = approved
platform.miningDayDivisorStatus = approved
platform.miningDenominationStatus = approved
platform.miningApprovalDayPolicyStatus = approved
platform.miningLockedCoinEligibilityStatus = approved
platform.miningPostMaturityEligibilityStatus = approved
platform.miningPostSaleEligibilityStatus = approved
platform.miningRoundingPolicyStatus = approved
platform.commissionBasePolicyStatus = approved
platform.commissionRoundingPolicyStatus = approved
platform.commissionRootEligibilityPolicyStatus = approved
```
*(Keep all 8 execution kill-switches `false`!)*

---

## Step 6 — PHASE E: Historical Recovery Previews & Authorized Execution (Checkpoint 4)

### 6A. Level Commission Historical Recovery (`PUR-10D7174B7FF83054` & any `NO_APPROVED_PLAN` rows)
1. **Preview append-only superseding revisions (read-only):**
```bash
php spark commissions:recover-plan --plan <APPROVED_10_5_3_PLAN_REF>
# Add --exclude-root if ROOT0001 is excluded by owner sign-off.
```
For `PUR-10D7174B7FF83054` (`$5.00000000`, L1 = `ROOT0001`, L2/L3 missing): expected = `$0.50000000` (if root eligible) or `$0.00` (if root excluded); L2/L3 = `UNALLOCATED`.

2. **Persist append-only revisions & dry-run batch (ONLY after Checkpoint 4 approval):**
```bash
php spark commissions:recover-plan --plan <APPROVED_10_5_3_PLAN_REF> --persist --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --actor <ADMIN_2_UID>
php spark commissions:post --plan <APPROVED_10_5_3_PLAN_REF> --dry-run --strict-exit
```

3. **Enable ONLY `platform.commissionPostingEnabled = true` in `.env` (keep `commissionAutoPostOnApprovalEnabled = false`) and post recovery batch:**
```bash
php spark commissions:post --plan <APPROVED_10_5_3_PLAN_REF> --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --batch-size 50 --strict-exit --actor <ADMIN_2_UID>
php spark commissions:reconcile
```

### 6B. Mining Historical Recovery (`exclude_approval_day`, starting no earlier than approved effective date)
1. **Assess each IST business date from `<APPROVED_EFFECTIVE_DATE>` up to `yesterday` IST (read-only):**
```bash
# Example for 2026-11-17 (approval day for PUR-10D7174B7FF83054 -> must show 0 eligible) and 2026-11-18:
php spark mining:recover --date 2026-11-17 --effective-from <APPROVED_EFFECTIVE_DATE>
php spark mining:recover --date 2026-11-18 --effective-from <APPROVED_EFFECTIVE_DATE>
# For PUR-10D7174B7FF83054 (5.00000000 COIN @ 3% / 30 days): 0.00500000 COIN/day starting 2026-11-18 IST.
```

2. **Create & dual-approve proposal per owed date (ONLY after Checkpoint 4 approval):**
```bash
php spark mining:recover --date <YYYY-MM-DD> --effective-from <APPROVED_EFFECTIVE_DATE> --propose --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --actor <ADMIN_1_UID>
php spark mining:recover --approve <MRP_REF> --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --actor <ADMIN_2_UID>
```

3. **Enable ONLY `platform.miningPostingEnabled = true` in `.env` (keep `miningAutoPostEnabled = false`), dry-run, execute via Phase 13B, and verify:**
```bash
php spark mining:post --date <YYYY-MM-DD> --dry-run --strict-exit
php spark mining:recover --execute <MRP_REF> --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE --actor <ADMIN_2_UID>
php spark mining:post-health --date <YYYY-MM-DD>
```

---

## Step 7 — PHASES F & G: Automation & Cron Scheduler Activation (Checkpoint 5)

**Only after Stage 6 reconciliation shows `0` mismatches and Checkpoint 5 is explicitly approved:**

1. Optionally enable `platform.commissionAutoPostOnApprovalEnabled = true` in `.env` (for future purchase approvals).
2. Enable `platform.miningAutoPostEnabled = true` in `.env`.
3. Verify exact PHP CLI binary on server (`which php` / `php -v` >= 8.2, `php -m` has `intl`, `bcmath`, `pdo_mysql`, `json`, `mbstring`, `openssl`).
4. Install cPanel cron jobs (server runs UTC; `20:00 UTC` = `01:30 Asia/Kolkata` next IST day, `20:45 UTC` = `02:15 Asia/Kolkata`):

```cron
# Daily automated mining posting for previous completed Asia/Kolkata business date (01:30 IST = 20:00 UTC)
0 20 * * * /usr/local/bin/php /home/wwwzeont/ngw.zodianwealth.info/spark mining:post --scheduled --strict-exit --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE >> /home/wwwzeont/ngw.zodianwealth.info/writable/logs/cron-mining-post.log 2>&1

# Watchdog health check (02:15 IST = 20:45 UTC)
45 20 * * * /usr/local/bin/php /home/wwwzeont/ngw.zodianwealth.info/spark mining:post-health --date yesterday >> /home/wwwzeont/ngw.zodianwealth.info/writable/logs/cron-mining-health.log 2>&1
```
*(Remember: CI4 `CliArgs` strictly requires space-separated `--opt value`; never use `--opt=value`.)*

Keep disabled unless separately authorized:
- `platform.miningP2pEnabled = false`
- `platform.commissionWithdrawalEnabled = false`
- `platform.commissionBlockchainPayoutEnabled = false`
- `platform.coinSellingEnabled = false`

---

## Step 8 — Post-Activation Reconciliation Checklist

Run read-only checks after every stage:
- `php spark mining:post-health --date yesterday` -> exit `0`
- `php spark commissions:reconcile` -> `0` mismatches
- Verify SQL invariants on `wwwzeont_ngw_test_db`:
  - `SUM(mining_wallets.available_balance) == SUM(mining_wallet_ledger credit - debit)`
  - Zero duplicate `(member_user_id, business_date)` in `mining_wallet_ledger`
  - `member_coin_balances.balance = available_balance + locked_balance + reserved_balance` (total owned untouched by mining)
  - Zero duplicate `(idempotency_key)` in `commission_wallet_ledger_entries`
  - `SUM(commission_wallet_accounts.available_balance) == SUM(commission_wallet_ledger_entries credit - debit)`

---

## Step 9 — Emergency Stop & Rollback Procedure

1. **Immediate Emergency Stop (< 30 seconds):** Set in `/home/wwwzeont/ngw.zodianwealth.info/.env`:
   ```ini
   platform.miningAutoPostEnabled = false
   platform.miningPostingEnabled = false
   platform.commissionAutoPostOnApprovalEnabled = false
   platform.commissionPostingEnabled = false
   ```
   and comment out/remove the two cPanel cron lines. All live mutation paths halt immediately.
2. **Code Rollback:** `git checkout 9822372` (never roll back earlier than `9822372`, because `9822372` fixes `wwwzeont_ngw_test_db` live safety detection!).
3. **Database Rollback (only before/without partial posted user activity or in maintenance window):** Restore the pre-migration verified backup `$BACKUP_FILE` taken in Step 3.

---

## Step 10 — Financial Automation Control Center (daily ON/OFF operations)

`/admin/financial-automation` replaces hand-edited `.env` switches and the two cron lines of Step 7 for daily operations.
Financial Policies stays as the **Advanced** approval page (drafts, dual-control approval, rates, plans).

**How it is protected**
- Switches (`Daily Coin Mining`, `Level Commission`, `Automatic Scheduler`) are stored server-side in `platform_settings` (`automation_*`). The `.env` kill-switches stay `false`.
- ON is refused unless: database + all tables ready; cron heartbeat < 30 min old; approved & independently-approved Mining policy (3 % monthly, calendar days, approval day excluded, COIN, no compounding, `policy_version` equal to the engine configuration); approved independent 3 % monthly mining rate for the next business date; approved independent Commission policy; approved independent plan L1 10 / L2 5 / L3 3 (USDT); every approved purchase has its mandatory lock.
- ON also needs the admin password and the typed phrase (`I_AUTHORIZE_PRODUCTION_CHANGE` on the live DB). Authorization is bound to the database name, so copying a DB dump to another server does not carry it over.
- Toggling never approves a draft, never creates an approver, never posts. OFF only stops *future* posting; no wallet/ledger row is touched.
- `automation:run` raises the needed kill-switch / policy-status flags **in memory for one job only** (after re-verifying readiness) and restores them in `finally`. Each job is idempotent per business date (`financial_automation_runs`) and the underlying runners are idempotent too. Failures are logged, audited and raised as `financial_alerts` (`automation_job_failed` / `automation_job_blocked`), with 3 attempts/day and a 15-minute back-off.
- Never switched by this feature: P2P, withdrawals, coin selling, blockchain payouts, commission auto-post-on-approval.

**LOCAL PC (review only)**
```powershell
cd C:\xampp\htdocs\coin-platform
php -l app/Services/FinancialAutomationService.php
php vendor/bin/phpunit tests/feature/FinancialAutomationControlCenterTest.php --no-coverage
```

**LIVE SERVER (only after explicit approval; each step is a checkpoint)**
```bash
cd /home/wwwzeont/ngw.zodianwealth.info
git pull origin main                                   # code only
# Backup first (same as Step 3), then the ONE new additive migration (creates financial_automation_runs):
php spark migrate                                      # pending: 993001, 994001, 995001, 996001 (996001 = automation run log)
php spark list | grep automation                       # automation:run must be listed
```
Install the single cron entry (server clock is UTC; the job itself decides the Asia/Kolkata 01:30 run time) and **remove** the two Step 7 `mining:post` / `mining:post-health` lines so nothing posts outside the control center:
```cron
*/10 * * * * /usr/local/bin/php /home/wwwzeont/ngw.zodianwealth.info/spark automation:run >> /home/wwwzeont/ngw.zodianwealth.info/writable/logs/automation-cron.log 2>&1
```
`automation:run` is harmless while everything is OFF: it only writes the heartbeat. Wait ~10 minutes, open `/admin/financial-automation`, and confirm “Server cron: working”.
Then (with the Step 4–6 prerequisites done: LOCK1000 remediation, approved policies/rate/plan, historical recovery decisions) use **Start All Automation**.
Before turning *Level Commission* ON use **Preview pending commissions**: the first run posts every eligible approved purchase that the approved plan covers and has no commission yet.

**Emergency stop:** press **Stop All Automation** (immediate for the next cron tick; a job already running finishes its current safe batch). `.env` flags and cron removal from Step 9 remain the hard stop.
Optional config (`.env`): `platform.automationDailyRunTime = 01:30` (business timezone), `platform.automationHeartbeatMaxAgeSeconds = 1800`, `platform.automationMaxAttemptsPerDay = 3`, `platform.automationRetryBackoffSeconds = 900`.
