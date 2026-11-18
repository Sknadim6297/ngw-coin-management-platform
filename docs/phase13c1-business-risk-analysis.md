# Phase 13C.1 — Business & Financial Risk Analysis

Status: analysis only. No policy approved. Covers sections E and §8 (per-decision review) and the additional policy gaps.

## E. Financial risk matrix

| ID | Risk | Likelihood | Impact | Evidence | Mitigation / required decision |
|----|------|-----------|--------|----------|--------------------------------|
| R1 | **Reward supply source unresolved** (newly minted vs drawn from reserve) | Certain (undefined) | **CRITICAL** | `MiningWalletService` credits the Mining Wallet; no code reads or debits `coin_inventory` for mining; no supply cap exists anywhere | Owner must state source; if reserve, a funding/debit design is needed (Phase 13D+) |
| R2 | 3 % monthly = 36 % simple per year; ≈ 98.56 % of principal over 1000 days | Certain if approved | High | Previews in tokenomics doc | Budget cap (G7), possible lower rate, owner sign-off on liability |
| R3 | Treasury insufficiency if reserve-funded | High at larger holdings | High | Reserve R = 20,000 − X; exhausted after (20,000 − X)/(0.03·X) months (table in tokenomics doc) | Fund model + cap before approval |
| R4 | Inflation of circulating supply if minted | Certain if minted | High | +36 % of purchased holdings per year at constant holdings | Decide mint vs reserve; publish supply policy |
| R5 | Compounding enabled by accident (mining credit becomes ownership) | Low today | High | Verified: posting leaves ownership ledger unchanged; snapshot never reads the Mining Wallet | Any future "convert reward to ownership" feature needs its own approval and a rate re-evaluation (hypothetical compounding = 42.58 % after 12 months monthly / 43.31 % daily) |
| R6 | One BLOCKED member makes every day PARTIAL (exit 40) | Medium | Medium | Seen in posting simulation: 4 runs all exit 40 with 1 blocked member | Policy G5: exclude-and-alert vs block |
| R7 | Rounding drift | Certain, tiny | Low | ≤ 3.1e-7 per member-month | Decision `rounding` / `residual` |
| R8 | Hub accepts any payload value | Medium | Medium | `normalizePayload` does no validation | Mitigated by `POLICY_VALUE_INVALID`; add value validation to Hub in a later phase |
| R9 | Changing `approval_day` later silently re-derives historical eligibility (as-of engine reads the current policy) | Medium | High | Engine cutoff is policy-driven at run time | Effective-date field + forbid backdated runs across a policy change; historical correction is a separate approval |
| R10 | Wallet pooling: earned coins and P2P-received coins share one balance | Certain | Medium | Single `available_balance`; types only separated by ledger `entry_type` (`daily_mining_credit`, `p2p_credit`, `p2p_debit`) | Bucket separation (G9) before enabling P2P |
| R11 | Inactive members skipped | Certain | Medium | Frozen population is active members only; others SKIPPED | G4 |
| R12 | No alert destination / scheduler time | Certain | Medium | Decisions 11 and 12 pending | Owner input; never invented here |
| R13 | Hub tables absent in production | Certain | Medium | Migration 993001 not applied | Separate migration authorization |
| R14 | Inventory record unverified | Certain | Medium | `coin_inventory`: 20,000 added / 19,995 available / 5 allocated; "Unverified internal inventory record" | Reconcile against an independent source before relying on it for supply claims |

## §8 Per-decision review (current behaviour is read from code; no behaviour was assumed)

Approval status for every row: **PENDING_BUSINESS_APPROVAL**. Owner proposals (1–4) are PROPOSED_NOT_APPROVED.

### 1. `monthly_percent`
* **Context:** monthly rate applied by the daily engine as `eligible × percent/100 / days_in_month`.
* **Current behaviour:** rate comes from `mining_rate_configs` per month; one disabled 1 % row; creation by one admin, approval by another.
* **Options:** 3 % (proposed) / lower / tiered.
* **Recommended:** hold approval until G1/G7 resolved. **Alternatives:** lower flat rate; per-member cap.
* **Consequences:** financial — 36 %/yr simple; security — month-granular row, self-approval blocked; historical — only prospective months may be created.
* **Migration:** none for the rate; Hub migration 993001 for the draft record.
* **Tests:** rate-change month boundary (done), duplicate posting (done), historical month not altered (done).

### 2. `day_divisor`
* **Current:** `calendar_days` default; engine divisor per month verified for all 12 months incl. Feb 28/29 (Feb 2027 = 28, Feb 2028 = 29).
* **Options:** `calendar_days` (proposed) / `fixed_30`. **Recommended:** `calendar_days`. **Alternative:** `fixed_30` (constant daily, but monthly total = 3 %×(days/30): 28 days → 2.80 %, 31 days → 3.10 %).
* **Consequences:** financial — none beyond rounding with calendar days; historical — none; **migration** none.
* **Tests:** all 12 months + leap (done).

### 3. `denomination`
* **Current:** `COIN`; Mining Wallet assets observed = `COIN` only. Daily-engine and P2P services read `miningEarningAsset`.
* **Options:** COIN (proposed) / USDT. **Recommended:** COIN, only after G1/G3/G9. **Alternative:** USDT needs a payout rail that does not exist.
* **Consequences:** a COIN reward is a new claim on supply (R1/R4). **Migration:** none now; bucket separation later. **Tests:** asset-mixing check (done).

### 4. `approval_day`
* **Current default:** `full_day` (earns on the approval day). Proposed: `exclude_approval_day`.
* **Recommended:** the proposed value. **Alternative:** `full_day`.
* **Consequences:** historical — one approved purchase exists in live (5 coins); a rule change must not re-derive past runs (R9). **Tests:** 9 timestamp cases + 11 synthetic cases × 3 dates (done).

### 5. `locked_coins`
* **Current:** locked coins are part of ownership and are counted. Verified (M03 locked case earns).
* **Options:** include / exclude. **Recommended:** include. **Alternative:** exclude.
* **Consequences:** exclusion removes the benefit of LOCK1000; inclusion raises issuance on all lots. **Migration:** none. **Tests:** M03 (done).

### 6. `post_maturity`
* **Current:** `include_all` — unlocked/matured coins keep earning. Alternative fixture `exclude_matured_locked` verified in the as-of engine.
* **Options:** include_all / exclude_matured_locked. **Recommended:** `include_all`, subject to the supply decision. **Alternative:** stop at day 1000 (caps per-lot issuance ≈ 98.56 %).
* **Consequences:** financial — decides whether issuance is bounded per lot; historical — none yet (no mining has posted). **Migration:** none. **Tests:** M11 matured lock (done).

### 7. `post_sale`
* **Current:** `include` reserved coins; sold coins (SALE_DEBIT) excluded; partial sale reduces the base; full sale → zero (verified M05, M06, M07).
* **Options:** include / exclude reserved. **Recommended:** include until debit. **Alternative:** exclude reserved.
* **Consequences:** selling is disabled in production; policy only matters once selling opens. **Tests:** done.

### 8. `rounding`
* **Current default:** `down` at 8 dp, rounding once per daily credit. **Options:** down / half_up. **Recommended:** `down`.
* **Consequences:** drift ≤ 3.1e-7 per member-month. HALF_UP/DOWN previews in the tokenomics document. **Tests:** independent integer math comparison (done).

### 9. `residual`
* **Current:** none; daily credits are not trued-up to the monthly figure (e.g. 100 coins at 3 %, 28 days, HALF_UP: 28 × 0.10714286 = 3.00000008).
* **Options:** none / final-day true-up / carry-forward. **Recommended:** none and report drift. **Alternatives:** true-up (new posting logic and entry type — not built).
* **Migration:** none for "none". **Tests:** monthly sum vs theoretical (done in preview).

### 10. `effective_dates`
* **Current:** rate rows are month-granular; no policy-version effective date on the other decisions (Hub has `effective_from`).
* **Recommended:** prospective, owner-set, first day of a month. **Alternative:** mid-month (needs new logic).
* **Consequences:** prevents R9. **Migration:** Hub migration. **Tests:** rate change month boundary (done).

### 11. `scheduler_time`
* **Current:** none configured; cron deliberately not created. **Recommended:** after IST day end (01:30 IST suggested), owner/ops decide. **Tests:** exit-code and lock behaviour (Phase 13C).

### 12. `alert_destination`
* **Current:** none. **Recommended:** a real, monitored destination named by the owner. **Not invented.**

## ADDITIONAL POLICY GAPS (not part of the 12; approved set not expanded)

| Gap | Question | Why it matters |
|-----|----------|----------------|
| G1 | Reward supply: minted or reserve-funded? | CRITICAL; unresolved |
| G2 | May mining credits ever count as eligible ownership (compounding)? | Doubles the economics; today impossible |
| G3 | Are mined COIN subject to LOCK1000, sellable, or convertible to ownership? | Wallet coins are not ownership today |
| G4 | Do inactive members accrue, forfeit, or accrue-on-reactivation? | Today forfeited (SKIPPED) |
| G5 | Policy for members with bad history (BLOCKED) — block the day or exclude and alert? | Today the day becomes PARTIAL |
| G6 | Historical correction method (reversal + repost) and who approves | Not defined |
| G7 | Issuance budget cap (monthly/total) | No cap exists |
| G8 | Rate changes inside a month | Unsupported (month-granular) |
| G9 | Wallet bucket separation PURCHASED_OWNERSHIP / MINING_REWARD_BALANCE / P2P_RECEIVED_REWARDS | Pooled balance today |
