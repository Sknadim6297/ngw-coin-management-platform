# Phase 12E — Business Decisions Required

**Status:** Open — do not auto-approve  
**Constraint:** Configuration / approval preparation only. Kill-switches remain OFF.

Use this checklist before Phase 12F activation planning. Each item must be explicitly decided by authorized business/finance owners.

---

## 1. Mining Policy

| Decision | Options | Current |
|----------|---------|---------|
| Monthly mining percentage | Explicit % (e.g. 3.00000000) | `pending_business_decision` |
| Daily divisor | `fixed_30` **or** `calendar_days` | Must choose; do not assume |
| Earning denomination | `COIN` **or** `USDT` | Must choose; do not assume |
| Approval-day eligibility | Include approval date **or** start following business date | Must choose |
| Post lock-maturity mining | Eligible / ineligible / partial rules | Unresolved |
| Post coin-sale mining | Eligible / ineligible / partial rules | Unresolved |
| Decimal precision & rounding | e.g. 8 dp, ROUND_DOWN / HALF_UP | Unresolved |
| Policy effective date | YYYY-MM-DD | Unresolved |

Illustrative only (not approved): owned 100 × 3% ÷ 30 = **0.10000000** daily.

---

## 2. Commission Policy

| Decision | Options | Current |
|----------|---------|---------|
| Level rates | Confirmed target L1=10% L2=5% L3=3% — formal plan approval still required | Engine plan may be DRAFT |
| Calculation base | Approved purchase USDT **or** other approved financial base | Must choose |
| Sponsor eligibility | Active only / KYC / other | Unresolved |
| Missing sponsor handling | Skip / hold / root absorb | Unresolved |
| Precision & rounding | Explicit | Unresolved |
| Effective date / version | Explicit | Unresolved |

Illustrative only: 100 USDT base → L1 10 + L2 5 + L3 3 = **18 USDT**.

Do **not** silently approve existing DRAFT commission plans.

---

## 3. P2P Policy (Mining Wallet — internal only)

| Decision | Notes |
|----------|-------|
| Min / max transfer | Do not invent |
| Daily transfer limit | Do not invent |
| Sender / receiver eligibility | Explicit rules |
| Reauthentication | Confirm required |
| Fee policy | Fee amount/method or zero — explicit |
| Rate limiting | Thresholds |
| Duplicate request protection | Confirm window |
| Decimal precision | Explicit |

**Execution remains OFF** (`miningP2pEnabled=false`).

---

## 4. Withdrawal Policy (Commission Wallet only)

| Decision | Notes |
|----------|-------|
| Min / max withdrawal | Explicit |
| Fee amount & method | Flat / % — unresolved until approved |
| Network | Intended: BNB Smart Chain / BEP20 |
| Token contract | **Never guess** — provide real contract |
| Destination validation rules | Explicit |
| Admin approval / payout verification / confirmation | Explicit |
| Processing rules | Queues, SLA, fail-closed behavior |

Mining Wallet external withdrawal must remain **prohibited**.  
Do **not** mark withdrawals PAID. Do **not** send real USDT.

---

## 5. Coin Selling Policy

| Decision | Notes |
|----------|-------|
| Selling price policy | Explicit |
| Buyer / counterparty | Explicit |
| Settlement asset & method | Explicit |
| Fee / min / max quantity | Explicit |
| Partial selling allowed | Yes/No |
| Admin approval & settlement verification | Explicit |
| Post-sale mining eligibility | Explicit |
| Rounding / effective date | Explicit |

Mandatory lock remains **1000 days**. Execution remains OFF (`coinSellingEnabled=false`).

---

## 6. Member ID Policy

| Item | Current production | Target (after separate approval) |
|------|--------------------|----------------------------------|
| start | 981234 | 1234 |
| padding | 6 | 4 |
| sequence.last_number | 981233 | 1233 |
| next IDs | NGW981234… | NGW1234, NGW1235… |

Existing IDs (**ROOT0001**, **NGW000001**, etc.) must remain unchanged.  
Reuse: `php spark members:correct-sequence` — **do not execute on production without separate authorization**.

---

## 7. Purchase & LOCK1000

Confirmed and still pending formal policy record:

- Registration free; new members inactive until qualifying purchase
- Minimum qualifying approved purchase = **5 USDT**
- Mandatory lock = **1000 days**
- Admin verification required

Historical remediation (separate approval only):

| Purchase | Member | Current | Expected after remediation |
|----------|--------|---------|----------------------------|
| PUR-10D7174B7FF83054 | NGW000001 | Available=5, Locked=0 | Available=0, Locked=5 |

Reuse: `php spark locking:remediate-mandatory` — **preview only in 12E**.

---

## 8. Blockchain Verification

| Item | Status |
|------|--------|
| `blockchain.depositVerifier` | `not_configured` (blocker) |
| Token contract | Placeholder / not production-usable |
| Deposit receiving address | Must be dual-control approved usable BEP20 |
| RPC / decimals / confirmations | Explicit |
| Secrets | Environment-backed only; never public config tables |

Never guess contract addresses. Never store private keys in DB.

---

## 9. Dual-control & activation governance

Confirm role matrix:

- Who may create DRAFT
- Who may APPROVE (must differ from creator/submitter)
- Who may mark ACTIVE (must differ from approver)
- Reauthentication required on submit/approve/activate/reject

**CRITICAL:** APPROVED/ACTIVE does **not** enable kill-switches or financial posting.

---

## Exact decisions required before Phase 12F

1. Mining monthly %, divisor mode, denomination, approval-day rule, rounding  
2. Commission calculation base + formal plan approval (10/5/3)  
3. P2P limits/fees/eligibility  
4. Withdrawal min/max/fee + real BEP20 token contract  
5. Coin selling price/counterparty/settlement/fee rules  
6. Authorize or defer Member ID sequence correction  
7. Authorize or defer LOCK1000 remediation for PUR-10D7174B7FF83054  
8. Configure real deposit verifier + RPC + contracts (env secrets)  
9. Backup/restore verification sign-off  
10. Scheduler readiness sign-off  
11. Explicit kill-switch enablement plan (per switch, never global) for a later phase  

Until these are decided and dual-control approved, Phase 12F must treat financial execution as **BLOCKED**.
