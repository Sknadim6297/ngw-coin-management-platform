# Phase 13C — production readiness blockers

Status: **NOT READY for production activation.** This is expected for a preparation phase.
Classes: `BLOCKED` (must be cleared), `NOT VERIFIED` (cannot be proven from here).

## A. Business decisions (all `PENDING_BUSINESS_APPROVAL`; none auto-approved)

| ID | Decision | Technical default (a recommendation, not an approval) |
|---|---|---|
| B1 | Monthly mining % and effective month | none; the 3 % used in 13A/13B is test-only |
| B2 | Divisor: fixed-30 vs calendar days | calendar days (month total = stated monthly %) |
| B3 | Denomination: COIN vs USDT | COIN; USDT needs an approved conversion policy first |
| B4 | Approval day: Option A (same day) vs B (next day) | A matches the current engine default |
| B5 | Locked coins | include (already a confirmed requirement; still needs a formal record) |
| B6 | After LOCK1000 maturity / unlock | none recommended |
| B7 | After partial/full sale, during pending-sale reservation | none recommended |
| B8 | Rounding HALF_UP vs DOWN | HALF_UP |
| B9 | Residual carry-forward / final-day adjustment | none implemented |
| B10 | Effective dates and policy versions | month granularity (engine) |
| B11 | Scheduler run time | not chosen |
| B12 | Alert destination | not chosen |

Denomination (B3) is the largest open item: the wallet and ledger are single-asset today, so USDT would change wallet, ledger, precision, P2P, reconciliation, dashboard and reports. No conversion rate was assumed and the coin market price was not reused.

## B. Data and environment

| ID | Blocker | Class |
|---|---|---|
| D1 | No approved, non-test mining rate (live has one `disabled` 1 % row for 2026-11) | BLOCKED |
| D2 | Live database lacks migrations 993001/994001 (hub + run-history tables absent) | BLOCKED until authorized migration |
| D3 | Backup is the local XAMPP copy of 2026-11-17; not proven to be the deployed production host | NOT VERIFIED |
| D4 | Snapshot has 2 members; historical ownership for the larger real tree is unverified | NOT VERIFIED |
| D5 | LOCK1000 remediation (`PUR-10D7174B7FF83054`, NGW000001: available 5 / locked 0 -> 0 / 5) has not been approved or run in production | BLOCKED (owner approval) |
| D6 | PHP CLI path and server hardening on the deployment host | NOT VERIFIED |
| D7 | Alert destination not configured | BLOCKED |
| D8 | All production switches `false` (required state until written authorization) | BLOCKED by design |

## C. Findings fixed during Phase 13C (code only, nothing deployed)

1. Policy Hub mining preview under-reported by 1 unit in the 8th decimal (0.96774193 vs 0.96774194 under HALF_UP); it now delegates to `MiningMath` and supports `half_up`/`down`.
2. Per-date posting lock TTL (1800 s) was shorter than the default max runtime (3600 s) and the lock is not renewed; it is now `max(configured, max-runtime + 300)`.
3. `mining:daily` / `mining:reconcile` are labelled LEGACY with printed warnings and point to `mining:post --dry-run`; semantics unchanged, neither can post.
4. New alert classes: lock contention, blocked historical data, job timeout, database connection failure.
5. `locking:remediate-mandatory` accepts `phase13c` (apply needs `--confirm APPLY_STAGING`) and `phase13c_snapshot` (dry-run only; apply refused).
6. Hub `readinessDashboard` entries "backup_restore" and "scheduler" are static text, not evidence-based; they should not be read as proof.

## D. Next gate

Business owner supplies the 12 decisions -> records approved in the Hub with a different approver -> approved rate -> fresh production-host dump + rehearsal -> written authorizations (migration, LOCK1000, activation). Phase 13D must not start until then.
