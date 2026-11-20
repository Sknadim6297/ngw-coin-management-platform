# Instant Commission Posting (L1 / L2 / L3)

Status: implemented, **default OFF**. Nothing in this change enables live commission posting, approves a policy
or deploys anything. Production behaviour does not change until an authorized administrator turns the control ON.

## 1. What it does

When an administrator approves a valid USDT purchase (the existing approval, which also activates the member on
the first approved purchase), the Level 1/2/3 commission for that purchase is posted **immediately** instead of
by the next-day scheduler.

Nothing about the commission rules changed: percentages (10 / 5 / 3), base (approved USDT purchase amount),
sponsor hierarchy (purchase-time sponsor snapshots), currency (USDT), rounding and root-sponsor handling
(ROOT0001 is paid) are all produced by the **existing** engine. There is no second calculation implementation.

```
Admin approves purchase  ->  CoinPurchaseService::approveRequest   (unchanged, one DB transaction:
                              verified deposit, inventory, ownership, ACTIVATION, sponsor snapshots, mandatory lock)
        | commit
        v
CommissionPurchaseEngineService::onPurchaseApproved   (unchanged: writes calculation rows, never posts)
        v
InstantCommissionService::postApprovedPurchase        (NEW, returns null while the control is OFF)
        v
CommissionPostingRunnerService::postApprovedPurchaseEvent -> run(event_mode)   (existing runner)
        v
CommissionWalletService::creditCommission             (existing ledger + wallet, idempotent)
```

## 2. Rules enforced by the new code

| Requirement | How |
|---|---|
| No extra manual approval | Verification/approval already happened in `approveRequest`; the new step only runs after that commit. |
| Governance preserved | The control needs dual-control commission policy + approved plan + mandatory locks (re-checked on **every** approval), admin password and the typed phrase to turn ON. |
| Atomic | All payable levels of a purchase are credited in **one DB transaction** (`postEventGroupAtomically`). Any failure rolls back ledger rows, wallet balances, item status and calculation status together. |
| Idempotent | Ledger key `comm-post:{purchase_ref}:L{level}` is UNIQUE; calculation rows are UNIQUE on `(purchase_ref, sponsor_level)`; the runner only collects `CALCULATED_NOT_POSTED` rows. |
| Retries / refresh / scheduler afterwards | A repeated approval is refused (request no longer pending); a repeated instant call finds nothing to post; the scheduler only collects unposted rows. |
| Approval never undone by commission trouble | Posting runs after the approval commit and never throws into it. A failure is audited (`commission.instant_failed`), alerted, and left for recovery. |
| Scheduler = recovery | Unposted rows stay `CALCULATED_NOT_POSTED`; the Level Commission scheduler job posts them (idempotently). It also re-creates a missing calculation for a post-cutoff purchase whose post-commit calculation failed. |
| No retroactive posting | The first time the control is turned ON the server records `automation_instant_commission_since`. Scheduler/recovery and the preview only touch purchases approved **at or after** it, and never resume a batch frozen before it. The value is never moved afterwards. Older history needs the explicit operator command `commissions:post` (unchanged). |
| Sponsor eligibility | The approved plan's `sponsor_eligibility_policy` is now enforced when calculating (`active_sponsors_only` pays only active sponsors; an unresolved/unknown policy pays nobody). This is the rule the recovery revisions already applied; the live calculation previously ignored it. |
| Mining / money-out unchanged | Daily mining code is untouched. Withdrawals, P2P, coin selling and blockchain payouts stay disabled; the env kill-switches stay `false` (they are raised in-process for one runner call only and always restored). |

"Start All" never turns the instant control ON; "Stop All" always turns it OFF.

## 3. Failure behaviour

| Situation | Result |
|---|---|
| Control OFF | Nothing attempted. Calculation rows are written as before; next-day scheduler flow unchanged. |
| Purchase before the cutoff / not approved / payment not verified | `commission.instant_skipped`, nothing posted. |
| A prerequisite stopped being true (plan, policy, locks, DB) | `commission.instant_blocked` + alert, nothing posted, approval intact, scheduler recovers later. |
| Wallet/DB error while posting | Whole purchase rolled back, `commission.instant_failed` + alert, items `FAILED`, calculation rows still payable. |
| `commission_post` lock held by the scheduler | Retried a few times, then deferred (`commission.instant_deferred`); scheduler posts it. |

Operational notes:
- The scheduler's Level Commission job runs once per business date. A purchase that failed instantly is recovered by the next daily run (or immediately with `php spark commissions:post` for a specific `--purchase`).
- A persistent `PARTIAL` recovery batch (an item that keeps failing) holds that batch open; investigate failed items (`commission_post_items`) and alerts.

## 4. Tests

`tests/feature/InstantCommissionPostingTest.php` (isolated `coin_platform_test` database only; asserts it is not a live DB):
successful L1/L2/L3 post on activation, OFF by default, inactive sponsor / missing sponsor / root-only chain,
pre-cutoff purchase, gate re-check at approval time, retries + refresh + scheduler afterwards (no duplicates),
partial failure with full rollback and scheduler recovery with idempotent duplicate handling, calculation backfill,
scheduler never posts history but explicit reconciliation still can, activation prerequisites and re-authentication,
Start All / Stop All / cutoff behaviour, admin page and toggle.

Regression suites to run with it: `Phase11BCommissionEngineTest`, `Phase14RecoveryAndAutomationTest`,
`FinancialAutomationControlCenterTest`, `CommissionSimulationTest`, `CoinPurchaseTest`, `Phase8BuyCoinsActivationTest`,
`SingleAdminGovernanceTest`.

Never run these against `wwwzeont_ngw_live_db`.

## 5. Deployment plan (no step is automatic)

1. **Backup** the production database and the previous release. Record current commission ledger totals (`commissions:status`, `commissions:reconcile`).
2. **Deploy code only.** No migration is required (no schema change). New files: `InstantCommissionService.php`; changed: `CoinPurchaseService`, `CommissionPurchaseEngineService`, `CommissionPostingRunnerService`, `FinancialAutomationService`, `FinancialAutomationRunnerService`, `FinancialAutomationController`, `Config/Services.php`, the Financial Automation view.
3. **Verify it is inert:** Admin -> Financial Automation shows "Instant Commission Posting: OFF". All eight env kill-switches are still `false`. Approve a test purchase on a staging copy: `commission_post` is null, behaviour unchanged.
4. **Review the sponsor-eligibility change.** Calculation now respects the plan's sponsor policy. Check the approved plan shows the intended policy (`active_sponsors_only`).
5. **Pre-go-live checks on production** (read-only): readiness card of the instant control is fully green (commission policy dual-control approved + active, approved 10/5/3 plan effective, every approved purchase has its mandatory lock, cron heartbeat fresh). `?preview=1` shows what the scheduler would post: confirm it does not include history you do not want posted.
6. **Authorize** (a finance approver / platform admin): turn **Instant Commission Posting ON** (password + typed phrase). This records the cutoff. Do this **before** turning the Level Commission scheduler job ON, so the scheduler can never sweep pre-cutoff history.
7. Then (optional, recovery path): turn **Level Commission** and **Automatic Scheduler** ON.
8. **Smoke test** with one real, already-agreed purchase approval: confirm 3 ledger entries `comm-post:{ref}:L1..L3`, correct beneficiaries/amounts, `commission.instant_posted` audit row, `commissions:reconcile` clean. Re-open the approval page / refresh: nothing changes.
9. Monitor `commission.instant_failed/blocked/deferred` audit rows and alerts for the first days.

Production safety checks built in: DB classification and `ProductionSafetyGate` in the runner, authorization stored against the **database name and an active admin** (a copied setting from another DB is ignored), all kill-switches `false`, policy flags raised in-process only after the DB-backed approvals were verified, `commission_post` job lock, unique ledger keys.

## 6. Rollback plan

- **Fast stop (seconds):** Admin -> Financial Automation -> Instant Commission Posting -> **Turn OFF** (or **Stop All Automation**). Future approvals go back to the previous behaviour (calculate only). Nothing already posted is touched.
- **Do not delete ledger rows.** Posted commissions, wallet balances, audit logs, sponsor links and history are never modified by turning the control OFF.
- **Code rollback:** redeploy the previous release. No schema change was made, so no database rollback is needed. The cutoff and switch live in `platform_settings` (`automation_instant_commission`, `automation_instant_commission_since`) and are harmless when unused.
- **Wrong commission posted:** do not edit the ledger. Use the existing approved remediation/reversal procedure (finance approval) and keep the audit trail.
- **Leave OFF while investigating** any `commission.instant_failed` pattern; unposted rows remain recoverable by the scheduler or `commissions:post --purchase`.

## 7. Business-rule notes carried over (unchanged by this change)

- Commission is calculated for every approved purchase (not only the activating one), as the scheduler does today.
- ROOT0001 is included as a beneficiary (existing default; policy flag `commissionRootEligibilityPolicyStatus` is still managed through the commission policy approval).
- Historical purchases are excluded from automatic posting; reposting history requires the explicit `commissions:post` reconciliation with its own authorization.
