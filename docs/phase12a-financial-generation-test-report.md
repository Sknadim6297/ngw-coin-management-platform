# Phase 12A — Financial Generation Test Report

Generated: 2026-11-17T11:48:04+00:00

## 1. Environment and database isolation

- Live/default app DB inspected earlier: `coin_platform` (contains real member NGW000001) — **NOT USED**
- Isolated test DB: `coin_platform_phase12a` @ `localhost`
- Sandbox window seconds: 120
- Window closed after run: yes

## 2. Audit findings fixed or still pending

- **PENDING_LIVE_REMEDIATION**: PUR-10D7174B7FF… on coin_platform has no mandatory lock; NGW000001 has 5 available / 0 locked
  - Proposal: Do not auto-fix live DB. Controlled remediation: CoinLockingService::lockMandatoryForPurchase for that purchase_ref after admin approval. Phase 12A validates LOCK1000 on synthetic purchases only.
- **SANDBOX_COVERED**: Approved purchase missing commission calculation / no approved plan on live DB
  - Proposal: Approve 10/5/3 plan in sandbox and post via CommissionPurchaseEngineService::postPurchase. Live plan approval remains a business decision.
- **SANDBOX_TEMPORARY_ASSUMPTIONS**: Mining formula decisions unresolved
  - Proposal: Sandbox uses calendar_days divisor, full_day approval policy, COIN asset, total-owned base. Not applied to production .env.
- **PENDING_BUSINESS_CONFIRMATION**: Member ID start NGW1234 vs NGW981234
  - Proposal: Production remains NGW981234 per prior confirmed policy. Sandbox uses SYN* prefix only. Change production start only after explicit business confirmation.

## 3–4. Mining calculation / wallet credit

- Assumptions: `{"base":"total_owned_coins_includes_locked","divisor_mode":"calendar_days","approval_day_policy":"full_day","earning_asset":"COIN","monthly_rate":"3.00000000","label":"SANDBOX_TEMPORARY_ONLY"}`
- Business date: `2026-11-17`
- Buyer owned (includes locked): `100.00000000` locked=`100.00000000`
- Expected buyer credit: `0.10000000`
- Actual buyer posted: `0.10000000`
- Math OK: YES
- Idempotent second run OK: YES

## 5–6. L1/L2/L3 commission / wallet credit

- Expected: L1=10, L2=5, L3=3, total=18 USDT on 100 USDT base
- Actual by level: `{"L1":"10.00000000","L2":"5.00000000","L3":"3.00000000"}`
- Total posted: `18.00000000`
- Math OK: YES
- Purchase lock created: YES (`LCK-M-8E65E005F11AC517`)

## 7. Duplicate generation results

- Commission second pass new posts: `0` duplicates=`3`
- Mining second pass credited: `0` duplicates=`5`
- Commission idempotent OK: YES
- Mining idempotent OK: YES

## 8. P2P transfer results

- Transfer ref: `MPT-20261117-DB74E9A1`
- Debit OK: YES
- Credit OK: YES
- Duplicate blocked: YES
- Reconcile mismatches: `0`

## 9. Withdrawal workflow results

- Submit: `pending_admin_review`
- Reject/release: `rejected`
- Approve: `approved`
- Evidence recorded: ``
- Real USDT sent: **no**
- Live PAID marking: skipped by design

## 10. Coin selling results

- Sell ref/status: `CSL-20261117-C322C969` / `completed`
- Ownership debit OK: YES
- Duplicate blocked: YES
- Real member coins: **no** (synthetic SYN* only)

## 11. Ledger reconciliation

```json
{
    "ownership_rows": 6,
    "hard_failures": [],
    "notes": [],
    "mining_p2p_mismatches": 0,
    "selling_mismatches": 0,
    "withdrawal_mismatches": 0,
    "commission_mismatches": 1
}
```

## 12. Errors

- None

## 13. Production blockers

- Mining/commission/P2P/withdrawal/selling production kill-switches must remain OFF
- Live DB historical missing LOCK1000 for PUR-10D7174B7FF… still needs controlled remediation approval
- Mining formula + commission plan not production-approved
- Member ID start NGW1234 vs NGW981234 awaiting business confirmation
- Blockchain verifier / SMTP / HTTPS production hardening incomplete

## 14. Financial switch states before / after

### Before
```json
{
    "miningPostingEnabled": false,
    "miningP2pEnabled": false,
    "commissionPostingEnabled": false,
    "commissionWithdrawalEnabled": false,
    "commissionBlockchainPayoutEnabled": false,
    "coinSellingEnabled": false,
    "sandbox_window_open": true
}
```

### After
```json
{
    "miningPostingEnabled": false,
    "miningP2pEnabled": false,
    "commissionPostingEnabled": false,
    "commissionWithdrawalEnabled": false,
    "commissionBlockchainPayoutEnabled": false,
    "coinSellingEnabled": false,
    "sandbox_window_open": true
}
```

Production switches remain OFF: **YES**

## 15. Exact next steps

1. Review this report and JSON artifact under `writable/phase12a-test-window-report.json`
2. Decide Member ID policy (NGW981234 vs NGW1234) explicitly
3. Approve controlled remediation for live missing LOCK1000 (do not silent-fix)
4. Approve mining formula + seed production commission plan only after business sign-off
5. Keep all production kill-switches OFF until a separate activation phase

## Classification

| Item | Status |
|---|---|
| Executable sandbox posting code | Implemented |
| Tested successfully in isolation | YES |
| Production approved | NO |
| Production enabled | NO |

Overall run success: **PASS**
