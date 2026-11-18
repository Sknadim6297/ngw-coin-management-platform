# Phase 13C.1 — Mining Policy Proposal Package

Status: **REVIEW DOCUMENT. Nothing in this file is approved.** Generated 2026-11-18 (Asia/Kolkata).
Scope covers sections A, B, C, D, H and I of the Phase 13C.1 brief. Local only; no production write.

Related: `phase13c1-business-risk-analysis.md`, `phase13c1-mining-tokenomics-impact.md`,
`phase13c1-owner-approval-checklist.md`, `phase13c1-policy-validation-report.md`.

---

## A. The 12 decision identifiers (read from the real code, not renamed)

Source: `MiningPolicyWorksheetService::decisionCatalog()` (live output of `build()`), cross-checked against
`phase13c-mining-business-decision-matrix.md`. Live status on `coin_platform`, read through a SELECT-only account:
**all 12 = `PENDING_BUSINESS_APPROVAL`**; Hub tables absent in live (migration 993001 not applied); only one rate
exists (`MRC-20261117-AA1FAA19`, 2026-11, 1.00000000 %, `disabled`).

| # | Id | Decision | Platform flag | Engine default (NOT an approval) | Live state |
|---|----|----------|---------------|----------------------------------|------------|
| 1 | `monthly_percent` | Monthly mining percentage | `pending_business_decision` | none (rates live in `mining_rate_configs`) | PENDING |
| 2 | `day_divisor` | Daily divisor (fixed 30 vs calendar days) | `pending_business_decision` | `calendar_days` | PENDING |
| 3 | `denomination` | Mining denomination (COIN vs USDT) | `pending_business_decision` | `COIN` | PENDING |
| 4 | `approval_day` | Approval-day eligibility | `pending_business_decision` | `full_day` | PENDING |
| 5 | `locked_coins` | Locked coin eligibility | `pending_business_decision` | locked coins counted (no explicit value) | PENDING |
| 6 | `post_maturity` | Mining after LOCK1000 maturity / unlock | `pending_business_decision` | `include_all` | PENDING |
| 7 | `post_sale` | Mining after sale / during reservation | `pending_business_decision` | `include` (reserved coins) | PENDING |
| 8 | `rounding` | Rounding policy | `pending_business_decision` | `down` | PENDING |
| 9 | `residual` | Residual / final-day adjustment | `no_status_flag` | none implemented | PENDING |
| 10 | `effective_dates` | Effective dates & policy versions | `no_status_flag` | month-granular rate rows only | PENDING |
| 11 | `scheduler_time` | Scheduler run time | `no_status_flag` | none | PENDING |
| 12 | `alert_destination` | Alert destination | `no_status_flag` | none | PENDING |

Worksheet hardening added in this phase: an approved Hub record whose value is not a canonical enum/number
(for example the owner label `ACTUAL_CALENDAR_DAYS` or `FOLLOWING_BUSINESS_DATE`) now resolves to the new state
`POLICY_VALUE_INVALID` instead of `APPROVED_VERIFIED`. Previously `normalizePayload` accepted any string.

## B. The four owner-proposed values (DRAFT proposals, state `PROPOSED_NOT_APPROVED`)

Mapped onto existing decision ids and existing enums. No new enum names were created.

| Decision id | Owner wording | Canonical value stored | Existing enum / key | State |
|-------------|---------------|------------------------|---------------------|-------|
| `monthly_percent` | 3% per month | `3.00000000` (percent, `rate_basis=monthly`) | `mining_rate_configs.monthly_percent` / Hub `monthly_percent` | PROPOSED_NOT_APPROVED |
| `day_divisor` | ACTUAL_CALENDAR_DAYS | `calendar_days` | `Platform::miningMonthDayDivisorMode` | PROPOSED_NOT_APPROVED |
| `denomination` | COIN | `COIN` | `Platform::miningEarningAsset` | PROPOSED_NOT_APPROVED |
| `approval_day` | FOLLOWING_BUSINESS_DATE (Asia/Kolkata) | `exclude_approval_day` | `Platform::miningApprovalDayPolicy` | PROPOSED_NOT_APPROVED |

Meaning of `exclude_approval_day` (engine cutoff = start of the business day): a purchase approved on IST date D
first earns for business date D+1. Verified against the real as-of engine (see validation report).

What was and was not done with them:

* Drafts were created **only on the disposable clone** `coin_platform_phase13c` (4 Hub drafts, status `draft`,
  `effective_from` empty, approved/active = 0 before and after). The creation method refuses any non-allowlisted DB.
* Nothing was created in `coin_platform`: the Hub tables do not exist there and creating them would be a production
  migration, which is forbidden in this phase. **A production Hub draft therefore cannot be filed until the owner
  authorizes migration 993001 in a later phase.**
* No active rate exists. The disabled historical 1% rate was not touched. Nothing was backdated.
* All 12 decisions stayed `PENDING_BUSINESS_APPROVAL` on the clone after the drafts (drafts do not count as approval).

## C. Remaining unresolved decisions (8 of 12 — owner has not proposed values)

`locked_coins`, `post_maturity`, `post_sale`, `rounding`, `residual`, `effective_dates`, `scheduler_time`,
`alert_destination`. Every one stays `PENDING_BUSINESS_APPROVAL`. Per-decision analysis is in section D and in
`phase13c1-business-risk-analysis.md`.

Plus **ADDITIONAL POLICY GAPS** (not part of the 12; the approved set was *not* expanded — owner must decide whether to
add them): G1 reward supply source (CRITICAL), G2 compounding eligibility of mining credits, G3 treatment of mined
COIN (lock/sellable/ownership), G4 inactive-member accrual, G5 behaviour when one member has bad history, G6 historical
correction method, G7 issuance budget cap, G8 sub-month rate changes, G9 wallet bucket separation. Details in the risk
analysis document.

## D. Recommended options and alternatives (all RECOMMENDATIONS, none approved)

| Id | Recommended | Alternatives | Why |
|----|-------------|--------------|-----|
| `monthly_percent` | Owner value 3.00 % is technically representable. **Recommend not approving until G1 (supply source) and the budget cap G7 are decided.** | Lower rate; tiered rate; rate cap per member | 3 % = 36 % simple per year and ≈ 98.56 % of principal over the 1000-day lock horizon; sustainability is unproven |
| `day_divisor` | `calendar_days` (owner value) | `fixed_30` | Month total always equals 3 % of holding (± rounding dust); daily value varies 0.0968–0.1071 per 100 coins |
| `denomination` | `COIN` (owner value) | `USDT` | Requires G1/G3/G9 first; USDT would need a payout rail that does not exist |
| `approval_day` | `exclude_approval_day` (owner value, IST) | `full_day` | Rule verified at midnight, UTC/IST, month/year end |
| `locked_coins` | Include locked coins (engine behaviour) | Exclude locked | Locked coins are owned; exclusion would remove the incentive for LOCK1000 |
| `post_maturity` | `include_all` (no cliff at unlock) | `exclude_matured_locked` (stop at day 1000) | Stopping at maturity caps issuance at ≈ 98.56 % of principal per lot; decide with G1/G7 |
| `post_sale` | `include` until the SALE_DEBIT posts (reservation does not stop earning; debit does) | `exclude` while reserved | Sold coins never earn (verified); reserved-then-released coins are not penalised |
| `rounding` | `down` at 8 dp (current default, never over-issues) | `half_up` | Max drift ≤ 3.1e-7 coin per member per month (31 days × 1e-8); HALF_UP can over-issue by up to half of that |
| `residual` | No residual posting; accept sub-1e-7 drift and report it in reconciliation | Final-day true-up; carry-forward | True-up needs new posting logic and a new ledger entry type |
| `effective_dates` | Prospective only, month-granular, set by owner (see H) | Mid-month start (needs new logic) | Rate table is month-granular |
| `scheduler_time` | After IST day end, e.g. 01:30 IST posting the previous business date | Any other time ≥ 00:30 IST | Owner/ops must choose; not set by this phase |
| `alert_destination` | Owner to supply a real mailbox/channel | none | **Not invented**; no alert can reach a human without it |

Note: simulations used `half_up` as a *test fixture*, not a proposal. The platform default rounding is `down`, and the
rounding decision is still pending.

## H. Required effective dates

None has been supplied by the owner. Required before any activation:

1. **Rate effective month** (month-granular, e.g. `2026-12` if approved before 2026-12-01 — suggestion only).
2. **Approval-day rule effective date** — applies prospectively to purchases approved on/after this date; historical
   approvals are not reinterpreted (the engine reads approval timestamps as-of, so a rule change would otherwise
   re-derive historical eligibility — see risk R9).
3. **Policy version** identifier in the Hub per decision.
4. No policy may be backdated automatically. Any backdating is a separate, explicitly approved historical correction.

## I. Dual-control approval readiness

| Control | Status |
|---------|--------|
| Hub approval requires a *different* user and the approver's password | Present (Phase 12E) |
| Self-approval refused | Verified for rates in this phase (creator cannot approve own rate) |
| Hub approve refuses while any kill switch is ON | Present (`assertKillSwitchesOff`) |
| Immutable event history (`financial_policy_events`) | Present in code/clone; **table absent in live** |
| Version, proposed value, rationale, effective date, reviewer, decision, timestamp | Hub captures title/payload/notes/effective_from/actor/time; **no first-class "financial impact preview" field** — the preview is attached as a document (this package) |
| Value validation on approval | Not in Hub; compensated by the worksheet check `POLICY_VALUE_INVALID` |
| Can drafts be held safely | Yes on an isolated DB; not in live until migration approval |

Readiness: **dual-control is code-ready; production is not** (hub migration pending, no real approver pair designated).
Approval itself is a separate explicit action — see `phase13c1-owner-approval-checklist.md`.
