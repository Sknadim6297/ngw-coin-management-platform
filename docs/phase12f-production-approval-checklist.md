# Phase 12F — Production Approval Checklist

**Status:** Awaiting separate human authorization  
**Constraint:** Phase 12F did **not** apply either production correction.

Use this checklist before any live remediation. Each item requires an explicit operator decision.

---

## Pre-conditions (verified in 12F)

- [x] Production DB identity = `coin_platform`
- [x] Baseline matches: ROOT0001, NGW000001; sequence `last_number=981233`
- [x] PUR-10D7174B7FF83054 approved; Available=5 / Locked=0 / Total=5
- [x] All financial kill-switches OFF
- [x] Full mysqldump restore rehearsed into `coin_platform_phase12f` (73 tables)
- [x] Member ID dry-run ready → next `NGW1234`
- [x] LOCK1000 dry-run ready → Available 0 / Locked 5 / Total 5
- [x] Unauthorized production apply refused without exact phrase
- [x] Isolated apply proven; second LOCK1000 run = ALREADY REMEDIATED
- [x] Production before/after identical (no live mutation in 12F)

---

## A. Operator approvals required

### A1. Backup sign-off

- [ ] Latest production dump taken immediately before change window
- [ ] Dump stored offline / restricted location (not public web)
- [ ] Restore contact + rollback owner named
- [ ] Change window agreed

### A2. Member ID sequence correction

Target:

| Setting | From | To |
|---------|------|----|
| `platform.memberIdStartNumber` | 981234 | 1234 |
| `platform.memberIdPadding` | 6 | 4 |
| `member_id_sequences.last_number` (NGW) | 981233 | 1233 |
| Next IDs | NGW981234… | NGW1234, NGW1235… |

- [ ] Business owner approves format NGW1234+
- [ ] Confirmed existing IDs must remain unchanged (ROOT0001, NGW000001, …)
- [ ] `.env` update prepared (start=1234, padding=4)
- [ ] Command authorized (separate ticket):

```text
php spark members:correct-sequence --allow-production --confirm "I_AUTHORIZE_PRODUCTION_CHANGE"
```

- [ ] Post-apply verify: `previewNext` = NGW1234
- [ ] Rollback plan: restore `last_number` + prior `.env` values

### A3. Historical LOCK1000 remediation

Target purchase: **PUR-10D7174B7FF83054** / member **NGW000001**

| | Available | Locked | Total |
|--|-----------|--------|-------|
| Before | 5 | 0 | 5 |
| After | 0 | 5 | 5 |

- [ ] Finance owner approves reclassification (no mint)
- [ ] Confirmed no mining/commission side effects expected
- [ ] Exact pre-state re-read immediately before apply
- [ ] Command authorized (separate ticket):

```text
php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --allow-production --confirm "I_AUTHORIZE_PRODUCTION_CHANGE"
```

- [ ] Post-apply verify invariant: Total = Available + Locked + Reserved
- [ ] Second run returns ALREADY REMEDIATED / SAFE NO-OP

---

## B. Hard rules during live apply

1. One operation at a time (Member ID **or** LOCK1000 — not batched blindly).
2. Both `--allow-production` **and** exact `I_AUTHORIZE_PRODUCTION_CHANGE` required.
3. Re-read production state immediately before each apply; **STOP** if baseline drifted.
4. Do **not** enable any financial kill-switch in this remediation window.
5. Do **not** deploy unrelated code in the same window unless separately approved.
6. Do **not** send real USDT / post mining / post commission.

---

## C. Post-apply verification (each correction)

- [ ] Production snapshot captured
- [ ] Audit log entry present for the authorized operation only
- [ ] Member IDs list unchanged (for sequence correction)
- [ ] Ownership invariant holds (for LOCK1000)
- [ ] Kill-switches still OFF
- [ ] Registration smoke test (next Member ID)
- [ ] Admin/Member login smoke test

---

## D. Still blocked for later phases (not part of 12F apply)

- Mining monthly % / divisor / denomination decisions
- Commission calculation base formal approval
- Blockchain deposit verifier + real token contract
- Per-switch financial activation plan

See `docs/phase12e-business-decisions-required.md`.

---

## E. Recommended order for future live window

1. Fresh backup + restore contact ready  
2. Member ID sequence correction + `.env` update  
3. Registration smoke (NGW1234)  
4. LOCK1000 remediation for PUR-10D7174B7FF83054  
5. Ownership reconciliation  
6. Freeze financial switches (remain OFF) until Phase 12G decisions
