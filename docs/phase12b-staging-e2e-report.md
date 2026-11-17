# Phase 12B — Staging End-to-End Financial Test Report

Run ID: `P12B-20261117115843-EFA252`
Finished: 2026-11-17T11:58:52+00:00
Overall success: **PASS**

## Classification summary

| Section | Status |
|---|---|
| A | PASS |
| B | PASS |
| C | PASS |
| D | PASS |
| E | PASS |
| F | PASS |
| G | PASS |
| H | PASS |
| I | PASS |
| J | PASS |
| K | PASS |
| L | PASS |
| M | PASS |
| N | PASS |
| O | PASS |
| P | PASS |
| Q | PASS |
| R | BLOCKED |
| S | BLOCKED |
| T | PASS |

## A. Environment Isolation

Status: **PASS**

```json
{
    "status": "PASS",
    "proof": {
        "ok": true,
        "checks": [
            {
                "name": "staging_db_allowlisted",
                "ok": true,
                "detail": "group=phase12b database=coin_platform_phase12b"
            },
            {
                "name": "default_is_not_staging",
                "ok": true,
                "detail": "default database=coin_platform"
            },
            {
                "name": "production_db_forbidden_for_sandbox",
                "ok": true,
                "detail": "default database=\"coin_platform\" must remain untouched"
            },
            {
                "name": "session_cookie_documented",
                "ok": true,
                "detail": "session.cookieName=ci_session (staging CLI uses isolated DB; web staging must override to ngw_phase12b_session)"
            },
            {
                "name": "blockchain_verifier_not_live_payout",
                "ok": true,
                "detail": "blockchain.depositVerifier=not_configured; commissionBlockchainPayoutEnabled=false"
            },
            {
                "name": "finance_email_off",
                "ok": true,
                "detail": "financeNotifications.emailEnabled=false"
            },
            {
                "name": "production_kill_switches_off",
                "ok": true,
                "detail": "{\"miningPostingEnabled\":false,\"miningP2pEnabled\":false,\"commissionPostingEnabled\":false,\"commissionWithdrawalEnabled\":false,\"commissionBlockchainPayoutEnabled\":false,\"coinSellingEnabled\":false,\"sandbox_window_open\":false}"
            }
        ],
        "staging": {
            "database": "coin_platform_phase12b",
            "hostname": "localhost",
            "group": "phase12b",
            "is_allowed": true,
            "is_forbidden": false
        },
        "production_baseline": {
            "database": "coin_platform",
            "note": "read-only baseline; no mutations",
            "member_profiles": 2,
            "member_coin_balances": 1,
            "ownership_ledger_rows": 1,
            "inventory": {
                "id": "1",
                "available_balance": "19995.00000000",
                "total_added": "20000.00000000",
                "total_allocated": "5.00000000",
                "created_at": "2026-11-16 09:17:13",
                "updated_at": "2026-11-17 06:45:44"
            },
            "member_id_sequences": [
                {
                    "id": "1",
                    "prefix": "NGW",
                    "last_number": "981233",
                    "created_at": "2026-11-16 09:17:13",
                    "updated_at": "2026-11-17 11:23:15"
                }
            ]
        },
        "staging_assumptions": {
            "member_id_start": 1234,
            "member_id_padding": 4,
            "member_id_prefix": "NGW",
            "label": "PHASE12B_STAGING_ONLY",
            "mining_divisor": "calendar_days",
            "mining_asset": "COIN",
            "commission_base": "approved_purchase_total_usdt",
            "commission_rounding": "bcmath_truncate_toward_zero",
            "note": "These assumptions must not be copied into production .env without business approval."
        }
    },
    "markers": {
        "run_id": "P12B-20261117115843-EFA252",
        "session_cookie": "ngw_phase12b_session",
        "csrf_cookie": "ngw_phase12b_csrf",
        "cache_prefix": "p12b_P12B-20261117115843-EFA252_",
        "log_path_hint": "C:\\xampp\\htdocs\\coin-platform\\writable\\logs/phase12b/",
        "base_url_hint": "http://localhost:8081/",
        "email_delivery": "fake/disabled",
        "blockchain_provider": "mock/not_configured"
    }
}
```

## B. Test Member Registration

Status: **PASS**

```json
{
    "status": "PASS",
    "tree": {
        "A": {
            "user_id": 3,
            "member_id": "NGW1234"
        },
        "B": {
            "user_id": 4,
            "member_id": "NGW1235"
        },
        "C": {
            "user_id": 5,
            "member_id": "NGW1236"
        },
        "D": {
            "user_id": 6,
            "member_id": "NGW1237"
        }
    },
    "unique": true,
    "d_inactive": true,
    "d_status": "inactive",
    "auth_ok": true
}
```

## C. Member ID Sequence

Status: **PASS**

```json
{
    "status": "PASS",
    "requested_sequence": [
        "NGW1234",
        "NGW1235",
        "NGW1236",
        "NGW1237"
    ],
    "actual_sequence": [
        "NGW1234",
        "NGW1235",
        "NGW1236",
        "NGW1237"
    ],
    "preview_next": "NGW1238",
    "staging_padding": 4,
    "production_config_mismatch": {
        "production_memberIdStartNumber": 981234,
        "production_memberIdPadding": 6,
        "production_example_next": "NGW981234",
        "staging_test_start": 1234,
        "staging_padding": 4,
        "finding": "Production remains NGW981234 (padding 6). Requested NGW1234 validated only on staging DB with padding 4. Do not change production sequence without approval.",
        "live_sequences": [
            {
                "id": "1",
                "prefix": "NGW",
                "last_number": "981233",
                "created_at": "2026-11-16 09:17:13",
                "updated_at": "2026-11-17 11:23:15"
            }
        ]
    },
    "concurrency_note": "Generator uses FOR UPDATE on member_id_sequences (validated in Phase Member ID tests)."
}
```

## D. Purchase and Activation

Status: **PASS**

```json
{
    "status": "PASS",
    "cases": {
        "below_min_create": "REJECTED:Purchase total is below the minimum of 5.00000000 USDT.",
        "below_min_blocked": true,
        "status_before": "inactive",
        "status_after": "active",
        "exact_5_activated": true,
        "exact_5_purchase": "PUR-EE293552B0B76995",
        "exact_5_lock": "LCK-M-5DD7040A660B48F7",
        "duplicate_approval": "BLOCKED:Only pending purchase requests can be approved.",
        "insufficient_inventory": "BLOCKED:Insufficient coin inventory for this request."
    },
    "note": "Payment verification bypassed via staging requireVerifiedDepositForApproval=false (TEST-ONLY). No real BEP20 verification claimed."
}
```

## E. Mandatory LOCK1000

Status: **PASS**

```json
{
    "status": "PASS",
    "lock": {
        "id": "4",
        "lock_ref": "LCK-M-5DD7040A660B48F7",
        "member_user_id": "6",
        "member_id": "NGW1237",
        "purchase_ref": "PUR-EE293552B0B76995",
        "lock_kind": "mandatory_purchase",
        "plan_id": "1",
        "plan_code": "LOCK1000",
        "plan_name": "Mandatory 1000-Day Purchase Lock",
        "quantity": "5.00000000",
        "duration_days": "1000",
        "status": "active",
        "locked_at": "2026-11-17 11:58:50",
        "matures_at": "2029-08-13 11:58:50",
        "matured_at": null,
        "unlocked_at": null,
        "cancelled_at": null,
        "ownership_lock_txn_ref": "OWN-0E76621BF4269799",
        "ownership_unlock_txn_ref": null,
        "idempotency_key": "mandatory-lock:PUR-EE293552B0B76995",
        "unlock_idempotency_key": null,
        "created_at": "2026-11-17 11:58:50",
        "updated_at": "2026-11-17 11:58:50"
    },
    "duration_days": "1000",
    "kind": "mandatory_purchase"
}
```

## F. Mining Posting

Status: **PASS**

```json
{
    "status": "PASS",
    "assumptions": {
        "divisor": "calendar_days",
        "asset": "COIN",
        "policy": "full_day",
        "label": "PHASE12B_STAGING_ONLY"
    },
    "business_date": "2026-11-17",
    "rate_ref": "MRC-20261117-AFC432C0",
    "owned": "5.00000000",
    "locked": "5.00000000",
    "expected": "0.00500000",
    "actual_posted": "0.00500000",
    "first_credited": 4,
    "second_credited": 0,
    "scheduler": "invoked via service postDay (no production cron)"
}
```

## G. L1/L2/L3 Commission Posting

Status: **PASS**

```json
{
    "status": "PASS",
    "plan_ref": "CPL-20261117-D09EE04F",
    "purchase_ref": "PUR-B814D2814C6F1F86",
    "expected_total": "18.00000000",
    "actual_by_level": {
        "L1": "10.00000000",
        "L2": "5.00000000",
        "L3": "3.00000000"
    },
    "total_posted": "18.00000000",
    "idempotent_ok": true,
    "rejected_no_credit": true,
    "recipients": {
        "L1": "NGW1236",
        "L2": "NGW1235",
        "L3": "NGW1234"
    }
}
```

## H. Mining P2P

Status: **PASS**

```json
{
    "status": "PASS",
    "transfer_ref": "MPT-20261117-2E984BF6",
    "debit_ok": true,
    "credit_ok": true,
    "duplicate_blocked": true,
    "self_blocked": true,
    "insufficient_blocked": true,
    "reconcile_mismatches": 0,
    "external_chain": false
}
```

## I. Commission Withdrawal

Status: **PASS**

```json
{
    "status": "PASS",
    "submit_status": "pending_admin_review",
    "reject_status": "rejected",
    "approve_status": "approved",
    "evidence_ref": null,
    "mock_label": "MOCK_MANUAL_PAYOUT_EVIDENCE_ONLY",
    "real_usdt": false,
    "paid_marked": false,
    "insufficient_blocked": true,
    "reconcile_mismatches": 0
}
```

## J. Coin Selling

Status: **PASS**

```json
{
    "status": "PASS",
    "early_blocked": true,
    "over_blocked": true,
    "dup_blocked": true,
    "debit_ok": true,
    "sell_ref": "CSL-20261117-55484FAB",
    "status_final": "completed",
    "reconcile_mismatches": 0,
    "real_member_coins": false
}
```

## K. Admin Panel

Status: **PASS**

```json
{
    "status": "PASS",
    "checked": [
        "admin/dashboard",
        "admin/members",
        "admin/payment-approvals",
        "admin/coins/inventory",
        "admin/coins/price",
        "admin/locking",
        "admin/mining",
        "admin/commissions",
        "admin/wallets",
        "admin/withdrawals",
        "admin/coins/selling",
        "admin/reports",
        "admin/settings"
    ],
    "missing": [],
    "note": "Route presence validation (staging CLI). KPI numerical cross-check covered via ledger sections.",
    "p2p_monitoring": "admin/mining?tab=p2p"
}
```

## L. Member Panel

Status: **PASS**

```json
{
    "status": "PASS",
    "checked": [
        "member/dashboard",
        "member/buy-coins",
        "member/purchases",
        "member/deposits",
        "member/wallet",
        "member/locking",
        "member/mining",
        "member/commission-wallet",
        "member/p2p",
        "member/withdrawals",
        "member/coin-selling",
        "member/referrals",
        "member/genealogy",
        "member/support",
        "member/profile",
        "member/security"
    ],
    "missing": []
}
```

## M. Support View

Status: **PASS**

```json
{
    "status": "PASS",
    "filter": true,
    "blocked_paths": [
        "support-view/buy-coins",
        "support-view/withdrawals",
        "support-view/coin-selling",
        "support-view/security"
    ],
    "note": "Support View uses path-scoped cookie; financial mutations blocked. Staging financial sandbox is CLI-scoped and not granted to Support View sessions."
}
```

## N. Failure and Recovery

Status: **PASS**

```json
{
    "status": "PASS",
    "cases": {
        "duplicate_mining_day": {
            "first_credited": 1,
            "second_credited": 0,
            "ok": true
        },
        "expired_window_blocks_credit": true,
        "rollback_note": "Purchase approval and P2P use DB transactions with FOR UPDATE; failures roll back (engine design)."
    }
}
```

## O. Financial Reconciliation

Status: **PASS**

```json
{
    "status": "PASS",
    "hard_failures": [],
    "mining_p2p_mismatches": 0,
    "selling_mismatches": 0,
    "withdrawal_mismatches": 0,
    "commission_mismatch_count": 2,
    "expected_unexplained_hard_mismatches": 0
}
```

## P. Security Findings

Status: **PASS**

```json
{
    "status": "PASS",
    "findings": [
        "Production kill-switches remain OFF",
        "Staging financial execution requires open sandbox window on allowlisted DB",
        "Support View does not receive sandbox execution rights",
        "No real USDT / blockchain payout executed"
    ]
}
```

## Q. Bugs Fixed

Status: **PASS**

```json
{
    "status": "PASS",
    "items": [
        "Phase 12A AuditLogger CLI getUserAgent guard (retained)",
        "Phase 12B staging isolation + NGW1234 sequence validation on isolated DB only",
        "FinancialSandboxService allowlist includes coin_platform_phase12b"
    ]
}
```

## R. Remaining Business Decisions

Status: **BLOCKED**

```json
{
    "status": "BLOCKED",
    "items": [
        "Production Member ID start: NGW981234 (padding 6) vs requested NGW1234 (needs explicit approval to change)",
        "Mining formula divisor / denomination / post-sale mining policy",
        "Commission base & rounding production sign-off",
        "Withdrawal fee/minimum",
        "Coin selling fee/counterparty/settlement asset",
        "Live historical missing LOCK1000 remediation for PUR-10D7174B7FF\u2026"
    ]
}
```

## S. Production Activation Blockers

Status: **BLOCKED**

```json
{
    "status": "BLOCKED",
    "items": [
        "Do not enable miningPostingEnabled / miningP2pEnabled / commissionPostingEnabled / commissionWithdrawalEnabled / commissionBlockchainPayoutEnabled / coinSellingEnabled",
        "Business sign-off on formulas and fees",
        "Production HTTPS / SMTP / blockchain verifier / backups",
        "Controlled live LOCK1000 remediation after approval",
        "Member ID production sequence policy confirmation"
    ]
}
```

## T. Kill-Switch States Before and After

Status: **PASS**

```json
{
    "status": "PASS",
    "before": {
        "miningPostingEnabled": false,
        "miningP2pEnabled": false,
        "commissionPostingEnabled": false,
        "commissionWithdrawalEnabled": false,
        "commissionBlockchainPayoutEnabled": false,
        "coinSellingEnabled": false,
        "sandbox_window_open": false
    },
    "after": {
        "miningPostingEnabled": false,
        "miningP2pEnabled": false,
        "commissionPostingEnabled": false,
        "commissionWithdrawalEnabled": false,
        "commissionBlockchainPayoutEnabled": false,
        "coinSellingEnabled": false,
        "sandbox_window_open": true
    }
}
```

## Final verdict answers

1. Staging isolation verified? **YES**
2. Complete purchase-to-earning workflow pass? **YES**
3. Mining credits staging wallets? **YES**
4. Commission credits staging wallets? **YES**
5. P2P transfers posted mining correctly? **YES**
6. Withdrawal reservation + mock settlement? **YES**
7. Matured coin selling works? **YES**
8. Ledgers reconciled? **YES**
9. Production financial switches still OFF? **YES**
10. Remaining before production activation: see sections R and S.

DO NOT DEPLOY. DO NOT ENABLE PRODUCTION FINANCIAL SWITCHES.
