# Phase 13B — business decisions required before production mining posting

No decision below has been made by engineering. The code supports each option as an explicit **policy**; production
`Platform.php` status flags stay `pending_business_decision` (only `approved` opens the live gate) and every production
kill-switch stays `false`. Test fixtures (`T-DEFAULT`, `T-CALENDAR-HALFUP`, `T-FIXED30-HALFUP`, `T-NEXTDAY-DOWN`,
`T-RESERVED-EXCL`, `T-MATURED-EXCL`) exist only to prove the engine can implement either answer.

| # | Decision | Options supported | Effect (illustration: 1000 coins, 3 % monthly) | Platform setting |
|---|---|---|---|---|
| 1 | Monthly percentage and approval authority | Any % in the approved `mining_rates` record (dual control) | — | `miningMonthlyPercentStatus` |
| 2 | Month-day divisor | `calendar_days` (28/29/30/31) or `fixed_30` | 31-day month: `0.96774194` vs `1.00000000`. Calendar pays exactly the monthly % per month; fixed-30 pays 31/30 in 31-day months and 28/30 in February | `miningMonthDayDivisorMode`, `miningDayDivisorStatus` |
| 3 | Rounding | `half_up` or `down` (to 8 decimals, once, at the final amount) | `0.96774194` vs `0.96774193` per member-day | `miningRoundingMode`, `miningRoundingPolicyStatus` |
| 4 | Residual (sub-unit) handling | Currently **none**: no redistribution, no final-day adjustment (explicitly not implemented) | Sum of daily roundings differs from a one-shot monthly figure by a few 1e-8 | — (needs new approved rule if wanted) |
| 5 | Approval-day treatment | `full_day` (coins approved at any time on day D earn for D) or `exclude_approval_day` (first earning day is D+1) | Case A: the 50 coins approved today earn tomorrow under `exclude_approval_day`; today under `full_day` | `miningApprovalDayPolicy`, `miningApprovalDayPolicyStatus` |
| 6 | Locked coins | Eligible (default in code) — must be confirmed | Case B/C | `miningLockedCoinEligibilityStatus` |
| 7 | Coins after lock maturity | `include_all` or `exclude_matured_locked` | Case I | `miningMaturityPolicy`, `miningPostMaturityEligibilityStatus` |
| 8 | Coins reserved for a pending sale | `include` or `exclude` | Case G/H | `miningReservedCoinPolicy`, `miningPostSaleEligibilityStatus` |
| 9 | Denomination / earning asset | COIN vs USDT-equivalent | — | `miningEarningAsset`, `miningDenominationStatus` |
| 10 | Inactive / blocked members | Currently ineligible (zero) | Case E | account status rule |
| 11 | Which date the scheduler posts | Engine posts the completed previous Asia/Kolkata day (`--date yesterday`) | — | scheduler time of day |
| 12 | Back-dated posting | Permitted by the engine for any completed date with an approved rate and complete ownership evidence; business must decide if/when/who may back-post | Case J | operational policy |
| 13 | Retroactive purchase approval / back-dated ownership correction | Engine uses the **ledger timestamp** (`created_at` of the ownership transaction) as "effective"; a correction that is back-dated in the ledger changes past eligibility and must go through a controlled adjustment, not silent recomputation | — | operational policy |
| 14 | Batch size and runtime budget | Tested 100/250/500/1000 | See performance table in the accuracy report | `miningPostBatchSize` (test value, not approved) |
| 15 | Alert channel and thresholds | Local log + event only | — | `miningPostAlert*` |
| 16 | Retry policy | `miningPostMaxAttempts`, `miningPostRetryDelaySeconds` (test values) | — | Platform |

## Activation blockers (all must be cleared; none is cleared by Phase 13B)

1. Decisions 1–10 approved and recorded; the eight `mining*Status` flags changed to `approved` by an authorized person.
2. Migration `2026-11-18-994001_Phase13BMiningPostBatchTables` (and the 13A migration) applied to production through the
   normal controlled migration process — **not** done in Phase 13B.
3. An approved monthly mining rate for the first business month (dual control).
4. Verified historical ownership evidence for the production population (run the read-only `mining:post` dry-run per date and
   resolve every `BLOCKED_HISTORICAL_DATA` member by an authorized correction).
5. Production backup and restore test; scheduler account with least privilege.
6. Alert destination approved and configured (outside this repository's automatic scope).
7. Explicit production sign-off to flip `miningPostingEnabled` / `miningAutoPostEnabled`, and to run the production phrase gate.
