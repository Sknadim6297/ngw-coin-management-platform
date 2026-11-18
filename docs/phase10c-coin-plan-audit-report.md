# Phase 10C — Coin Plan Business Rule Audit Report

Read-only audit + safe corrections. Kill-switches remain OFF. No deploy. No real wallet credits.

Commands:
- `php spark coinplan:audit`
- `php spark mining:dry-run [--date YYYY-MM-DD] [--limit 50]`

---

## Business-rule matrix

| Rule | Client Requirement | Current Implementation | Status | Missing Work | Evidence |
|------|--------------------|------------------------|--------|--------------|----------|
| 1 | Joining is FREE | RegistrationService; no fee; inactive profile | MATCHED | None | `RegistrationService::register` |
| 2 | Active after min $5 purchase | `coin_settings.min_purchase_usdt=5`; activate on purchase approve only | MATCHED | Keep prod min=5; gate deposit verify ON | `CoinPriceService`, `CoinPurchaseService::approveRequest` |
| 3 | Dynamic daily mining | Admin rates + simulate/dry-run; **no real posting** | PARTIALLY MATCHED | Mining wallet + posting engine + scheduler + formula confirmation | `MiningSimulationService`, `mining:dry-run` |
| 4 | USDT QR + TX + Admin validation | BEP20 details, TX, evidence, manual approve, allocation | MATCHED | Keep `requireVerifiedDepositForApproval=true` | Payment Details + Deposit + Purchase services |
| 5 | 1000-day lock; sell after maturity | Voluntary locking only; selling Coming Soon | NOT IMPLEMENTED | Auto-lock on approval; sell workflow | `CoinLockingService`; Placeholder `coin-selling` |
| 6 | L1 10% / L2 5% / L3 3% dynamic | Configurable plans + simulation; posting OFF | PARTIALLY MATCHED | Approved default plan; purchase-time posting when authorized | `CommissionPlanService`, kill-switch |
| 7 | Separate mining wallet; P2P only | No mining wallet; P2P Coming Soon | NOT IMPLEMENTED | Mining ledger + P2P + abuse controls | No `MiningWallet*`; Placeholder `p2p` |
| 8 | Commission-only USDT withdrawal + Admin evidence | Commission wallet exists; submit/pay blocked | PARTIALLY MATCHED | Full payout workflow with TX evidence | `CommissionWithdrawalService` |

Additional: Asia/Kolkata business dates — `appTimezone` currently UTC → **PARTIALLY MATCHED**.

---

## A. Daily mining report

- Real daily income generated? **NO**
- Scheduler operational? **UNVERIFIED** (no in-repo cron/Tasks; local inspection cannot prove production cron)
- Rate dynamic? **YES** (Admin draft/approve rates)
- Separate mining wallet? **NO**
- Real credits posted? **NO** — *DAILY MINING IS NOT POSTING REAL INCOME.*
- Duplicate prevention (sim runs)? Simulation idempotent by date; N/A for real credits
- P2P functional? **NO**

**Business decision required:** exact daily % / base (available vs locked vs total owned). Code uses **available_balance only** today — conflicts with mandatory 1000-day lock of purchased coins.

## B. Level commission report

- Rates 10/5/3? **Configurable**; UI defaults now 10/5/3; **not auto-seeded/approved**
- Rates dynamic? **YES**
- Purchase-triggered? Simulation yes; daily re-simulation of same purchase is possible as preview history — must not become real daily credits
- Actual commission posted? **NO**
- Commission wallet credited? **NO** (kill-switch)
- Duplicate commissions prevented? Proposal/idempotency architecture exists; posting not executable

Illustrative base 100 USDT: L1=10, L2=5, L3=3 (simulation formula).

## C. Withdrawal report

- Commission-only withdrawal functional? **NO** (disabled)
- Mining withdrawal blocked? **N/A** (no mining wallet); UI blocks WD
- Manual Admin payout implemented? **Architecture only**
- Payment evidence recorded? **Incoming deposits yes; outgoing payout evidence NO**
- Real payout enabled? **NO**

## D. Coin locking report

- Auto-locked 1000 days on purchase? **NO**
- Sell after maturity? **NO** (Coming Soon)
- Early selling blocked? **N/A** (no sell); early unlock blocked by plan `allow_early_unlock=0`

## E. Gap analysis

**Fully implemented:** free join; min $5 gate; activation on approve; USDT payment validation path.

**Partial:** dynamic mining config; commission plan/sim; commission wallet/WD architecture.

**Missing:** mining wallet; mining posting; P2P; auto 1000-day lock; coin selling; WD payout evidence path; Asia/Kolkata default.

**Incorrect / mismatch risks:** available-only mining eligibility vs locked purchase plan; form previously defaulted lock to 30 days (corrected to 1000 UI default).

**Security:** do not enable kill-switches until engines + dual-control + evidence exist.

## F. Test / safe fixes

**Tests:** `Phase10CCoinPlanAuditTest` — 11/11 pass.

**Safe fixes made:**
- `CoinPlanAuditService` + `coinplan:audit` / `mining:dry-run`
- Lock plan form default 1000 days + admin gap banner
- Commission plan form defaults 10/5/3
- `Platform::$coinPlanLockDays=1000`
- `env.production.example` Kolkata note

**Not changed:** kill-switches; no auto-lock on purchase; no mining/commission posting.

## FINAL VERDICT

**Does the software currently match ALL 8 client Coin Plan rules?**

### NO

Evidence: Rules 5 and 7 are NOT IMPLEMENTED; Rules 3, 6, and 8 remain PARTIALLY MATCHED with posting/P2P/withdrawals disabled or absent.
