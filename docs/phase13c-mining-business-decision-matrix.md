# Phase 13C — Mining business decision matrix

Generated: 2026-11-18T07:46:21+00:00 from database `coin_platform` (read-only). Single source: `MiningPolicyWorksheetService`.

**A technical recommendation is not business approval.** `Approved Value` is filled only when an approved/active Financial Policy Hub record exists AND the platform status flag is `approved`; otherwise the item is `PENDING_BUSINESS_APPROVAL`.

## 1. Confirmed business requirements

- **C1** Mining income is calculated daily. _(Business (confirmed))_
- **C2** Mining rate is configured monthly by Admin (dual control, no self-approval). _(Business (confirmed); MiningRateService)_
- **C3** Mining is based on eligible total owned coins. _(Business (confirmed); MiningEligibleOwnershipSnapshotService)_
- **C4** Eligible locked coins are included in total owned coins. _(Business (confirmed))_
- **C5** Mining Wallet is separate from the Commission Wallet. _(Business (confirmed))_
- **C6** Mining Wallet funds cannot be externally withdrawn. _(Business (confirmed))_
- **C7** Mining Wallet supports internal P2P only when separately enabled. _(Business (confirmed); miningP2pEnabled=false)_
- **C8** Calculation uses Asia/Kolkata business dates. _(Business (confirmed))_
- **C9** No duplicate credit for the same member and business date. _(Business (confirmed); idempotency key + unique ledger index)_
- **C10** Financial posting requires approved policies and explicit activation. _(Business (confirmed); Phase 13A/13B gates)_

## 2. Decision matrix (0 verified approved, 12 not approved)

| Decision | Current Status | Available Options | Recommended Technical Default | Financial Impact | Historical Data Impact | Approval Required | Approved Value | Effective Date |
|---|---|---|---|---|---|---|---|---|
| Monthly mining percentage | `pending_business_decision` | Any percent > 0 and <= 100 per effective month (draft -> different approver). The 3% used in 13A/13B tests is test-only. | No technical default exists; the business must supply the percentage. | Linear: income = owned x percent / 100 / divisor. Doubling the percent doubles every daily credit. | A rate applies to the month it is approved for; back-dated posting uses the rate of the business month. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Daily divisor (fixed 30 vs calendar days) | `pending_business_decision` (default value `calendar_days`) | fixed_30 / calendar_days (28/29/30/31 of the rate month) | calendar_days (monthly total equals the stated monthly percent in every month). Not an approval. | fixed_30 pays 31/30 of the monthly percent in 31-day months and 28/30 in February. | Changing it after posting does not alter posted days; the day run freezes the policy version. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Mining denomination (COIN vs USDT) | `pending_business_decision` (default value `COIN`) | COIN / USDT | COIN (the Mining Wallet ledger asset is single-asset today); USDT needs an approved conversion policy first. | COIN: income is a coin quantity. USDT: needs a defined conversion (price source, time, rounding) — none is approved. | A denomination change must not re-denominate existing mining ledger rows. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Approval-day eligibility | `pending_business_decision` (default value `full_day`) | full_day (earns on approval business date) / exclude_approval_day (earns from the next business date) | full_day matches the existing Phase 13A tests. Not an approval. | Exclude-day delays the first credit by exactly one business day for every new purchase. | Cutoff = end of the business day (full_day) or its start (exclude_approval_day) in Asia/Kolkata. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Locked coin eligibility | `pending_business_decision` | include (CONFIRMED business requirement C4) | include — already confirmed; the flag still needs a formal approval record. | Locked coins earn the same as available coins. | As-of locked/available split is rebuilt from the ownership ledger. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Mining after LOCK1000 maturity / unlock | `pending_business_decision` (default value `include_all`) | include_all (keep mining after LOCK1000 maturity/unlock) / exclude_matured_locked | No default recommended; the engine implements both. Do not assume matured coins stop mining. | exclude_matured_locked removes matured-lock coins from the eligible quantity from the maturity day. | Maturity is evaluated as-of the business date from lock records. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Mining after sale / during reservation | `pending_business_decision` (default value `include`) | reserved coins: include / exclude. Sold coins: stop at the ledger SALE_DEBIT time (engine behaviour). | No default recommended; do not assume sold coins keep mining. | Reservation include keeps earning until settlement; exclude stops at SALE_RESERVE. | Partial/full sale and settlement are applied from the dated ownership events only. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Rounding policy | `pending_business_decision` (default value `down`) | half_up / down (once, at 8 dp, per member per day). Residual carry-forward and final-day adjustment are NOT implemented. | half_up gives the smallest per-day error; the sum of daily roundings can still differ from the exact month by a few 1e-8. | See the rounding comparison preview for the exact mathematical difference. | Posted rows keep the rounding in force when posted (policy version is stored per day run). | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Residual / final-day adjustment | `no_status_flag` | none (independent daily rounding) / residual carry-forward / final-day adjustment | Keep independent daily rounding; any adjustment rule needs explicit approval and new code + tests. | Non-zero only at the 1e-8 level per member per month. | A final-day adjustment would make a day depend on earlier days (breaks per-day idempotent recompute). | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Effective dates & policy versions | `no_status_flag` | Rate: effective month (start = first day, end = last day of that month). Hub policies: effective_from / effective_to date window. | Month-granular rates are what the engine supports; sub-month windows are not supported. | One approved rate per month (overlap refused). | Backdated posting uses the approved rate of the business month. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Scheduler run time | `no_status_flag` | Post the previous completed Asia/Kolkata business day shortly after midnight IST. | 00:20 IST (as in the Phase 13A/13B examples) with a later health check. Not an approval. | None directly; timing affects when members see credits. | Dates are business dates, not run dates. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |
| Alert destination | `no_status_flag` | Email / webhook / Admin notification system (recipients/URLs must be supplied by the business). | At least one channel with a named on-call owner. | None; a missed day is only noticed if an alert exists. | Not applicable. | Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person | **PENDING_BUSINESS_APPROVAL** (PENDING_BUSINESS_APPROVAL) | — |

## 3. Monthly rate records (actual rows)

| Rate ref | Month | Start | End | Version | Percent | Status | Created by | Approved by | Approved at | Test-only? |
|---|---|---|---|---:|---:|---|---|---|---|---|
| MRC-20261117-AA1FAA19 | 2026-11 | 2026-11-01 | 2026-11-30 | 1 | 1.00000000 | disabled | 1 | — | — | no |

Rate workflow facts (verified in `MiningRateService`): draft -> approve by a **different** user (self-approval refused), one approved rate per effective month (overlap refused), approved rates are immutable, month granularity (start = first day, end = last day of the month). Sub-month effective windows are **not supported**; if the business needs them it is a new decision.

## 4. Illustrative previews (1000 coins, 3 % monthly — NOT approved)

### Divisor and rounding (daily credit / month total)

| Days in month | Exact monthly income | Fixed-30 HALF_UP daily | Calendar HALF_UP daily | Fixed-30 HALF_UP month total | Calendar HALF_UP month total | Calendar DOWN daily | Calendar DOWN month total |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 28 | 30.00000000 | 1.00000000 | 1.07142857 | 28.00000000 | 29.99999996 | 1.07142857 | 29.99999996 |
| 29 | 30.00000000 | 1.00000000 | 1.03448276 | 29.00000000 | 30.00000004 | 1.03448275 | 29.99999975 |
| 30 | 30.00000000 | 1.00000000 | 1.00000000 | 30.00000000 | 30.00000000 | 1.00000000 | 30.00000000 |
| 31 | 30.00000000 | 1.00000000 | 0.96774194 | 31.00000000 | 30.00000014 | 0.96774193 | 29.99999983 |

### Residual handling, 31-day month (exact difference vs exact monthly income)

| Rounding | Independent daily total | Diff vs exact | Carry-forward total (not implemented) | Diff vs exact | Final-day adjustment: last-day credit (not implemented) | Last day minus normal day |
|---|---:|---:|---:|---:|---:|---:|
| half_up | 30.00000014 | 0.000000140000 | 30.00000000 | 0.000000000000 | 0.96774180 | -0.00000014 |
| down | 29.99999983 | -0.000000170000 | 29.99999999 | -0.000000010000 | 0.96774210 | 0.00000017 |

Spec example: fixed-30 = `1.00000000`, 31-day calendar = `0.96774194` (HALF_UP, illustrative).

## 5. Denomination (COIN vs USDT) impact

**If COIN**

- *wallet:* mining_wallets.available_balance holds a COIN quantity (8 dp). Ledger asset = COIN (mining_wallet_ledger.asset).
- *ledger:* daily_mining_credit rows carry the coin amount; no price is involved.
- *precision:* 8 dp (MiningMath, rounded once).
- *p2p:* Internal Mining-Wallet P2P moves COIN between Mining Wallets only (still disabled).
- *reconciliation:* expected COIN (frozen per member) = posted COIN; wallet = ledger sum.
- *dashboard:* Member dashboard shows COIN balances/earnings.
- *reports:* Financial reports total COIN; no USDT conversion line.

**If USDT**

- *definition_required:* A conversion policy: price source (Dynamic Coin Price vs fixed), snapshot time (credit date vs month start), rounding, and handling of price changes. NONE is approved; the coin market price is NOT reused.
- *wallet:* Needs a USDT-denominated Mining Wallet balance or a second asset column; the current Mining Wallet is single-asset (COIN). Compatibility work required — not done silently.
- *ledger:* USDT and COIN balances must never be mixed in one ledger; a separate asset code per row/ledger.
- *precision:* USDT scale (8 dp in this platform) applied after conversion — two rounding steps unless defined otherwise.
- *p2p:* P2P policy must state the transferable asset.
- *reconciliation:* Must reconcile coins x price = USDT credited, per member per day, with the stored price snapshot.
- *dashboard:* Dashboard and reports need USDT labels/formatting.
- *reports:* Reports need the conversion price snapshot to be auditable.

## 6. Open blockers from the worksheet

- `POLICY-MONTHLY_PERCENT` — Monthly mining percentage is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-DAY_DIVISOR` — Daily divisor (fixed 30 vs calendar days) is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-DENOMINATION` — Mining denomination (COIN vs USDT) is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-APPROVAL_DAY` — Approval-day eligibility is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-LOCKED_COINS` — Locked coin eligibility is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-POST_MATURITY` — Mining after LOCK1000 maturity / unlock is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-POST_SALE` — Mining after sale / during reservation is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-ROUNDING` — Rounding policy is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-RESIDUAL` — Residual / final-day adjustment is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-EFFECTIVE_DATES` — Effective dates & policy versions is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-SCHEDULER_TIME` — Scheduler run time is not approved (PENDING_BUSINESS_APPROVAL).
- `POLICY-ALERT_DESTINATION` — Alert destination is not approved (PENDING_BUSINESS_APPROVAL).
- `RATE-APPROVED` — No approved, non-test monthly mining rate exists in this database.
- `HUB-MIGRATION` — financial_policy_versions table is absent (migration 993001 not applied to this database).
