# Phase 13C.1 — Owner Approval Checklist

Nothing below has been approved. This checklist is for a **separate, explicit approval action** by two different people.
Do not tick a box because a value was proposed in a chat message.

## 0. Preconditions (none are satisfied today)

- [ ] Owner authorizes applying Hub migration 993001 to production (`financial_policy_versions`, `financial_policy_events`). Currently absent in live.
- [ ] Two distinct real accounts exist: **proposer** and **approver** (no self-approval; approver re-enters password).
- [ ] All seven switches confirmed FALSE at approval time: `miningPostingEnabled`, `miningAutoPostEnabled`, `miningP2pEnabled`, `commissionPostingEnabled`, `commissionWithdrawalEnabled`, `commissionBlockchainPayoutEnabled`, `coinSellingEnabled`.
- [ ] Reward supply source (G1) answered in writing: **minted** or **reserve-funded**. (CRITICAL)
- [ ] Issuance budget / cap decision (G7) recorded.

## 1. Owner-proposed values (status PROPOSED_NOT_APPROVED)

| Decision | Proposed | Rationale (owner) | Effective date | Impact preview | Approve? |
|----------|----------|-------------------|----------------|----------------|----------|
| `monthly_percent` | 3.00000000 % per month | _owner to write_ | _owner to set (month-granular)_ | tokenomics doc §G, §F | ☐ |
| `day_divisor` | `calendar_days` | _owner to write_ | _owner to set_ | tokenomics doc §G | ☐ |
| `denomination` | `COIN` | _owner to write_ | _owner to set_ | tokenomics doc §F3 | ☐ |
| `approval_day` | `exclude_approval_day` (following Asia/Kolkata business date) | _owner to write_ | _owner to set; prospective only_ | validation report §rule cases | ☐ |

Warnings the approvers must acknowledge:

- [ ] 3 % monthly = 36 % simple per year; ≈ 98.56 % of principal over the 1000-day lock.
- [ ] Economic sustainability is **not demonstrated**.
- [ ] Mined COIN is not ownership, not locked, not sellable today.
- [ ] The Hub stores values without validating them; canonical values are the enum strings above, not the owner labels.

## 2. Remaining decisions (owner has not proposed values)

| Decision | Recommended | Decision |
|----------|-------------|----------|
| `locked_coins` | include | ☐ include ☐ exclude |
| `post_maturity` | `include_all` | ☐ include_all ☐ exclude_matured_locked |
| `post_sale` | `include` until SALE_DEBIT | ☐ include ☐ exclude |
| `rounding` | `down` (8 dp) | ☐ down ☐ half_up |
| `residual` | none; report drift | ☐ none ☐ true-up ☐ carry-forward |
| `effective_dates` | prospective, first of month | _date(s)_ |
| `scheduler_time` | after IST day end (e.g. 01:30 IST) | _time_ |
| `alert_destination` | owner-supplied real destination | _not invented_ |

## 3. Dual-control steps (when the owner decides to approve)

1. Proposer creates a Hub draft per decision with value, rationale, `effective_from`, notes (attach the impact preview).
2. Proposer submits for approval.
3. Approver (different user) reviews this package and the validation report, approves with password.
4. For the rate: create the `mining_rate_configs` draft (creator) and approve (another user).
5. Activate only after every precondition in section 0 is met; activation does **not** turn on any switch.
6. Re-run `php spark mining:phase13c1-verify` on the isolated clone; confirm the worksheet shows `APPROVED_VERIFIED`, not `POLICY_VALUE_INVALID`.
7. Phase 13D remains a separate instruction; this checklist does not start it.

## 4. Sign-off record (blank)

| Role | Name | Decision | Timestamp (Asia/Kolkata) |
|------|------|----------|--------------------------|
| Proposer |  |  |  |
| Approver |  |  |  |
