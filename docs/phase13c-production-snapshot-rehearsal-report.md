# Phase 13C — Production snapshot rehearsal report

Generated from `writable/phase13c-validation-results.json` (`php spark mining:phase13c-verify`, 43 checks, 0 failed, 31 s).
Preparation only. Nothing was posted, migrated, remediated, scheduled or deployed in production.

## 1. Environment identity (not assumed from "localhost")

| Item | Verified value |
|---|---|
| App default connection | `localhost:3306`, database `coin_platform`, ENVIRONMENT=development |
| MariaDB server | 10.4.32 (XAMPP, this machine) |
| Classification | **Local XAMPP operational copy.** It is *not* proven to be the deployed production host. |
| Live DB access used | Temporary account `p13c_live_ro`, random password (never logged), `SHOW GRANTS` verified = `USAGE` + `SELECT ON coin_platform.*` only, dropped at the end of the run |
| Kill switches (all seven) | all `false` before and after |

## 2. Backup source verification (stage G)

An existing Phase 12F dump was used. Nothing was extracted from production in Phase 13C.

| Check | Result |
|---|---|
| File | `writable/phase12f-private/coin_platform-full-20261117-134752.sql` (git-ignored, outside web root) |
| Size | 138,653 bytes = Phase 12F evidence record |
| SHA-256 | `16f44775aca89916c7754192960dc628d27a434f46293a389ef54620fee07766` |
| Format | mysqldump, single-transaction, full schema **and data** (73 `CREATE TABLE`, `INSERT` data for 34 tables) |
| Header | host `localhost`, database `coin_platform`, MariaDB 10.4.32 |
| Timestamp | "Dump completed 2026-11-17 18:17:53" (= 12:47:53 UTC, matches evidence `backup_timestamp`) |
| Completion marker | present |
| Dangerous statements | none (`USE`, `CREATE/DROP DATABASE`, account/GRANT statements all absent) |
| Schema version in dump | `2026-11-17-992001` |
| Classification | `SOURCE_VERIFIED_LOCAL_COPY` |

**Limits.** A written statement that this file represents the *deployed* production server does not exist. Phase 12F created it at the user's request; that is the only authorization evidence. The dump is 1 day old and live has had non-financial activity since (see §4).

## 3. Isolated restore (stage H)

| Target | Purpose | Action | Duration | Access |
|---|---|---|---|---|
| `coin_platform_phase13c_snapshot` | read-only audit baseline | created (marker table `phase13c_marker`) | 4.3 s | account `p13c_snap_ro`, `SELECT` only (grants verified); NOT in the financial-sandbox allowlist |
| `coin_platform_phase13c` | disposable clone for migrations / what-if | created (marker table) | 4.0 s | account `phase13c`, `ALL` on this DB only; allowlisted |

No existing database was dropped. A database without our marker that is not empty is refused by the code.

## 4. Validation after restore

| Validation | Result |
|---|---|
| Table count | 73 = evidence |
| Schema version | `2026-11-17-992001` |
| Row counts (member_profiles 2, member_id_sequences 1, coin_purchase_requests 2, member_coin_balances 1, member_coin_locks 0) | equal Phase 12F evidence |
| Snapshot vs clone | identical (`CHECKSUM TABLE` on 73 tables) |
| `CHECK TABLE` (all tables) | OK |
| Foreign keys | 32 checked, 0 orphans |
| Duplicate references (purchase_ref, transaction_ref, idempotency_key, lock_ref, member_id) | none |
| Cached balance == last ledger row == available+locked+reserved | yes |
| Member ID sequence (`NGW`, last_number) | 981233 (same as live) |
| Classification | **FULL_DATA_RESTORE_VERIFIED** — for the *local* copy only |

Snapshot compared with live *now* (financial core): members 2/2, approved purchases 1/1, owned 5/5, available 5/5, locked 0/0, ownership ledger 1/1, locks 0/0, mining ledger 0/0, commission ledger 0/0 — **equal**. Non-financial tables have moved on since the dump (e.g. `audit_logs` 28 vs 35, `auth_logins` 5 vs 6, `admin_impersonation_*` 2 vs 4), so the snapshot is a point-in-time copy, not a mirror.

The snapshot stayed pristine: `CHECKSUM TABLE` of all 73 tables is identical before and after every audit.

## 5. Historical ownership reconstruction (dry-run, snapshot, stage I)

Method: `MiningEligibleOwnershipSnapshotService` (ledger-as-of, never current balances) under two policy fixtures; no credits are possible (SELECT-only account, mining tables absent in the snapshot).

| Business date (IST) | T-CALENDAR-HALFUP (full-day) | T-NEXTDAY-DOWN (exclude approval day) |
|---|---|---|
| 2026-11-16 | ZERO (no ownership yet) | ZERO |
| 2026-11-17 (approval 12:15:44 IST) | ELIGIBLE owned 5, locked 0 | **ZERO** |
| 2026-11-18 (incomplete day, evidence only) | ELIGIBLE 5 | ELIGIBLE 5 |

- Ledger evidence for `PUR-10D7174B7FF83054`: exactly 1 `PURCHASE_CREDIT`, quantity matches, ownership ref matches.
- Blocked rows (`BLOCKED_HISTORICAL_DATA`): **0**. Missing / inconsistent events: **0**. Lock-movement rows: 0.
- Illustrative daily amount (3 %, 31-day, HALF_UP, *not a rate decision*): 0.00483871 for 5 coins.
- **Scope limit:** the snapshot contains only 2 members (`ROOT0001`, `NGW000001`). The larger genealogy pasted earlier (`NGW000002` … `NGW981235`) is **not in this data**, so its historical ownership is **NOT VERIFIED**.

## 6. LOCK1000 audit (snapshot, dry-run only, stage J)

`php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --group phase13c_snapshot --dry-run` (the existing command; its gate was not bypassed). Apply against the snapshot group is refused by the command (tested).

| Check | Result |
|---|---|
| Purchase exists / approved | yes / `approved` |
| Member | `NGW000001` (user 5) |
| Approval timestamp | 2026-11-17 06:45:44 UTC (12:15:44 IST) |
| Ownership credit | 1 row, quantity 5 = purchase quantity |
| Mandatory lock | **missing** (0 lock rows) |
| Reservation | none (reserved 0) |
| Prior remediation | none (0 audit rows) |
| Computed maturity (approval + 1000 days) | 2029-08-13 06:45:44 UTC |
| Invariant | total 5 = available 5 + locked 0 + reserved 0 |
| Classification | `LOCK1000_ELIGIBLE_FOR_REMEDIATION_ON_SNAPSHOT` |

## 7. Isolated remediation simulation (clone only, stage K)

Dry-run first (clone unchanged), then `--group phase13c --confirm APPLY_STAGING` through the real command.

| | Available | Locked | Total |
|---|---:|---:|---:|
| Before | 5 | 0 | 5 |
| Proposed | 0 | 5 | 5 |
| Actual (clone) | 0 | 5 | 5 |
| Difference | 0 | 0 | 0 |

- Side-effect check: +1 mandatory lock, +1 ownership ledger row; **no** new `PURCHASE_CREDIT`, owned total unchanged (5), inventory and inventory transactions unchanged, mining wallet/ledger unchanged, commission ledger unchanged, no sale/transfer rows.
- Second apply: no-op, state identical (idempotent).
- Rollback proof: re-restoring the same dump returned the clone to the exact baseline (73 tables identical, 4.8 s).
- **A snapshot PASS is a rehearsal only. It is not a production correction and does not authorize one.**

## 8. Migration rehearsal 993001 / 994001 (clone, stage L)

| Item | Result |
|---|---|
| Applied set | exactly `2026-11-17-993001`, `2026-11-18-994001` (0.49 s) |
| New tables | `financial_policy_versions`, `financial_policy_events`, `mining_post_day_runs`, `mining_post_members` |
| Existing tables | all 73 unchanged (DDL hash and data checksum), excluding the `migrations` bookkeeping table |
| Alters existing tables | no (static scan) |
| Compatibility | worksheet builds on the migrated clone; hub tables present; `mining:post --dry-run` reports "no approved mining rate" (no crash, no post) |
| `down()` | removes only the 4 new tables; originals identical to baseline |
| Re-apply after `down()` | OK |
| Rollback limit | `down()` DROPs the new tables with their rows — safe only before the hub/scheduler has written data; export first otherwise |

Production migration checklist: see `docs/operations/MINING_PRODUCTION_ACTIVATION_CHECKLIST.md` (item 7) and `writable/phase13c-validation-results.json` → `migration_readiness.production_checklist`.

## 9. What this rehearsal does NOT prove

1. The dump is the local XAMPP copy; equivalence with the deployed host is unproven.
2. Only 2 members exist in the snapshot; large-tree historical ownership is unverified.
3. The production migration, LOCK1000 remediation and scheduler have not been run in production and need written authorization.
