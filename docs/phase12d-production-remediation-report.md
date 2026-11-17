# Phase 12D — Production Blocker Remediation & Activation Preparation

**Generated:** 2026-11-17  
**Project:** NGW Coin Management Platform  
**Live database:** `coin_platform` (untouched for balances / sequence / locks)  
**Isolated work DB:** `coin_platform_phase12d`  

**Honored constraints:** no deploy · no live balance mutation · no real USDT · no financial posting · all kill-switches OFF

---

## Classification summary

| Section | Status |
|---------|--------|
| A. Member ID correction readiness | **READY FOR APPROVAL** |
| B. LOCK1000 remediation readiness | **READY FOR APPROVAL** |
| C. Mining policy status | **BLOCKED** |
| D. Commission policy status | **READY FOR APPROVAL** (DRAFT tooling; no approved plan) |
| E. BEP20 verification readiness | **BLOCKED** |
| F. Backup/restore evidence | **PASS** (schema restore to isolated DB) |
| G. Security hardening results | **READY FOR APPROVAL** |
| H. Scheduler readiness | **READY FOR APPROVAL** (not activated) |
| I. Focused tests | **PASS** (isolated) |
| J. Production changes made | **PASS** (none financial) |
| K. Production changes requiring approval | **READY FOR APPROVAL** |
| L. Remaining activation blockers | **BLOCKED** |

---

## A. Member ID correction readiness

**Status: READY FOR APPROVAL**

### Production before (live, unchanged)

| Item | Value |
|------|-------|
| start | 981234 |
| padding | 6 |
| `last_number` | 981233 |
| next preview | NGW981234 |
| existing | ROOT0001, NGW000001 |

### Target after approval

| Item | Value |
|------|-------|
| start | 1234 |
| padding | 4 |
| `last_number` | 1233 |
| next | NGW1234, NGW1235, … |
| preserved | ROOT0001, NGW000001 (never renamed) |

### Tooling

```text
php spark members:correct-sequence --dry-run
php spark members:correct-sequence --group phase12d --confirm APPLY_STAGING
# Production (separate authorization only):
php spark members:correct-sequence --allow-production --confirm="I_AUTHORIZE_PRODUCTION_CHANGE"
```

Also update `.env`:

```env
platform.memberIdStartNumber = 1234
platform.memberIdPadding = 4
```

Changing start alone is **insufficient** — sequence `last_number` must move to 1233.

### Staging evidence

- `coin_platform_phase12b`: already issued through NGW1238 → correction to 1234 correctly **blocked** (`already_past_target`).
- `coin_platform_phase12d`: dry-run ready → apply **PASS** → `verify_next=NGW1234`, `after_last=1233`, NGW000001 preserved.
- Rollback hint recorded in command output.

### Live after Phase 12D

`last_number` still **981233** (not applied).

---

## B. LOCK1000 remediation readiness

**Status: READY FOR APPROVAL**

### Production target (preview only)

| Field | Value |
|-------|-------|
| Purchase | `PUR-10D7174B7FF83054` |
| Member | NGW000001 |
| Before | available 5 / locked 0 / total 5 |
| After (projected) | available 0 / locked 5 / total 5 |
| locked_at | 2026-11-17 06:45:44 |
| matures_at | 2029-08-13 06:45:44 |
| Checks | all true |
| Executed on live? | **NO** |

Live refuse without phrase verified. Wrong confirm with `--allow-production` refused.

### Tooling

```text
php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --dry-run
# Staging:
php spark locking:remediate-mandatory --group phase12d --purchase PUR-... --confirm APPLY_STAGING
# Production (separate authorization only):
php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --allow-production --confirm="I_AUTHORIZE_PRODUCTION_CHANGE"
```

### Isolated execution evidence (`coin_platform_phase12d`)

Synthetic `PUR-P12D-LOCK-TEST001`:

| | Before | After |
|--|--------|-------|
| available | 5 | **0** |
| locked | 0 | **5** |
| total | 5 | **5** |
| lock | — | `LCK-M-800A0F8FA150F663` LOCK1000 active |

Evidence: `writable/phase12d-lock-remediation-result.json`

### Live after Phase 12D

Still available **5** / locked **0** / locks count **0**.

---

## C. Mining policy status

**Status: BLOCKED** (business values unapproved)

Platform now exposes explicit pending statuses (Phase 12D):

- `miningMonthlyPercentStatus`
- `miningDayDivisorStatus`
- `miningDenominationStatus`
- `miningApprovalDayPolicyStatus`
- `miningLockedCoinEligibilityStatus`
- `miningPostMaturityEligibilityStatus`
- `miningPostSaleEligibilityStatus`
- `miningRoundingPolicyStatus`

All default: `pending_business_decision`.

### Draft assumptions (not approvals)

| Topic | Draft code default | Notes |
|-------|--------------------|-------|
| Formula | eligible_total_owned × (monthly%/100) ÷ day_divisor | Engine |
| Day divisor mode | `calendar_days` | vs `fixed_30` |
| Approval day | `full_day` | vs exclude approval day |
| Denomination | `COIN` | |
| Locked coins | Engine policy `total_owned_active` | Prod draft rate row still `available_active_only` — mismatch |
| Rounding | scale 8 | |
| Monthly % | **unset / unapproved** | Do not invent |

### Example (illustrative only)

5 owned+locked coins, **3%/month**, Nov 30 days, calendar divisor:

`5 × 0.03 / 30 = 0.005 COIN/day`

**Do not enable `miningPostingEnabled` until monthly % and policies are signed and an approved rate row exists.**

---

## D. Commission policy status

**Status: READY FOR APPROVAL**

| Item | Value |
|------|-------|
| Target rates | L1 10% / L2 5% / L3 3% |
| Base | Approved purchase USDT total (pending written sign-off) |
| Snapshots | Purchase-time sponsor + rate snapshots implemented |
| Dual-control | `CommissionPlanService::approve` blocks self-approval |
| Production approved plans | **0** |
| Auto-approve / auto-post | **Not done** |

Illustrative 100 USDT → 10 + 5 + 3 = 18 USDT (staging-proven in 12A/12B).

Create DRAFT via admin/API `CommissionPlanService::createDraft` only; dual-control approve is a separate step.

---

## E. BEP20 verification readiness

**Status: BLOCKED**

| Check | Finding |
|-------|---------|
| Chain ID 56 | Present on `bep20` network row |
| Official USDT contract | **Placeholder text** — not a verified contract address |
| Deposit receiving address | Placeholder-like — **operator must supply verified address** |
| RPC | `blockchain.evmRpcUrl` empty; `depositVerifier=not_configured` |
| Receipt + Transfer event checks | Implemented in `EvmUsdtVerificationProvider` |
| Decimals / amount / recipient | Code supports; config incomplete |
| Confirmations | 15 on BEP20 row |
| Duplicate hash | Unique deposit constraint |
| Payouts | `commissionBlockchainPayoutEnabled=false` |

**No funds sent. No guessed contracts/addresses written into production config.**

---

## F. Backup/restore evidence

**Status: PASS** (isolated schema restore)

| Item | Evidence |
|------|----------|
| Source | `mysqldump --no-data coin_platform` → `writable/phase12d-schema.sql` |
| Restore target | **`coin_platform_phase12d` only** (never over live) |
| Table count | **73** |
| Evidence file | `writable/phase12d-restore-evidence.json` |
| Live overwrite | **None** |

Full data dump + timed restore drill on a dedicated host remains recommended before cutover. Schema restore integrity verified here.

---

## G. Security hardening results

**Status: READY FOR APPROVAL**

### Code / template changes (safe)

- `ProductionSafetyGate` — fail-closed live mutation gate
- Support View URI guards already CLI-safe (12C)
- `env.production.example` — documents target Member ID as commented; keeps kill-switches OFF; HTTPS/secure cookie guidance already present
- Platform mining policy status fields — prevent silent “assumed approved” reading

### Live environment still (not changed without approval)

| Item | Current |
|------|---------|
| CI_ENVIRONMENT | development |
| HTTPS / forceGlobalSecureRequests | false |
| cookie.secure | false |
| DB credentials | local root (not prod-grade) |

Apply `env.production.example` values on the real host under separate change control.

---

## H. Scheduler readiness

**Status: READY FOR APPROVAL** (not activated)

| Item | State |
|------|-------|
| `mining:daily` | Dry-run only; refuses post flags |
| Timezone | Asia/Kolkata |
| Job locks | `ops_job_locks` |
| Idempotency | `mining-daily:{user}:{date}` |
| Alerts | email notifications disabled |
| Cron | UNVERIFIED / inactive |

Do not install posting cron until mining policy + kill-switch authorization.

---

## I. Focused tests

**Status: PASS**

| Test | Result |
|------|--------|
| Member ID prod dry-run ready | PASS |
| Member ID phase12d apply → NGW1234 | PASS |
| Member ID phase12b already past target | PASS (correct refuse) |
| LOCK1000 prod dry-run ready | PASS |
| LOCK1000 live refuse without auth phrase | PASS |
| LOCK1000 phase12d execute + balances | PASS |
| Kill-switches remain OFF | PASS |
| Live balances unchanged | PASS |

PHPUnit full suite not re-run (per Phase 12C operator preference). Engine coverage retained from 12A/12B.

---

## J. Production changes made

**Status: PASS** (non-financial only)

| Change | Live impact |
|--------|-------------|
| New services/commands for gated remediation | Code only |
| Platform mining policy status fields | Defaults pending |
| `env.production.example` comments | Template only |
| Database group `phase12d` | Config only |
| Live `.env` / balances / sequence / locks | **Unchanged** |

---

## K. Production changes requiring approval

**Status: READY FOR APPROVAL**

1. `.env` Member ID start=1234, padding=4 + `members:correct-sequence --allow-production --confirm=I_AUTHORIZE_PRODUCTION_CHANGE`
2. `locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --allow-production --confirm=I_AUTHORIZE_PRODUCTION_CHANGE`
3. Approved mining monthly % + policy statuses → approved rate row
4. Dual-control commission plan approve (10/5/3)
5. Real BEP20 USDT contract + destination + RPC → `depositVerifier=evm_erc20`
6. `CI_ENVIRONMENT=production`, HTTPS, secure cookies, hardened DB user
7. Proven full backup restore drill sign-off
8. Scheduler install after posting authorization
9. Kill-switch enablement — **separate activation phase only**

---

## L. Remaining activation blockers

**Status: BLOCKED**

All of K above, plus withdrawal fee/minimum and coin-selling commercial policy (still pending from 12C).

---

## Final attestation

| Kill-switch | State |
|-------------|-------|
| miningPostingEnabled | false |
| miningP2pEnabled | false |
| commissionPostingEnabled | false |
| commissionWithdrawalEnabled | false |
| commissionBlockchainPayoutEnabled | false |
| coinSellingEnabled | false |

**Live NGW000001:** available 5 / locked 0 / total 5  
**Live sequence:** last_number 981233  

**DO NOT DEPLOY. DO NOT ENABLE FINANCIAL POSTING. DO NOT MUTATE LIVE BALANCES WITHOUT EXPLICIT AUTHORIZATION.**
