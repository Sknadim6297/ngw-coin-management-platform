# Phase 13C.1 — Mining COIN Tokenomics Impact

Covers sections F (mining COIN supply analysis) and G (daily/monthly previews). All figures are computed by
`MiningProposalService::economicPreview('3')` with bcmath (no floats) and are stored in
`writable/phase13c1-validation-results.json`. They are **previews of a PROPOSED 3 % rate, not approved policy**.

## F. Mining COIN supply analysis

### F1. Where does a mined COIN come from? — **CRITICAL, UNRESOLVED**

Facts established from the code and the live inventory (SELECT-only):

* `MiningWalletService` writes a `daily_mining_credit` into `mining_wallet_ledger` and raises `mining_wallets.available_balance`.
* **No mining code reads or debits `coin_inventory`.** Purchases allocate from inventory; mining does not.
* No supply cap or total-supply limit exists anywhere in the code.
* Live `coin_inventory`: 20,000 added / 19,995 available / 5 allocated, flagged "Unverified internal inventory record".

Therefore the platform currently behaves as **newly minted with no ceiling**. Whether that is intended is the owner's
decision (gap G1). Until it is stated, the supply source is a **CRITICAL blocker** and the rate must not be called
sustainable.

### F2. Two possible models (neither is assumed)

**Model M — minted.** Supply grows by 3 % per month of eligible holdings. At constant holdings X, after 12 months the
Mining Wallets hold 36 % of X as additional COIN; after 1000 days ≈ 98.56 % of X. Inflation relative to purchased
supply is certain, and any future ability to sell or convert mined COIN turns this into a direct dilution/liability.

**Model R — reserve-funded.** Total supply fixed at 20,000 (if the inventory record is accurate). Holdings X come out of
the same stock, reserve R = 20,000 − X. Mining moves coins from R to wallets at 0.03·X per month, so reserve lasts
(20,000 − X)/(0.03·X) months (constant X, simple, no compounding):

| Owned X | Reserve R | Mining per month | Months until reserve exhausted |
|--------:|----------:|-----------------:|-------------------------------:|
| 1,000 | 19,000 | 30 | 633.3 |
| 5,000 | 15,000 | 150 | 100.0 |
| 10,000 | 10,000 | 300 | 33.3 |
| 15,000 | 5,000 | 450 | 11.1 |
| 20,000 | 0 | 600 | no reserve at all |

Under Model R, once the reserve is exhausted new purchases cannot be fulfilled, so the rate itself limits sales. This
model also needs a debit mechanism that does not exist today. These are arithmetic illustrations from stated
assumptions, **not evidence of sustainability** (no revenue, price, or sell-through data is available here).

### F3. Denomination review (COIN) — answers from the code

| Question | Answer (evidence) |
|----------|-------------------|
| Which asset does the Mining Wallet store? | `COIN` (default, `asset_code` COIN); observed assets in simulation = `["COIN"]` only |
| Do credits create supply? | Yes — nothing is debited from inventory (F1) |
| Do credits auto-add to ownership? | **No.** Ownership ledger and balances were identical before/after posting in the simulation |
| Can wallet coins enter mining calculations? | **No.** `MiningEligibleOwnershipSnapshotService` reads only ownership ledger, purchases and locks (code check strips comments; no Mining Wallet reference) → no compounding |
| Does P2P affect eligibility? | **No**, same reason |
| Do transfers change total supply? | P2P writes `p2p_debit` + `p2p_credit` (conserving); P2P is disabled in production |
| LOCK1000 rule for mined coins? | **None.** Locking operates on ownership only |
| Can mined coins become sellable? | Not today: selling engine uses ownership only; `coinSellingEnabled` is false |
| Can they move into ownership? | No such feature exists |

**Recommendation (future work only, not implemented):** keep three explicit balance classes —
`PURCHASED_OWNERSHIP` (ownership ledger, eligible for mining), `MINING_REWARD_BALANCE` (mining credits) and
`P2P_RECEIVED_REWARDS` (credits received from other members). An *equivalent* exists only at ledger level
(`entry_type` = `daily_mining_credit` / `p2p_credit` / `p2p_debit`); the wallet **balance is pooled**, so earned and
received coins cannot be separated by balance. No wallet architecture was changed in this phase.

### F4. Compounding — separate hypothetical, NOT enabled

Compounding is impossible today (F3). If an owner later let mining credits become eligible ownership, 12-month
growth of the holding at 3 % per month would be:

| Holding | Simple after 12 m | Monthly compounding | Daily compounding (2027, actual days) |
|--------:|------------------:|--------------------:|--------------------------------------:|
| 100 | 136.00000000 | 142.57608868 | 143.30749246 |
| 500 | 680.00000000 | 712.88044342 | 716.53746230 |
| 1,000 | 1,360.00000000 | 1,425.76088684 | 1,433.07492460 |
| 5,000 | 6,800.00000000 | 7,128.80443423 | 7,165.37462301 |
| 10,000 | 13,600.00000000 | 14,257.60886846 | 14,330.74924602 |

Status in the output: `HYPOTHETICAL_NOT_ENABLED`.

## G. Daily and monthly calculation previews (3.00 % monthly, per-day = holding × 3 % ÷ days in month)

Daily credit per day, 8 decimal places. HALF_UP / DOWN (HALF_UP shown first).

| Holding | 28-day month | 29-day month | 30-day month | 31-day month |
|--------:|--------------|--------------|--------------|--------------|
| 100 | 0.10714286 / 0.10714285 | 0.10344828 / 0.10344827 | 0.10000000 / 0.10000000 | 0.09677419 / 0.09677419 |
| 500 | 0.53571429 / 0.53571428 | 0.51724138 / 0.51724137 | 0.50000000 / 0.50000000 | 0.48387097 / 0.48387096 |
| 1,000 | 1.07142857 / 1.07142857 | 1.03448276 / 1.03448275 | 1.00000000 / 1.00000000 | 0.96774194 / 0.96774193 |
| 5,000 | 5.35714286 / 5.35714285 | 5.17241379 / 5.17241379 | 5.00000000 / 5.00000000 | 4.83870968 / 4.83870967 |
| 10,000 | 10.71428571 / 10.71428571 | 10.34482759 / 10.34482758 | 10.00000000 / 10.00000000 | 9.67741935 / 9.67741935 |

Theoretical issuance (constant holding, simple):

| Holding | Per month (any length) | Annual (×12) | Annual % | 1000-day simple issuance (from 2026-11-18, actual days) |
|--------:|-----------------------:|-------------:|---------:|--------------------------------------------------------:|
| 100 | 3.00000000 | 36.00000000 | 36 % | 98.55806451 |
| 500 | 15.00000000 | 180.00000000 | 36 % | 492.79032258 |
| 1,000 | 30.00000000 | 360.00000000 | 36 % | 985.58064516 |
| 5,000 | 150.00000000 | 1,800.00000000 | 36 % | 4,927.90322580 |
| 10,000 | 300.00000000 | 3,600.00000000 | 36 % | 9,855.80645160 |

Notes: (1) With `calendar_days` the monthly total is 3 % in every month; with `fixed_30` it would be 2.80 % (28 days) to
3.10 % (31 days). (2) Daily-credit rounding creates dust, e.g. 100 coins, 28 days, HALF_UP: 28 × 0.10714286 =
3.00000008 (+8e-8). (3) Calendar-day counts verified for 13 months, Feb 2027 = 28, Feb 2028 = 29.

## Inflation, treasury, circulating supply, sustainability — conclusion

* Inflation: certain under Model M; absent under Model R but the reserve drains (F2).
* Treasury sufficiency: **cannot be assessed** — no treasury balance, revenue or liability data exists in the system.
* Circulating supply: undefined for mined COIN (not in ownership, not sellable today).
* Sustainability: **not demonstrated**. The proposed 3 % cannot be called sustainable on the evidence available.
