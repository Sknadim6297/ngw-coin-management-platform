# Phase 13C — Mining business policy finalization report

Preparation only. No policy was approved, no rate was created, no financial switch was changed, nothing was posted/migrated/deployed in production.
Companion documents: `phase13c-mining-business-decision-matrix.md` (generated), `phase13c-production-snapshot-rehearsal-report.md`, `phase13c-production-readiness-blockers.md`, `operations/MINING_PRODUCTION_ACTIVATION_CHECKLIST.md`, `writable/phase13c-validation-results.json`.

## 1. What already existed and was reused (no duplicate engines)

| Area | Reused component |
|---|---|
| Policy governance | Phase 12E Financial Policy Hub (`/admin/financial-policies`, `financial_policy_versions/events`, dual control) |
| Rates | `MiningRateService` / `mining_rate_configs` (month granularity, creator != approver, one approved rate per month, immutable once approved) |
| Posting | Phase 13A/13B `mining:post` (runner, batch service, as-of ownership snapshot, `MiningMath`, strict exit codes, per-date lock, resume) |
| Safety | `ProductionSafetyGate`, `FinancialSandboxService` allowlist, `CliArgs` guard |
| Remediation | `locking:remediate-mandatory` (dry-run default) |

Phase 13B performance/accuracy numbers are **previously reported results**; Phase 13C did not re-run the 10,000-member harness.

## 2. Mining Business Decision Worksheet (new, read-only)

- Route `GET /admin/financial-policies/mining-worksheet` (permission = Policy Hub view; Support View stays read-only), linked from the Hub index. Backed by `MiningPolicyWorksheetService`.
- Separates: confirmed requirements, proposed/technical-default values, pending decisions, approved values, effective dates, policy versions, activation blockers.
- **No auto-approval.** `APPROVED_VERIFIED` requires both an approved/active Hub record containing the decision's payload key **and** the platform status flag `approved`. A flag without a record shows `FLAG_APPROVED_WITHOUT_POLICY_RECORD`; a record without the flag shows `POLICY_RECORD_APPROVED_FLAG_NOT_APPROVED`. Verified in the live (read-only) run: **0 of 12 approved**.
- Confirmed rules preserved (C1–C10): daily calculation; Admin monthly rate; eligible total owned coins including locked; Mining Wallet separate from Commission Wallet; no external withdrawal; internal P2P only when separately enabled; Asia/Kolkata business dates; no duplicate credit per member and date; posting needs approved policies and explicit activation.

## 3. Monthly rate workflow

Fields available in the existing workflow: percentage, effective month, start/end date (first/last day of month), version in month, creator, approver, approval timestamp, status. Self-approval and overlap are refused (existing `MiningSimulationTest::testSelfApprovalBlocked`, `testOverlappingApprovedRatesRejected`, re-run in this phase). No production rate was created. Live holds one old row (2026-11, 1 %, `disabled`, never approved). The 13A 3 % rate is test-only.
Sub-month effective windows are **not supported** by the engine; if needed they are a new business decision.

## 4. Divisor (fixed-30 vs calendar) — illustrative, not selected

1000 coins at 3 % (HALF_UP): fixed-30 = **1.00000000**; 31-day month = **0.96774194** (DOWN 0.96774193). Full 28/29/30/31-day matrices with month totals are in the matrix document §4.

## 5. Denomination (COIN vs USDT) — unresolved

Impact (worksheet §5): wallet asset code, ledger, 8-dp precision, internal P2P, reconciliation, dashboards, reports. No conversion rate was assumed; the coin market price is not reused. The platform stays on `COIN` and nothing is silently changed. Compatibility work for USDT (asset-aware wallets/ledger/reports + an approved conversion policy) is identified in the matrix, not implemented.

## 6. Approval-day options (cutoff semantics, tested)

| Case | Option A (same day) | Option B (next day) |
|---|---|---|
| Approved 00:01 IST on D | earns D | earns D+1 |
| Approved 23:59 IST on D | earns D | earns D+1 |
| Exactly 00:00:00 IST | belongs to the new business day | same |
| Cross-midnight UTC (19:00 UTC = 00:30 IST next day) | business date = IST date | same |
| Backdated business date | never sees later approvals | same |
| Multiple approvals same day | evaluated independently | same |

Snapshot evidence: the real purchase (12:15 IST on 2026-11-17) is ELIGIBLE on the 17th under A and ZERO under B. "Inactive members": eligibility currently ignores `account_status`; that is part of the pending decisions, not assumed.

## 7. Locked / matured / sold eligibility

Confirmed: locked coins are included. Pending (no assumption made): after maturity, after unlock, after partial/full sale, during pending-sale reservation, after settlement. The engine has switchable fixtures (`include_all` / `exclude_matured_locked`; reserved include/exclude) and uses historical ledger events — never current ownership. Missing evidence returns `BLOCKED_HISTORICAL_DATA`.

## 8. Rounding / precision

13B precision kept (bcmath, one rounding step, no floats; a test now asserts no float/round() use in the policy services). HALF_UP vs DOWN shown side by side; independent daily rounding vs residual carry-forward vs final-day adjustment illustrated for a 31-day month (differences vs exact monthly income shown). **No residual policy is implemented**; it needs approval.

## 9. Legacy commands

`mining:daily` and `mining:reconcile` are labelled **LEGACY** (description + printed banner: uses current balances, 2000 cap, never credits, use `mining:post --dry-run`). Semantics unchanged. Both verified on the clone: banner printed, wallet/ledger state unchanged, no posting code.

## 10. Code changes (Phase 13C)

New: `MiningPolicyWorksheetService`, `MiningSchedulerReadinessService`, `Phase13CVerificationService`, command `mining:phase13c-verify`, worksheet view, `Phase13CMiningPolicyReadinessTest`.
Modified: `FinancialPolicyService::miningPreview` (delegates to `MiningMath`, optional rounding), `FinancialPoliciesController` (+ worksheet action, rounding param), `Routes.php`, `Services.php`, `financial_policies/index.php` (button), `MiningPostingRunnerService` (lock TTL fix), `MiningPostAlertService` (4 new alert codes), `MiningDaily` / `MiningReconcile` (LEGACY labels), `RemediateMandatoryLock` (phase13c groups; snapshot dry-run only), `MiningPost`, `MiningPostHealth`, `CorrectMemberIdSequence` (group allowlist), `CliArgs` (group), `FinancialSandboxService` (allowlist `coin_platform_phase13c`; snapshot DB deliberately not allowlisted), `Database.php` (`phase13c`, `phase13c_snapshot`, runtime-only `phase13c_live_ro`).
No migration was added. No `.env` change.

## 11. Test results (actually executed)

| Run | Files | Tests | Assertions | Failures | Skips |
|---|---|---:|---:|---:|---:|
| Focused + regression, single PHPUnit invocation (13m 31s) | `Phase13CMiningPolicyReadinessTest` (19 tests), `Phase12EFinancialPolicyTest`, `Phase12FControlledRemediationTest`, `Phase13AMiningPostTest`, `Phase13BMiningAccuracyTest`, `MiningSimulationTest` | 70 | 492 | 0 | 0 |
| Isolated snapshot rehearsal `php spark mining:phase13c-verify` | n/a (spark) | 43 checks | n/a | 0 | n/a |

Notes: the only PHPUnit warning is "no code coverage driver". During development the first Phase 13C run had 2 failures (an over-broad float-detector regex and an escaped `/` in a JSON assertion � test bugs, plus a comment containing `round(`); they were fixed and the final run above is clean. The first spark run had 4 failing checks caused by verifier logic (CLI JSON parsing, `migrations` bookkeeping DDL counted as a change, exit-code expectation, idempotency expectation); fixed and re-run clean. The full suite was not run. Phase 13B 10,000-member performance results are **previously reported**, not re-run.
