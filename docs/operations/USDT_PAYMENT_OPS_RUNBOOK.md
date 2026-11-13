# USDT Payment Operations Runbook (Phase 4C)

**Scope:** Operational readiness for the Phase 4B EVM USDT verification and purchase reconciliation stack.  
**Does not:** activate live cryptocurrency payments, deploy to production, or auto-repair ledgers.

---

## 1. Network selection and verification

1. Choose **one** EVM-compatible network for the initial go-live (e.g. Ethereum mainnet or a vetted L2).
2. Confirm the **USDT token contract** for that exact chain (contracts are not interchangeable across networks).
3. Create/update an admin **Payment Network** row with:
   - `verifier_type = evm_erc20`
   - Correct `chain_id`
   - Token contract, decimals, destination wallet
   - `required_confirmations` (conservative)
   - Explorer TX URL template (`…/{tx_hash}`)
4. Do **not** enable TRON or non-EVM networks with the EVM verifier.

## 2. Wallet ownership confirmation

1. Confirm the destination wallet is controlled by the organization.
2. Document custody (who holds keys/seed — never store them in source, `.env`, or the database).
3. Prefer a dedicated deposit address; rotate only with dual-control change management.

## 3. RPC endpoint setup

1. Obtain a **trusted** HTTPS JSON-RPC endpoint (provider account under company control).
2. Set in `.env` (example):

```env
blockchain.depositVerifier = evm_erc20
blockchain.evmRpcUrl = https://YOUR-TRUSTED-RPC-HOST/...
blockchain.rpcTimeoutSeconds = 10
blockchain.rpcMaxRetries = 2
blockchain.rpcHostAllowlist = YOUR-TRUSTED-RPC-HOST
blockchain.verificationMaxAttempts = 8
blockchain.verificationRetryBackoffSeconds = 60
```

3. Never put private keys in env. Never accept user-supplied RPC URLs.
4. Optional per-network override: `rpc_url_env_key` must look like `blockchain.someKey`.

## 4. Safe configuration procedure

1. Keep `platform.requireVerifiedDepositForApproval = true`.
2. Run **Admin → Preflight** and resolve all blockers.
3. Run **Admin → Payment Health → Run RPC Probe**.
4. Confirm chain ID consistency and head block populate.
5. Leave `depositVerifier = not_configured` until the above passes in the target environment.

## 5. Testnet validation / known-transaction dry runs

1. Prefer a testnet USDT contract + funded test wallet first.
2. Submit a deposit with a **known** successful Transfer to the destination.
3. Use **Retry Verification**; expect classified outcomes (wrong token, insufficient confirmations, etc.) for negative cases.
4. Confirm: no coin credit before `verified`; approval consumes allocation exactly once.

## 6. Monitoring and alert response

| Signal | Where | Action |
|--------|-------|--------|
| RPC down / wrong chain | Payment Health | Fail closed; do not approve unpaid purchases |
| Failures by category | Payment Health | Investigate terminal vs retryable |
| Integrity flags | Payment Audit / Reconciliation | Manual investigation only |
| Orphan allocations / amount mismatch | Reconciliation | Escalate; **do not** silent-repair |

## 7. Incident handling

1. **Suspected reorg** after verify: run Integrity Re-check. System flags `reorg_suspected` / `needs_investigation` and **does not** reverse ownership or inventory.
2. Freeze further approvals if critical reconciliation findings appear.
3. Preserve deposit events and audit logs; export for forensics.
4. Only reverse financial state via a controlled, dual-approved manual process outside automated tools (Phase 4C does not provide auto-reversal).

## 8. Database backup and recovery

1. Before any migration or cutover: full MySQL dump of the application schema.
2. Verify restore on a staging host.
3. After restore: run migrations if needed (`php spark migrate`), then Preflight + Reconciliation (read-only).

## 9. Rollback procedure

1. Set `blockchain.depositVerifier = not_configured` to disable live verification immediately.
2. Keep the payment approval gate **enabled** so unpaid approvals stay blocked.
3. Do not drop Phase 4B/4C tables in production rollback; leave schema additive.

## 10. Deployment checklist (Windows Plesk / IIS)

- [ ] PHP **8.2+**
- [ ] Extensions: `intl` (**blocker if missing**), `curl`, `openssl`, `mbstring`, `json`, `pdo_mysql`, `bcmath`
- [ ] On Plesk: Domains → PHP Settings → enable `intl`; recycle IIS app pool / PHP-FPM
- [ ] `writable/` and `writable/uploads/deposit_evidence` writable by the app pool identity
- [ ] HTTPS site binding + correct `app.baseURL`
- [ ] `.env` secrets not world-readable
- [ ] Migrations applied (`php spark migrate`) — never destructive reset
- [ ] Preflight all green / only accepted warnings
- [ ] RPC probe OK; chain ID matches
- [ ] Payment gate enabled
- [ ] Ops sign-off recorded before enabling `evm_erc20`

## 11. Explicit non-goals

- No automatic ROI, mining returns, commissions, withdrawals, or P2P in this phase.
- No production deployment from this runbook alone.
- No claim of production readiness unless every required preflight check passes **and** business/compliance review is complete.
