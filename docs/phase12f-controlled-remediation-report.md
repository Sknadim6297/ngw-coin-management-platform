# Phase 12F — Controlled Remediation Report

**Generated:** 2026-11-17  
**Project:** NGW Coin Management Platform  
**Scope:** Backup + preflight + dry-run + isolated testing + reconciliation  
**Live mutations:** **NONE**

**Honored constraints:** no production INSERT/UPDATE/DELETE/ALTER · no deploy · no kill-switch enablement · no real USDT · existing IDs unchanged on production · balances unchanged on production

---

## Classification summary

| Section | Status |
|---------|--------|
| A. Existing Architecture Review | **PASS** |
| B. Production Environment Verification | **PASS** |
| C. Backup and Restore Evidence | **PASS** |
| D. Member ID Sequence Audit | **PASS** |
| E. Member ID Correction Preview | **READY FOR APPROVAL** |
| F. Member ID Isolated Test Results | **PASS** |
| G. Historical LOCK1000 Audit | **PASS** |
| H. LOCK1000 Dry-Run | **PASS** |
| I. LOCK1000 Isolated Test Results | **PASS** |
| J. ProductionSafetyGate Verification | **PASS** |
| K. Ledger Reconciliation | **PASS** |
| L. Phase 12E Regression | **PASS** |
| M. Security Verification | **PASS** |
| N. Focused PHPUnit Results | **PASS** |
| O. Production Before/After Comparison | **PASS** |
| P. Remaining Business Approvals | **READY FOR APPROVAL** |
| Q. Production Activation Blockers | **BLOCKED** (financial activation) |
| R. Recommended Phase 12G Work | **READY FOR APPROVAL** |

---

## A. Existing Architecture Review

Reused (no duplicates created):

| Asset | Role |
|-------|------|
| `php spark members:correct-sequence` | Preview / gated apply |
| `MemberIdSequenceCorrectionService` | Sequence math + collision checks |
| `php spark locking:remediate-mandatory` | Preview / gated LOCK1000 |
| `MandatoryLockRemediationService` | Eligibility + execute |
| `CoinLockingService::lockMandatoryForPurchase` | Atomic available→locked |
| `ProductionSafetyGate` | Live mutation gate + kill-switch snapshot |
| Phase 12E `/admin/financial-policies` | Policy hub (unchanged purpose) |

**Flags confirmed:**

```text
members:correct-sequence [--dry-run] [--group ...] [--start 1234] [--padding 4]
                         [--confirm APPLY_STAGING|I_AUTHORIZE_PRODUCTION_CHANGE]
                         [--allow-production]

locking:remediate-mandatory --purchase PUR-... [--dry-run] [--group ...]
                            [--confirm ...] [--allow-production] [--actor ...]
```

Default without `--confirm` = dry-run.

**Code hardening in 12F (non-production-data):**

1. LOCK1000 dry-run no longer writes audit rows (strict read-only).  
2. Second remediation returns `ALREADY REMEDIATED / SAFE NO-OP` instead of hard fail-only.  
3. Added DB group `phase12f` → `coin_platform_phase12f`; staging commands accept it.

**Status: PASS**

---

## B. Production Environment Verification

Re-read live `coin_platform` (SELECT-only transaction):

| Check | Observed | Expected | Match |
|-------|----------|----------|-------|
| Members | ROOT0001, NGW000001 (count 2) | same | Yes |
| Sequence last_number | 981233 | 981233 | Yes |
| Purchase PUR-10D7174B7FF83054 | approved, qty 5 | approved | Yes |
| Available / Locked / Reserved / Total | 5 / 0 / 0 / 5 | 5 / 0 / 0 / 5 | Yes |
| Mandatory locks | 0 | 0 (missing lock case) | Yes |
| Kill-switches | all false | all false | Yes |
| `.env` start/padding | 981234 / 6 | historical | Yes |

No silent baseline rewrite. Workflow continued because baseline matched.

Evidence: `writable/phase12f-prod-before.json`

**Status: PASS**

---

## C. Backup and Restore Evidence

| Item | Result |
|------|--------|
| Method | `mysqldump --single-transaction` full + schema |
| Timestamp | 2026-11-17T13:47:53+01:00 |
| Private storage | `writable/phase12f-private/` (gitignored) |
| Restore target | `coin_platform_phase12f` (**not** overwriting `coin_platform`) |
| Table count | **73** |
| Includes sequences / purchases / balances / locks | **Yes** |
| Restore duration | ~4.7s |
| Complete restore rehearsal | **Yes** (full data dump restored) |

Evidence: `writable/phase12f-restore-evidence.json`

Limitation: dump is local XAMPP operational copy; production ops should still take a fresh pre-window backup on the deployment host.

**Status: PASS**

---

## D. Member ID Sequence Audit

Inspected: sequence table, Platform config, registration generator floor, uniqueness on `member_profiles.member_id`.

Current:

- start 981234 · padding 6 · last 981233 · next preview NGW981234  
- Existing NGW numeric max = 1 (NGW000001) · ROOT0001 preserved separately

Proposed:

- start 1234 · padding 4 · last 1233 · next NGW1234  
- Existing IDs unchanged

Concurrency note: generator uses sequence row locking (`FOR UPDATE` on apply). Registration uniqueness remains DB unique key.

Long-term: if any future NGW ID with numeric ≥ 1234 is issued before correction, preview flips to `blocked_by_high_existing` and apply refuses.

**Status: PASS**

---

## E. Member ID Correction Preview

Production dry-run (`executed=false`):

```text
ready=true
after_projected.sequence_last=1233
after_projected.preview_next=NGW1234
collision_next_id=false
blocked_by_high_existing=false
```

Also requires separate `.env` update (`memberIdStartNumber=1234`, `memberIdPadding=4`).

**Status: READY FOR APPROVAL**

---

## F. Member ID Isolated Test Results

On `coin_platform_phase12f` (production clone):

| Step | Result |
|------|--------|
| Dry-run | ready |
| Apply `--group phase12f --confirm APPLY_STAGING` | exit 0 · last_number=1233 |
| Existing IDs | ROOT0001, NGW000001 preserved |
| Next IDs | NGW1234, NGW1235 |
| Collision rejection | PHPUnit: NGW5000 blocks start 1234 |
| Idempotent dry-run after apply | ready (already at target) |

Production sequence remained **981233**.

**Status: PASS**

---

## G. Historical LOCK1000 Audit

| Check | Result |
|-------|--------|
| Purchase exists / approved | Yes |
| Member NGW000001 matches | Yes |
| Quantity 5.00000000 | Yes |
| No existing mandatory lock | Yes |
| Sufficient available | Yes |
| Ownership invariant | 5 = 5+0+0 |
| reviewed_at present | 2026-11-17 06:45:44 |
| Maturity = reviewed_at + 1000 days | Projected |

**Status: PASS**

---

## H. LOCK1000 Dry-Run

Production dry-run after audit-write fix:

| | Available | Locked | Total |
|--|-----------|--------|-------|
| Before | 5 | 0 | 5 |
| After projected | 0 | 5 | 5 |

Post dry-run live counts: audit_logs still **28**, locks still **0**, balances unchanged → dry-run write-free.

**Status: PASS**

---

## I. LOCK1000 Isolated Test Results

On `coin_platform_phase12f`:

| Run | Result |
|-----|--------|
| Apply 1 | SUCCESS · Available 0 · Locked 5 · Total 5 · locks=1 |
| Apply 2 | ALREADY REMEDIATED / SAFE NO-OP · locks still 1 |
| Purchase status | unchanged approved |
| No mint | Total owned still 5 |

**Status: PASS**

---

## J. ProductionSafetyGate Verification

| Attempt | Result |
|---------|--------|
| `--allow-production --confirm WRONG` (member) | Refused — wrong phrase |
| `--allow-production --confirm WRONG` (lock) | Refused — wrong phrase |
| `--confirm I_AUTHORIZE_PRODUCTION_CHANGE` without `--allow-production` | Refused — missing allow |
| Live apply with correct phrase | **Not executed** (phase rule) |

Kill-switches asserted OFF by services before mutation paths.

**Status: PASS**

---

## K. Ledger Reconciliation

Production (unchanged):

```text
Total Owned 5 = Available 5 + Locked 0 + Reserved 0
```

Isolated after remediation:

```text
Total Owned 5 = Available 0 + Locked 5 + Reserved 0
```

No mining/commission/P2P/withdrawal/sell rows created on production during 12F.

**Status: PASS**

---

## L. Phase 12E Regression

- Route `/admin/financial-policies` retained  
- PHPUnit `Phase12EFinancialPolicyTest`: **10/10 PASS**  
- Mining/commission previews, dual-control self-approval rejection, Support View block, kill-switches OFF

**Status: PASS**

---

## M. Security Verification

| Control | Status |
|---------|--------|
| No public remediation URL | Pass (CLI only) |
| No public migration endpoint | Pass |
| Production apply dual token | Pass |
| Support View restrictions | Pass (PHPUnit) |
| Dry-run read-only | Pass |
| Private dumps gitignored | Pass (`writable/phase12f-private/`) |
| CSRF / Shield on Admin policy hub | Preserved |

**Status: PASS**

---

## N. Focused PHPUnit Results

| Suite | Tests | Assertions | Result |
|-------|-------|------------|--------|
| `Phase12FControlledRemediationTest` | 10 | 52 | **PASS** |
| `Phase12EFinancialPolicyTest` (regression) | 10 | 45 | **PASS** |
| Full suite | — | — | **NOT RUN** |

Coverage includes: sequence preview/apply/preservation/collision · LOCK dry-run non-write · LOCK apply + idempotent noop · safety gate · kill-switches · Support View · 12E hub.

Concurrent registration stress: uniqueness + sequence `FOR UPDATE` reviewed; dedicated multi-process race **NOT TESTED** in this phase (note for 12G if required).

Logs: `writable/phpunit-phase12f.txt`, `writable/phpunit-phase12e-regression-12f.txt`

**Status: PASS** (focused)

---

## O. Production Before/After Comparison

| Metric | Before | After | Delta |
|--------|--------|-------|-------|
| Member count | 2 | 2 | 0 |
| Member IDs | ROOT0001, NGW000001 | same | none |
| Sequence last | 981233 | 981233 | 0 |
| Available/Locked/Total | 5/0/5 | 5/0/5 | 0 |
| Mandatory locks | 0 | 0 | 0 |
| Audit logs | 28 | 28 | 0 |
| Mining/commission/P2P/WD/sell counts | 0 | 0 | 0 |

`identical_core_state=true` — `writable/phase12f-before-after-compare.json`

**Status: PASS**

---

## P. Remaining Business Approvals

See `docs/phase12f-production-approval-checklist.md`.

Exact operator approvals still required:

1. Fresh pre-window backup sign-off  
2. Authorize Member ID sequence correction + `.env` update  
3. Authorize LOCK1000 remediation for PUR-10D7174B7FF83054  
4. Named rollback owner / change window  

**Status: READY FOR APPROVAL**

---

## Q. Production Activation Blockers

Still **BLOCKED** for financial posting activation:

- Unresolved mining/commission/P2P/withdrawal/selling commercial decisions (Phase 12E)  
- Blockchain verifier not configured  
- Kill-switches intentionally OFF  

Member ID + LOCK1000 remediations themselves are **READY FOR APPROVAL** as isolated ops, not financial activation.

**Status: BLOCKED** (financial activation) / **READY FOR APPROVAL** (two remediations)

---

## R. Recommended Phase 12G Work

1. Scheduled change window with checklist A1–A3 completed  
2. Apply Member ID correction on production (authorized)  
3. Apply LOCK1000 remediation on production (authorized)  
4. Post-apply reconciliation pack  
5. Continue unresolved financial policy decisions  
6. Optional: multi-process registration race test under corrected sequence  
7. Do **not** enable kill-switches until policy + blockchain blockers clear  

**Status: READY FOR APPROVAL** (planning)

---

## Final Verdict

| # | Question | Answer |
|---|----------|--------|
| 1 | Is the production database identity verified? | **Yes** (`coin_platform`) |
| 2 | Is a recoverable backup proven? | **Yes** (full dump → `coin_platform_phase12f`) |
| 3 | Is Member ID correction safe to approve? | **Yes** (preview ready; existing IDs preserved in isolated proof) |
| 4 | Is LOCK1000 remediation safe to approve? | **Yes** (eligibility + isolated success + idempotent noop) |
| 5 | Were both corrections tested in isolation? | **Yes** |
| 6 | Are all production balances unchanged? | **Yes** |
| 7 | Are existing Member IDs unchanged? | **Yes** (production) |
| 8 | Are all financial kill-switches OFF? | **Yes** |
| 9 | Are existing features preserved? | **Yes** |
| 10 | What exact operator approvals remain? | Backup sign-off; Member ID apply; LOCK1000 apply; change window |
| 11 | What is the safest next phase? | **Phase 12G** — authorized production remediation window (one op at a time), still without financial kill-switch enablement |

### Overall

**Phase 12F complete without applying either production correction.**  
Both remediations are **READY FOR APPROVAL** under dual-control CLI authorization. Financial execution remains **OFF**.
