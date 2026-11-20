# Single-Admin Financial Governance (NGW)

NGW is operated by exactly one administrator. Under the default `dual_control` mode a Mining or Commission
policy, the monthly mining rate and the commission plan cannot be approved by the person who drafted them, so
the platform can never reach "ready". `single_admin` mode is a formally configured alternative that lets the
designated administrator approve their own items through a stricter, fully recorded workflow. It is opt-in, it
does not remove any authorization check, and it never touches a kill-switch or posts COIN/USDT.

## 1. Configuration

| `.env` key | Default | Meaning |
|---|---|---|
| `platform.financialGovernanceMode` | `dual_control` | `single_admin` enables the workflow. Any other value is treated as `dual_control`. |
| `platform.financialSingleAdminUserId` | `0` | `users.id` of the only administrator allowed to self-approve. Required; `single_admin` without it is refused. |
| `platform.financialSingleAdminReviewTtlSeconds` | `900` | How long a review stays usable (minimum 60 s). |

Migration `2026-11-20-997001_CreateFinancialGovernanceActions` adds one append-only table,
`financial_governance_actions`. It is additive (no existing table or row is changed).

Read-only check: `php spark governance:status [--type financial_policy|mining_rate|commission_plan --ref REF] [--group phase14]`.

## 2. What the administrator can do in `single_admin` mode

| Item | Action | Allowed terms (anything else still needs a second approver) |
|---|---|---|
| Mining policy (`financial_policy_versions`, domain `mining`) | approve, activate | `monthly_percent` 3, `rate_basis` monthly, `month_day_divisor_mode` calendar_days, `approval_day_policy` exclude_approval_day, `earning_asset` COIN, `compounding_enabled` false, `business_timezone` Asia/Kolkata, `policy_version` equal to the engine configuration; platform config must match |
| Commission policy (domain `commission`) | approve, activate | L1 10 %, L2 5 %, L3 3 %, base = approved USDT purchase amount |
| Monthly mining rate (`mining_rate_configs`) | approve | exactly 3 % monthly, reward COIN |
| Commission plan (`commission_plans`) | approve | exactly 3 levels, 10 / 5 / 3 %, USDT |
| P2P, withdrawal, selling, blockchain, purchase policies | — | never single-admin (dual-control only) |

## 3. Workflow (UI: *Review & approve as single administrator* on the policy, rate or plan page)

1. **Review** — `/admin/financial-governance/{type}/{ref}/{intent}` shows every term. The admin re-enters their
   password and records a review (`review_approve` / `review_activate` row with version + SHA-256 terms hash).
2. **Separate confirmation** — the admin re-enters the password again, types `APPROVE <ref>` / `ACTIVATE <ref>`,
   ticks every single term and acknowledges that there is no independent second approver.

The approving service (`FinancialPolicyService::approve/activate`, `MiningRateService::approve`,
`CommissionPlanService::approve`) re-validates everything inside its own transaction, so no controller or CLI path can
skip a step:

- mode is `single_admin`, the actor is the designated, active admin with the approval permission, not in Support View;
- fresh password (Shield identity) and the logged-in user equals the actor;
- the review exists, belongs to this admin / subject / action, is unused (single-use) and within the TTL;
- version (policy `lock_version`, rate/plan `updated_at`) and terms hash unchanged since the review, and equal to the
  hash the browser posted → otherwise *stale version*;
- typed phrase, every term confirmed, acknowledgement;
- terms equal the business rules in section 2;
- policy lifecycle unchanged (`draft → pending_approval → approved → active`), optimistic lock on the update, all
  production kill-switches OFF.

Every approval/activation (both modes) appends a `financial_governance_actions` row with admin ID, timestamp, subject
version, action, governance mode, terms hash, terms snapshot, review reference, database, IP and user agent. The
existing `financial_policy_events` / `mining_rate_approval_events` / `commission_plan_approval_events` and
`audit_logs` entries now also carry `governance_mode` and `review_ref`.

## 4. Financial Automation readiness

A self-approved (or self-activated) item is accepted only when **all** hold: mode is `single_admin`, the approver is
the designated admin, and a `single_admin` governance record exists whose terms hash equals the current terms.
Drafts are never accepted; a row forced to `approved` without the workflow, a row edited after approval, a
non-designated self-approver, or switching back to `dual_control` all block readiness. Approving never enables
automation: the Control Center switches still have to be turned on separately (password + phrase).

## 5. Security review

**Threats addressed**

| Threat | Control |
|---|---|
| Accidental / one-click approval | Two separate steps, password twice, typed phrase, per-term confirmation, acknowledgement |
| Approving something different from what was reviewed | Version + SHA-256 terms hash bound to the review and re-checked at confirmation (stale version refused) |
| Replay of an old confirmation | Review is single-use, action- and subject-bound, expires after the TTL |
| Session hijack / unattended browser | Fresh password at both steps; CSRF on every POST; Support View refused |
| Another admin account (e.g. compromised or newly created) self-approving | Only `financialSingleAdminUserId` may self-approve; changing it requires server `.env` access |
| Self-approving unusual economics (e.g. 5 % mining, L3 2 %) | Single-admin approval limited to the owner-approved rules; anything else needs dual-control |
| Back-door DB edits after approval | Readiness recomputes the terms hash and blocks if it no longer matches the governance record |
| Silent bypass of production safety | `ProductionSafetyGate` unchanged; kill-switches asserted OFF; dual-control remains the default |

**Residual risks and compensating controls**

1. *No independent second person.* A single admin can still make a business mistake inside the allowed rules
   (e.g. wrong effective date). Compensation: rules are fixed to the owner-approved values, every term is displayed and
   confirmed, append-only trail, Financial Automation must be switched on separately, emergency Stop All, and a
   recommended monthly owner review of `financial_governance_actions` and `audit_logs`.
2. *Server / `.env` access equals governance control.* Whoever can edit `.env` can switch the mode or the designated
   user. Compensation: SSH access limited to the owner; record `.env` changes in the change log; `governance:status`
   shows the mode at any time; the readiness detail names the governance mode.
3. *Direct database writes.* A DBA could insert a forged governance row. Compensation: DB credentials restricted,
   verified backups before every change, hash comparison catches term edits; forged rows still carry no review chain
   (`review_ref` pointing at a matching `review_*` row) and are visible in the trail.
4. *Password-only re-authentication* (no hardware second factor). Compensation: strong unique admin password,
   HTTPS (`forceGlobalSecureRequests`), CSRF, password asked twice; consider adding TOTP for this workflow later.
5. *Rate/plan version uses `updated_at` (second precision).* An edit in the same second as the review would keep the
   version, but the terms hash would still change and be refused.
6. *Out of scope (unchanged, still dual-control):* `MiningRecoveryService::approveProposal`,
   `FinancialRemediationService`, payment-destination approvals, and P2P / withdrawals / coin selling / blockchain
   payouts / commission auto-post-on-approval (which stay disabled).

## 6. Production plan for Mining Policy `FPV-MIN-20261118-6611BC` (`pending_approval`)

The policy is **not** approved automatically and its history is not rewritten. After deployment (section 7):

1. Read-only inspection:
   `php spark governance:status --type financial_policy --ref FPV-MIN-20261118-6611BC`
2. If `single_admin_eligible` is `true` (terms match the rules, including `policy_version`), the owner — logged in
   as the designated admin — opens the policy page, uses *Review & approve as single administrator*, confirms, then
   repeats the review/confirmation for *activate*. The existing `submitted` event and all prior history stay intact.
3. If it is **not** eligible (e.g. created before `policy_version`/`business_timezone` were part of the draft, or the
   engine configuration changed), do not edit it. Reject it on the policy page with the reason shown by
   `governance:status`, then create a fresh draft:
   `php spark mining:prepare-policy-draft --effective-from <APPROVED_DATE> --persist --actor <ADMIN_UID> --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE`
   submit it, and run the single-admin review/confirmation on the new version.
4. Approve the 3 % monthly rate for the current month and the 10/5/3 commission plan the same way.
5. Leave Financial Automation OFF until the separate activation checkpoint.

## 7. Deployment procedure (only after explicit approval; each step is a checkpoint)

LOCAL PC
```powershell
cd C:\xampp\htdocs\coin-platform
php vendor/bin/phpunit tests/feature/SingleAdminGovernanceTest.php --no-coverage
git add <reviewed files>; git commit -m "Add single-admin financial governance workflow"; git push origin main
```

LIVE SERVER
```bash
cd /home/wwwzeont/ngw.zodianwealth.info
git status && git fetch origin && git log --oneline HEAD..origin/main
git pull --ff-only origin main
# verified backup first (runbook Step 3), then:
php spark migrate:status          # pending: 2026-11-20-997001 CreateFinancialGovernanceActions
php spark migrate
php spark governance:status       # mode=dual_control (nothing changed yet)
```
Then edit `.env` (never commit it):
```ini
platform.financialGovernanceMode = single_admin
platform.financialSingleAdminUserId = <owner admin users.id>
```
`php spark governance:status` must report `mode: single_admin`, `ready: true`. Keep all 8 kill-switches `false`.
Continue with section 6.

**Rollback:** set `platform.financialGovernanceMode = dual_control` (self-approvals immediately stop counting for
readiness and no new ones are possible). The table can stay; `php spark migrate:rollback` only if no governance rows
need to be kept (not recommended — it is the audit trail).
