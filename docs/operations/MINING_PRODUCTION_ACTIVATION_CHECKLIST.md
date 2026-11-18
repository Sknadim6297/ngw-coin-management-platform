# Mining production activation checklist (Phase 13C)

**Status of this document: PREPARATION ONLY. No item is complete for production.**
An item may be ticked only with (a) evidence and (b) a named approver. `PARTIAL` = rehearsal evidence exists, production evidence does not.
Completing preparation is not production approval. Activation needs a separate written instruction.

Legend: `[ ]` open, `[~]` partial (rehearsal only). Nothing here is `[x]`.

| # | Item | Status | Evidence available today | Evidence still required |
|---|---|---|---|---|
| 1 | Business policy approval (12 decisions) | `[ ]` | Worksheet `/admin/financial-policies/mining-worksheet`; matrix `docs/phase13c-mining-business-decision-matrix.md` — **0 of 12 verified approved** | Approved Hub policy records (dual control) for: monthly %, divisor, denomination, approval-day, locked, post-maturity, post-sale, rounding, residual, effective dates, scheduler time, alert destination; then platform status flags set to `approved` |
| 2 | Approved monthly mining rate | `[ ]` | Live has 1 rate row: 2026-11, 1 %, status `disabled`, never approved | A non-test rate drafted by one admin and approved by a different admin for the posting month |
| 3 | Verified FULL-data backup of the production database | `[~]` | Phase 12F dump verified (sha256, 73 tables, data for 34 tables, completion marker, no dangerous statements) — local XAMPP copy, 2026-11-17 | Fresh dump taken from the **deployed** production host immediately before activation; sha256 and row counts recorded; stored off the web root; written confirmation it is the production host |
| 4 | Production snapshot rehearsal | `[~]` | `FULL_DATA_RESTORE_VERIFIED` into `coin_platform_phase13c_snapshot` (43/43 checks) | Re-run the same rehearsal on the fresh production dump |
| 5 | Historical ownership reconciliation | `[~]` | 2 snapshot members reconstructed as-of, 0 blocked, 0 missing events | Same for every real member in the production dump; any `BLOCKED_HISTORICAL_DATA` must be resolved or explicitly excluded in writing |
| 6 | LOCK1000 remediation approval | `[~]` | Audit PASS on snapshot; clone what-if 5/0/5 -> 0/5/5, no side effects, idempotent | Written owner approval; re-audit on the fresh dump; run `locking:remediate-mandatory` in production only under an explicit production authorization (separate from this phase) |
| 7 | Migration review (993001, 994001) | `[~]` | Clone migrate/rollback/re-apply OK; additive only; originals unchanged | Written migration authorization; maintenance window; pre/post fingerprints; sequence in `phase13c-production-snapshot-rehearsal-report.md` §8 |
| 8 | Server security review | `[ ]` | — | Deployment host hardening, DB account separation (no root for the app), file permissions on `writable/`, backup location, secrets storage |
| 9 | Scheduler timeout review | `[~]` | Lock TTL now = max(configured, max-runtime + 300 s) (Phase 13C fix); timeout ends a run PARTIAL and is resumable (13B, previously reported) | Choose and approve run time and max-runtime on the production hardware; confirm no overlap with backups |
| 10 | Alert destination | `[ ]` | Local log + event hook only; mock scenarios pass | A business-approved recipient/channel; see `MINING_ALERT_CONFIG_TEMPLATE.md` (placeholders only) |
| 11 | Kill-switch review | `[~]` | All seven switches false before/after Phase 13C | Written decision on which switches may change and when (`miningPostingEnabled`, `miningAutoPostEnabled` only for mining; P2P, commission, withdrawal, payout, selling stay false) |
| 12 | Written production authorization | `[ ]` | — | Signed instruction naming the first business date, rate ref, operator, and the exact command |
| 13 | Controlled activation plan | `[ ]` | Planned command (documented, not installed): `php spark mining:post --scheduled --strict-exit --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE` | Step-by-step plan: single manual dry-run -> single manual live date -> observe -> only then a scheduler entry |
| 14 | Post-activation reconciliation | `[~]` | Built into the run (reconciliation mismatch => PARTIAL, exit 40); previously verified in 13B | Named reviewer; per-date report; wallet total == ledger total check after day 1 |
| 15 | Emergency stop procedure | `[~]` | Switches default false; setting `miningPostingEnabled=false` refuses every run | Documented, rehearsed stop (who flips the switch, how a PARTIAL day is handled, how to disable the scheduler entry) |
| 16 | Rollback procedure | `[~]` | Migration `down()` verified on clone; clone restore-to-baseline 4.8 s | Production rollback plan: ledger corrections are forward-only (reversal entries), not deletions; backup restore criteria; data-loss limit of `down()` after activation |

## Scheduler activation readiness snapshot (read-only, this machine, live DB via SELECT-only account)

13 PASS, 6 BLOCKED, 1 NOT_VERIFIED. BLOCKED = policies not approved, no approved rate, `miningPostingEnabled=false`, `miningAutoPostEnabled=false`, hub/run tables absent in live (migration not applied), alert destination not configured. NOT_VERIFIED = PHP CLI path on the deployment server. Details: `writable/phase13c-validation-results.json` → `live_readiness.scheduler_readiness`.

No cron entry and no Windows Task Scheduler entry exists or was created.
