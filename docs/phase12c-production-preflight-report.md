# Phase 12C — Production Readiness & Financial Activation Preflight

**Generated:** 2026-11-17  
**Project:** NGW Coin Management Platform (`C:\xampp\htdocs\coin-platform`)  
**Scope:** Controlled production activation **review** only  
**Mutations:** None to live financial balances, kill-switches, or production deployment  

**Explicit non-actions (honored):**
- Did **not** deploy
- Did **not** enable production financial kill-switches
- Did **not** modify live member balances / create LOCK1000 on production
- Did **not** send real USDT or execute blockchain payouts
- Did **not** seed an approved production commission plan
- Did **not** activate the mining scheduler

Baseline evidence: `writable/phase12c-baseline-raw.json` (read-only MySQL probe of `coin_platform`).  
Focused tests: `writable/phase12c-focused-tests.txt`.

---

## Executive verdict

| # | Question | Answer |
|---|----------|--------|
| 1 | Ready for production **deployment review**? | **YES — with restrictions** (security hardening + business sign-offs still open) |
| 2 | Ready for actual mining posting? | **NO** |
| 3 | Ready for actual commission posting? | **NO** |
| 4 | Ready for P2P transfers? | **NO** |
| 5 | Ready for withdrawals? | **NO** |
| 6 | Ready for coin selling? | **NO** |
| 7 | What remains? | See **L** and **M** below |

**Classification legend:** READY · READY WITH RESTRICTIONS · BLOCKED

---

## A. Production Baseline

**Status: READY WITH RESTRICTIONS**

| Item | Value |
|------|-------|
| Database | `coin_platform` |
| CI_ENVIRONMENT | `development` (not `production`) |
| app.baseURL | `http://localhost:8080/` |
| Migrations applied | 45 |
| Members | 2 — `ROOT0001` (active root), `NGW000001` (active) |
| Purchases | 2 total / **1 approved** / 0 pending |
| Inventory available | 19995 (added 20000, allocated 5) |
| Ownership | available **5** / locked **0** / reserved **0** / total **5** |
| Active locks | **0** |
| Mining wallets / ledger | 0 / 0 |
| Commission accounts / ledger | 1 / 0 (balances 0) |
| P2P / withdrawals / sells | 0 / 0 / 0 |
| USDT deposits | 1 |
| Approved commission plans | **0** |
| Approved mining rates | **0** (1 disabled draft: `MRC-20261117-AA1FAA19`, 1%, basis=`daily`, eligibility=`available_active_only`) |
| Min purchase | 5.00000000 USDT |
| Current price | 1.00000000 USDT |

### Kill-switches (live config — must remain OFF)

| Switch | State |
|--------|-------|
| `miningPostingEnabled` | **false** |
| `miningP2pEnabled` | **false** |
| `commissionPostingEnabled` | **false** |
| `commissionWithdrawalEnabled` | **false** |
| `commissionBlockchainPayoutEnabled` | **false** |
| `coinSellingEnabled` | **false** |
| `mandatoryPurchaseLockEnabled` | **true** (new approvals) |
| `requireVerifiedDepositForApproval` | **true** |
| `blockchain.depositVerifier` | `not_configured` |

`php spark mining:status` confirms posting/P2P/commission/withdrawal/blockchain payout all false; scheduler unverified/inactive; **1** missing mandatory lock in sample.

---

## B. Member ID Migration Plan

**Status: BLOCKED** (awaiting explicit approval — **not applied**)

### Current production

| Setting | Value |
|---------|-------|
| Prefix | `NGW` |
| Start number | **981234** |
| Padding | **6** |
| Sequence row | `member_id_sequences.prefix=NGW`, `last_number=**981233**` |
| Existing IDs | `ROOT0001`, `NGW000001` (unchanged forever) |
| Collision `NGW1234` | **none** |
| Collision `NGW981234` | **none** |
| Next ID if unchanged | **NGW981234** |

### Target (business request)

| Setting | Value |
|---------|-------|
| Start number | **1234** |
| Padding | **4** |
| Expected next IDs | NGW1234, NGW1235, NGW1236, NGW1237… |

### Critical finding

Changing **only** `.env` `platform.memberIdStartNumber=1234` / `memberIdPadding=4` is **insufficient**.

Generator floor is:

`max(sequence.last_number, max_existing_NGW_numeric, startNumber - 1) + 1`

With `last_number=981233`, next remains **981234** even if start becomes 1234.

### Safe change plan (preview only — do not execute without approval)

1. Maintenance window; no concurrent registrations.
2. Full DB backup + restore verification.
3. Update `.env`:
   - `platform.memberIdStartNumber = 1234`
   - `platform.memberIdPadding = 4`
4. Update `Config\Platform` defaults to match (or rely on env).
5. **Sequence reset** (authorized SQL only):
   - `UPDATE member_id_sequences SET last_number = 1233 WHERE prefix = 'NGW';`
   - Verify no row with `member_id = 'NGW1234'` exists (confirmed absent).
6. Smoke: `MemberIdGenerator::previewNext()` → `NGW1234`.
7. Register one controlled synthetic account on **staging**, not production, first.
8. Update tests that hard-assert `NGW981234` (`tests/feature/MemberIdGenerationTest.php`).
9. Audit log the authorization ticket; do **not** rewrite `NGW000001` / `ROOT0001`.

### Collision risk

| Risk | Assessment |
|------|------------|
| Rewrite existing IDs | None — generator never rewrites |
| `NGW1234` collision | None today |
| Numeric max from `NGW000001` | Numeric `1` — does not block 1234 once sequence ≤ 1233 |
| Padding change | Affects **new** IDs only; existing 6-digit ID remains valid |

**Staging already validated** NGW1234–1237 on `coin_platform_phase12b` (Phase 12B).

---

## C. Historical LOCK1000 Remediation Preview

**Status: BLOCKED** (preview only — **lock not created**)

### Target

| Field | Value |
|-------|-------|
| Purchase | `PUR-10D7174B7FF83054` |
| Member | `NGW000001` (user_id 5) |
| Status | `approved` |
| Quantity | `5.00000000` |
| Reviewed at | `2026-11-17 06:45:44` |
| Inventory txn | `INV-2DF378D80C93FB22` |
| Ownership txn | `OWN-70336107CE255D07` |
| Deposit | `DEP-01BD1ABDFF57AAEA` (`payment_status=verified`) |
| Existing mandatory lock | **NONE** |
| Missing-lock queue | This purchase only (1 row) |

### Before / after projection

| Balance | Before | After (projected) |
|---------|--------|-------------------|
| available | 5.00000000 | **0.00000000** |
| locked | 0.00000000 | **5.00000000** |
| reserved | 0.00000000 | 0.00000000 |
| total balance | 5.00000000 | **5.00000000** (unchanged) |

| Lock field | Projected |
|------------|-----------|
| plan | LOCK1000 |
| kind | `mandatory_purchase` |
| duration | 1000 days |
| locked_at | `2026-11-17 06:45:44` (approval time) |
| matures_at | `2029-08-13 06:45:44` |
| idempotency_key | `mandatory-lock:PUR-10D7174B7FF83054` |

### Preconditions (all true for preview)

- Purchase approved ✔  
- Sufficient available ✔  
- No existing mandatory lock ✔  
- Ownership credit present ✔  
- Duplicate protection: purchase_ref + idempotency_key ✔  

### Authorized remediation (when separately approved)

```text
CoinLockingService::lockMandatoryForPurchase(
  memberUserId: 5,
  memberId: 'NGW000001',
  quantity: '5.00000000',
  purchaseRef: 'PUR-10D7174B7FF83054',
  actorUserId: <authorized admin>,
  lockedAt: '2026-11-17 06:45:44'
)
```

**Do not run without a signed remediation ticket.** Re-run read-only missing-lock preview after execution.

---

## D. Mining Business Decisions

**Status: BLOCKED**

Formula implemented (dry-run / staging only):

`eligible_total_owned × (monthly_rate/100) ÷ month_day_divisor`

| Decision | Staging assumption | Production status |
|----------|--------------------|-------------------|
| Monthly mining % | Explicit staging rate only | **No approved rate** (disabled draft exists; wrong basis/eligibility) |
| Daily divisor | `calendar_days` | DRAFT — needs sign-off vs `fixed_30` |
| Earning asset | `COIN` | DRAFT |
| Approval-day eligibility | `full_day` (includes approval business date) | DRAFT |
| Eligible base | Total owned **including locked** (engine) | Draft rate row says `available_active_only` — **mismatch** |
| Timezone | `Asia/Kolkata` | Confirmed for mining boundaries |
| Maturity / post-sale mining | Pending | `coinSellingPostSaleMiningPolicyStatus=pending_business_decision` |
| Rounding | Engine MoneyMath scale 8 | Needs explicit policy memo |

### Example (illustrative — not an approval)

Assume 5 coins locked+owned, monthly rate **3%**, November (30 days), `calendar_days`, `full_day`:

- Daily = `5 × 0.03 / 30` = **0.005** COIN/day  
- 1000-day lock period illustrative total ≈ **5.0** COIN if rate constant (rates are monthly/dynamic — do not treat as guarantee)

**Do not enable `miningPostingEnabled` until** an **approved** `mining_rate_configs` row matches business policy (monthly %, basis, eligibility including locked coins).

---

## E. Commission Business Decisions

**Status: BLOCKED**

| Decision | Target | Production status |
|----------|--------|-------------------|
| L1 / L2 / L3 | 10% / 5% / 3% | Engine supports; **0 approved plans** in DB |
| Base | Approved purchase USDT total | Pending written sign-off |
| Rounding | Scale 8 | Pending memo |
| Effective dating | Dual-control plan approval | Workflow exists; unused in prod |
| Sponsor eligibility | Snapshot at purchase | Implemented |
| Missing sponsor | Skip level / no credit | Implemented in engine; confirm policy text |
| Dual-control | Plan approval events | Required before posting |

Illustrative on 100 USDT (staging-proven): L1=10, L2=5, L3=3, total=18 USDT.

**Do not seed an approved production plan automatically.**

---

## F. Withdrawal / Selling Decisions

**Status: BLOCKED**

### Withdrawal (commission USDT only)

| Topic | Status |
|-------|--------|
| Minimum withdrawal | `null` — pending |
| Fees | `null` — pending |
| Network | BEP20 only (configured list) |
| Destination verification | Profile USDT + cooldown policy exists |
| Payout confirmation | Manual evidence + mock path; live blockchain payout switch OFF |
| Unposted commission | Excluded by design |
| Mining wallet | Excluded by design |

### Coin selling

| Topic | Status |
|-------|--------|
| Selling price | Pending |
| Counterparty | Pending |
| Settlement asset / method | Pending |
| Fees / minimum qty | Pending |
| Partial selling | Engine supports reservation model; policy pending |
| Post-sale mining eligibility | Pending |
| Execution | `coinSellingEnabled=false` |

Keep both execution paths blocked until policies are signed.

---

## G. Security Audit

**Status: READY WITH RESTRICTIONS**

| Control | Finding | Classification |
|---------|---------|----------------|
| CI_ENVIRONMENT | `development` | Must be `production` before public cutover |
| HTTPS / forceGlobalSecureRequests | **false** | BLOCKED for public prod |
| Cookie Secure | **false** | BLOCKED for public prod |
| Cookie HttpOnly | true | OK |
| CSRF | Enabled globally (except testing) | OK |
| Shield auth | In use (admin/member) | OK |
| Admin permissions | Group/permission checks on financial admin paths | OK |
| Password reauthentication | P2P / withdrawal / selling paths | OK |
| Rate limits | `auth-rates` filter on login/register/sensitive routes | OK |
| Encryption key | Set in `.env` (not reproduced here) | Rotate/confirm for prod host |
| DB credentials | Local root/empty password in this environment | **Not production-grade** |
| Error reporting | Logger threshold 9 in non-production | Set production threshold (4) on cutover |
| Audit logs | `audit_logs` present | OK |
| Support View | Separate cookie path `/support-view`; mutating posts blocked; financial services refuse impersonation context | OK |
| Secrets in report | Redacted | — |

**Admin password and encryption key material intentionally omitted from this document.**

---

## H. Blockchain Verification Readiness

**Status: BLOCKED**

| Item | Finding |
|------|---------|
| Global verifier | `blockchain.depositVerifier = not_configured` |
| RPC URL | Empty |
| BEP20 network row | `network_key=bep20`, chain_id **56**, verifier_type `evm_erc20`, enabled |
| Token contract | **Placeholder text** (`USDT Token Contract Address`) — not a valid address |
| Destination wallet | Appears placeholder-like (redacted in baseline) |
| Confirmations | 15 configured on BEP20 row |
| Transfer event checks | Implemented in `EvmUsdtVerificationProvider` (topic + sum ≥ expected) |
| Duplicate tx | Unique deposit tx hash constraint |
| Purchase link | Exact amount match policy for linking |
| Demo network | Sepolia-like demo disabled/hidden |

**Do not mark unverified transactions paid. Do not send real USDT.**

Activation of `evm_erc20` requires: trusted HTTPS RPC, real USDT contract on BSC, real destination wallet, host allowlist, ops runbook sign-off (`docs/operations/USDT_PAYMENT_OPS_RUNBOOK.md`).

---

## I. Scheduler Readiness

**Status: BLOCKED**

| Item | Status |
|------|--------|
| Command | `php spark mining:daily` — **dry-run only**; refuses `--post/--credit/--execute` |
| Business TZ | Asia/Kolkata |
| Job lock table | `ops_job_locks` exists |
| Idempotency | `mining-daily:{userId}:{businessDate}` |
| Retry / alerts | Financial alert email disabled (`financeNotifications.emailEnabled=false`) |
| Reconciliation | `mining:reconcile`, commission/P2P reconcile commands exist |
| Production cron | **UNVERIFIED** / not activated |

Do not install production cron for posting until mining formula + kill-switch authorization.

---

## J. Backup / Restore Readiness

**Status: BLOCKED**

| Item | Evidence |
|------|----------|
| Backup procedure documented | Yes — ops runbook §8 |
| Successful restore test on this host | **No evidence captured in Phase 12C** |
| File backup (`writable/uploads`) | Procedure exists; not verified here |
| Migration rollback | Additive migrations preferred; financial reverse requires dual-approved remediation |
| Emergency kill-switch | Set Platform flags false / keep verifier `not_configured` |
| Incident response | Runbook freeze + forensic preserve guidance |

**Cannot claim backup readiness without a dated successful restore test.**

---

## K. Focused Test Results

**Status: READY WITH RESTRICTIONS**

### Suite selection (not full PHPUnit)

- Kill-switch enforcement (`Phase9D`, Phase 11A/B/C/D/E control tests)
- Member ID sequence (`MemberIdGenerationTest`)
- Mandatory lock + idempotency (`Phase11A`)
- Commission rate math + posting isolation (`Phase11B`)
- P2P disabled controls (`Phase11C`)
- Withdrawal disabled + Support View block (`Phase11D`)
- Selling disabled (`Phase11E`)
- Support View mutating block (`Phase10D`)

### Preflight fixes applied (non-financial)

1. Migration `2026-11-13-700001` — idempotent add/drop for remediation evidence columns (unblocked `coin_platform_test` refresh).
2. `MiningWalletService::transferP2p` — fail closed on kill-switch **before** receiver lookup.
3. P2P / withdrawal / selling Support View URI guards — apply only when **not** CLI (prevents stale PHPUnit request URI false positives). Active impersonation context checks unchanged.

### Results (completed focused run)

| Metric | Value |
|--------|-------|
| Tests selected | 22 |
| Assertions | 85 |
| Passed | 19 |
| Errors | 2 |
| Failures | 1 |
| Log | `writable/phase12c-focused-tests.txt` (testdox from completed run) |

**Passed (representative):** Member ID NGW981234 sequence + concurrency; Support View mutating POST blocked; mandatory LOCK1000 + idempotency; commission 10/5/3 math; posting blocked; P2P/commission/selling kill-switches remain off; selling disabled server-side; Phase9D financial kill-switches + verified-deposit gate.

**Failed before isolation fixes (documented, not re-run per operator stop):**
- `testMiningPostingP2pAndWithdrawBlocked` — legacy `transferP2p` threw `Receiver not found` before kill-switch (fixed in code).
- `testDisabledP2pBlocksExecution` / `testDisabledWithdrawalsBlockExecution` — stale `support-view` URI from prior HTTP test under CLI (fixed with `! is_cli()` URI guard).

**Operator instruction:** further PHPUnit re-run of the hanging MemberId/Phase9D command was **not** executed.  
**Prior evidence retained:** Phase 12A sandbox + Phase 12B staging E2E both **PASS** on isolated DBs (engines, ledgers, panels).  
**Live attestation:** `php spark mining:status` — all production posting/P2P/withdrawal/blockchain switches **false**.

---

## L. Production Activation Blockers

**Status: BLOCKED**

1. Business approval: Member ID start **1234 / pad 4** including **sequence reset** plan  
2. Authorized LOCK1000 remediation for `PUR-10D7174B7FF83054`  
3. Approved production mining rate (monthly %, divisor, eligibility including locked, asset)  
4. Approved production commission plan 10/5/3 + base/rounding memo  
5. Withdrawal fee / minimum policy  
6. Coin selling price / counterparty / settlement / fee / post-sale mining policy  
7. `CI_ENVIRONMENT=production`, HTTPS, Secure cookies, hardened DB credentials  
8. Real BEP20 USDT contract + destination + trusted RPC; verifier enablement  
9. Proven DB backup **restore** test  
10. Scheduler install only after posting authorization  
11. Keep all financial kill-switches **OFF** until a separate activation phase with dual control  

---

## M. Recommended Activation Sequence

**Status: READY WITH RESTRICTIONS** (sequence only — do not execute now)

1. **Freeze window** + verified backup/restore  
2. Security cutover (HTTPS, secure cookies, env=production, secrets, SMTP)  
3. Blockchain ops enablement (RPC + contract + destination) — still no financial posting  
4. Apply Member ID config + sequence reset (if approved)  
5. Execute LOCK1000 remediation (if approved); reconcile ownership  
6. Seed/approve mining rate + commission plan under dual control  
7. Finalize withdrawal + selling policy numbers  
8. Enable switches **one domain at a time** with monitoring:
   1. `commissionPostingEnabled` (calc already safe; posting on approve)
   2. `miningPostingEnabled` + dry-run compare then limited post day
   3. `miningP2pEnabled`
   4. `commissionWithdrawalEnabled` (manual payout first; blockchain payout last)
   5. `coinSellingEnabled` last  
9. After each step: reconcile commands + audit review  
10. Never enable from staging sandbox gates; production flags only  

---

## Final checklist answers

1. **Deployment review ready?** READY WITH RESTRICTIONS — architecture and staging E2E are strong; public production hardening and business sign-offs remain.  
2. **Mining posting ready?** **NO** — switch OFF, no approved rate, formula assumptions unsigned.  
3. **Commission posting ready?** **NO** — switch OFF, no approved plan in production DB.  
4. **P2P ready?** **NO** — switch OFF.  
5. **Withdrawals ready?** **NO** — switch OFF; fee/minimum pending.  
6. **Coin selling ready?** **NO** — switch OFF; commercial policy pending.  
7. **Remaining:** blockers in **L**; follow sequence **M** after explicit approvals.

---

## Kill-switch attestation

| Switch | Before Phase 12C | After Phase 12C |
|--------|------------------|-----------------|
| miningPostingEnabled | false | **false** |
| miningP2pEnabled | false | **false** |
| commissionPostingEnabled | false | **false** |
| commissionWithdrawalEnabled | false | **false** |
| commissionBlockchainPayoutEnabled | false | **false** |
| coinSellingEnabled | false | **false** |

**DO NOT DEPLOY. DO NOT ENABLE FINANCIAL POSTING. DO NOT MODIFY LIVE BALANCES.**
