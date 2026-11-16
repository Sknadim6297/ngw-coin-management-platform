# Dashboard KPI Business Rules

Business timezone for “today” boundaries: `Config\App::$appTimezone` (currently **UTC**).
Day range: `[today 00:00:00, tomorrow 00:00:00)`.

Dashboards are **read-only**. They never credit wallets, approve purchases, mint mining, or write commission ledgers.

---

## Shared definitions

| Metric | Definition | Source | Status |
|--------|------------|--------|--------|
| **Active Member** | `member_profiles.account_status = 'active'` | Activated on first approved coin purchase | Implemented |
| **Inactive Member** | `member_profiles.account_status = 'inactive'` | Default at registration | Implemented |
| **Total Coins** | Platform inventory ever added | `coin_inventory.total_added` | Implemented |
| **Used Coins** | Inventory allocated to members via approved purchases | `coin_inventory.total_allocated` | Implemented |
| **Available Inventory Coins** | Sellable platform stock | `coin_inventory.available_balance` | Implemented |
| **Purchased Coins** | Sum of approved purchase quantities | `SUM(coin_purchase_requests.coin_quantity) WHERE status='approved'` | Implemented |
| **Total Locked Coins** | Sum of member locked balances | `SUM(member_coin_balances.locked_balance)` | Implemented |
| **Total Available Member Coins** | Sum of member available balances | `SUM(member_coin_balances.available_balance)` | Implemented |
| **Pending Coin Purchases** | Purchase requests awaiting review | `status='pending'` | Implemented |
| **Approved Coin Purchases** | Approved purchase request count | `status='approved'` | Implemented |
| **Pending Payment Verification** | Deposits awaiting verification | `usdt_deposit_requests.status IN ('submitted','under_review')` | Implemented |
| **Direct Referrals** | Members whose sponsor is this member | `sponsor_relationships.sponsor_user_id` | Implemented |
| **Total Team** | Recursive downline via sponsor links (BFS) | `sponsor_relationships` + `member_profiles` | Implemented |
| **Today's / Total Mining** | Mining earnings | — | **Not available** (module not implemented) |
| **Today's / Total Level Commission** | Level commission earnings | — | **Not available** (module not implemented) |

### Important distinctions

- **Used Coins ≠ Purchased Coins by assumption.** Used = inventory `total_allocated`. Purchased = sum of approved request quantities. They should match under normal flows; admin inventory corrections or reversals can diverge.
- **Member “My Purchased Coins”** uses ownership `total_acquired` (ledger purchase credits), not a fabricated wallet.
- **Mining and commissions are not interchangeable** and are not shown as zero earnings.
- Lock/unlock never changes total owned: `balance = available_balance + locked_balance`.

---

## Admin KPIs

See `AdminDashboardService::kpis()`.

## Member KPIs

All scoped to the authenticated `member_user_id`. See `MemberDashboardService::kpis()`.
Team metrics never expose other members’ balances or PII beyond aggregate counts.
