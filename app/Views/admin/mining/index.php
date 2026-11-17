<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'mining']) ?>
<p class="page-subtitle">Phase 11A mining control panel. All figures below are <strong>DRY-RUN / SIMULATION</strong> unless posting is separately authorized.</p>
<div class="alert alert-warning mb-3">
    Mining Preview — Actual Credits Not Activated · Posting kill-switch OFF · P2P OFF · No Enable Real Mining control in this phase.
</div>

<ul class="nav nav-tabs mb-3">
    <?php
    $tabs = [
        'overview' => 'Overview',
        'rates'    => 'Monthly Rate Settings',
        'preview'  => 'Daily Mining Preview',
        'wallets'  => 'Mining Wallet Monitoring',
        'p2p'      => 'P2P Monitoring',
        'history'  => 'Run History',
    ];
    foreach ($tabs as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link<?= $tab === $key ? ' active' : '' ?>" href="<?= site_url('admin/mining?tab=' . $key . ($key === 'preview' ? '&date=' . rawurlencode($date) : '')) ?>"><?= esc($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
    <?= view('components/kpi_grid', ['kpis' => $kpis]) ?>
    <div class="row g-3 my-2">
        <div class="col-md-6">
            <div class="card-premium h-100">
                <h2 class="h5 mb-3">Kill-switches / assumptions</h2>
                <ul class="small mb-0">
                    <li>mining_posting_enabled=<?= ! empty($status['mining_posting_enabled']) ? 'true' : 'false' ?></li>
                    <li>mining_p2p_enabled=<?= ! empty($status['mining_p2p_enabled']) ? 'true' : 'false' ?></li>
                    <li>timezone=<?= esc((string) ($status['business_timezone'] ?? '')) ?></li>
                    <li>divisor_mode=<?= esc((string) ($status['month_day_divisor_mode'] ?? '')) ?> <span class="text-warning">(DRAFT)</span></li>
                    <li>approval_day_policy=<?= esc((string) ($status['approval_day_policy'] ?? '')) ?> <span class="text-warning">(DRAFT)</span></li>
                    <li>earning_asset=<?= esc((string) ($status['earning_asset'] ?? '')) ?> <span class="text-warning">(DRAFT)</span></li>
                    <li>scheduler_activated=false</li>
                </ul>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-premium h-100">
                <h2 class="h5 mb-3">Legacy mandatory lock preview</h2>
                <p class="small text-secondary">Read-only. No historical backfill performed.</p>
                <?php if ($missing === []): ?>
                    <div class="text-secondary">No missing mandatory locks in sample.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Purchase</th><th>Member</th><th>Qty</th></tr></thead>
                            <tbody>
                            <?php foreach ($missing as $m): ?>
                                <tr>
                                    <td><code><?= esc($m['purchase_ref']) ?></code></td>
                                    <td><?= esc($m['member_id']) ?></td>
                                    <td><?= esc($m['coin_quantity']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'rates'): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Monthly Rate Settings</h2>
        <?php if ($canPropose): ?>
            <a class="btn btn-gold btn-sm" href="<?= site_url('admin/mining/rates/create') ?>">New Draft Rate</a>
        <?php endif; ?>
    </div>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead>
            <tr>
                <th>Ref</th><th>Month</th><th>Rate %</th><th>Basis</th><th>Status</th><th>Approved</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rates === []): ?>
                <tr><td colspan="7" class="text-secondary">No rates configured.</td></tr>
            <?php endif; ?>
            <?php foreach ($rates as $rate): ?>
                <tr>
                    <td><code><?= esc($rate['rate_ref']) ?></code></td>
                    <td><?= esc($rate['effective_month']) ?></td>
                    <td><?= esc($rate['rate_percent']) ?></td>
                    <td><?= esc($rate['rate_basis']) ?></td>
                    <td><?= esc($rate['status']) ?></td>
                    <td class="small"><?= esc((string) ($rate['approved_at'] ?? '—')) ?></td>
                    <td><a href="<?= site_url('admin/mining/rates/' . rawurlencode($rate['rate_ref'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'preview'): ?>
    <form class="row g-2 align-items-end mb-3" method="get" action="<?= site_url('admin/mining') ?>">
        <input type="hidden" name="tab" value="preview">
        <div class="col-auto">
            <label class="form-label">Business date</label>
            <input class="form-control form-control-sm" type="date" name="date" value="<?= esc($date) ?>">
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-gold btn-sm" type="submit">Filter</button>
        </div>
        <?php if ($canSimulate): ?>
            <div class="col-auto">
                <button class="btn btn-gold btn-sm" type="submit" form="dryRunForm">Persist DRY-RUN</button>
            </div>
        <?php endif; ?>
    </form>
    <?php if ($canSimulate): ?>
        <form id="dryRunForm" method="post" action="<?= site_url('admin/mining/simulate') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="date" value="<?= esc($date) ?>">
        </form>
    <?php endif; ?>
    <div class="alert alert-info small">
        Formula (DRAFT): <?= esc((string) ($preview['assumptions']['formula'] ?? '')) ?>
        · Unconfirmed: <?= esc(implode(', ', $preview['assumptions']['unconfirmed'] ?? [])) ?>
    </div>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead>
            <tr>
                <th>Member</th><th>Eligible</th><th>Owned</th><th>Locked</th><th>Rate%</th><th>Expected</th><th>Posted</th><th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($preview['rows'] ?? [], 0, 100) as $row): ?>
                <tr>
                    <td><?= esc($row['member_id']) ?></td>
                    <td><?= esc($row['eligible_coins']) ?></td>
                    <td><?= esc($row['total_owned']) ?></td>
                    <td><?= esc($row['locked']) ?></td>
                    <td><?= esc((string) ($row['monthly_rate_percent'] ?? '—')) ?></td>
                    <td><?= esc($row['expected_amount']) ?></td>
                    <td><?= esc($row['posted_amount']) ?></td>
                    <td><?= esc($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'wallets'): ?>
    <p class="small text-secondary">Posted balances only. Simulation earnings are never shown as real credits.</p>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead>
            <tr><th>Member</th><th>Asset</th><th>Available</th><th>Credited</th><th>Debited</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($wallets === []): ?>
                <tr><td colspan="6" class="text-secondary">No mining wallets yet (expected while posting OFF).</td></tr>
            <?php endif; ?>
            <?php foreach ($wallets as $w): ?>
                <tr>
                    <td><?= esc($w['member_id']) ?></td>
                    <td><?= esc($w['asset_code']) ?></td>
                    <td><?= esc($w['available_balance']) ?></td>
                    <td><?= esc($w['total_credited']) ?></td>
                    <td><?= esc($w['total_debited']) ?></td>
                    <td><a href="<?= site_url('admin/mining/wallets/' . (int) $w['member_user_id']) ?>">Ledger</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'p2p'): ?>
    <div class="alert alert-warning">P2P Transfers Not Activated · Kill-switch OFF · No admin execution · Ledger balances are not editable here.</div>
    <?php if (! empty($p2pStatus)): ?>
        <ul class="small mb-3">
            <li>mining_p2p_enabled=<?= ! empty($p2pStatus['mining_p2p_enabled']) ? 'true' : 'false' ?></li>
            <li>asset=<?= esc((string) ($p2pStatus['asset_code'] ?? '')) ?> <?= empty($p2pStatus['asset_approved']) ? '(DRAFT)' : '' ?></li>
            <li>execution_authorized=<?= ! empty($p2pStatus['execution_authorized']) ? 'true' : 'false' ?></li>
        </ul>
    <?php endif; ?>
    <?php if (! empty($p2pReconcile)): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="card-premium p-3"><div class="small text-secondary">Completed</div><div class="h5 mb-0"><?= esc((string) ($p2pReconcile['summary']['completed'] ?? 0)) ?></div></div></div>
            <div class="col-md-3"><div class="card-premium p-3"><div class="small text-secondary">Failed</div><div class="h5 mb-0"><?= esc((string) ($p2pReconcile['summary']['failed'] ?? 0)) ?></div></div></div>
            <div class="col-md-3"><div class="card-premium p-3"><div class="small text-secondary">Volume</div><div class="h5 mb-0"><?= esc((string) ($p2pReconcile['summary']['volume'] ?? '0')) ?></div></div></div>
            <div class="col-md-3"><div class="card-premium p-3"><div class="small text-secondary">Reconcile Mismatches</div><div class="h5 mb-0"><?= esc((string) ($p2pReconcile['summary']['mismatches'] ?? 0)) ?></div></div></div>
        </div>
        <?php if (! empty($p2pReconcile['mismatches'])): ?>
            <div class="alert alert-danger small">
                Suspicious / mismatch alerts:
                <?php foreach (array_slice($p2pReconcile['mismatches'], 0, 10) as $m): ?>
                    <div><?= esc(json_encode($m, JSON_UNESCAPED_SLASHES)) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <form method="get" class="row g-2 mb-3">
        <input type="hidden" name="tab" value="p2p">
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="date_from" value="<?= esc($p2pFilters['date_from'] ?? '') ?>" placeholder="From"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="date_to" value="<?= esc($p2pFilters['date_to'] ?? '') ?>" placeholder="To"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="text" name="member_id" value="<?= esc($p2pFilters['member_id'] ?? '') ?>" placeholder="Member ID"></div>
        <div class="col-md-2">
            <select class="form-select form-select-sm" name="status">
                <option value="">All statuses</option>
                <?php foreach (['completed', 'failed', 'rejected', 'pending'] as $st): ?>
                    <option value="<?= $st ?>" <?= (($p2pFilters['status'] ?? '') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="text" name="transfer_ref" value="<?= esc($p2pFilters['transfer_ref'] ?? '') ?>" placeholder="Transfer ref"></div>
        <div class="col-md-2"><button class="btn btn-outline-gold btn-sm w-100" type="submit">Filter</button></div>
    </form>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead>
            <tr><th>Ref</th><th>Created</th><th>Sender</th><th>Receiver</th><th>Amount</th><th>Asset</th><th>Status</th><th>Failure</th></tr>
            </thead>
            <tbody>
            <?php if (empty($p2pTransfers)): ?>
                <tr><td colspan="8" class="text-secondary">No P2P transfers recorded.</td></tr>
            <?php else: ?>
                <?php foreach ($p2pTransfers as $t): ?>
                    <tr>
                        <td><code><?= esc($t['transfer_ref']) ?></code></td>
                        <td><?= esc($t['created_at']) ?></td>
                        <td><?= esc($t['sender_member_id']) ?></td>
                        <td><?= esc($t['receiver_member_id']) ?></td>
                        <td><?= esc($t['amount']) ?></td>
                        <td><?= esc($t['asset_code']) ?></td>
                        <td><?= esc($t['status']) ?></td>
                        <td class="small"><?= esc((string) ($t['failure_reason'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'history'): ?>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead>
            <tr><th>Date</th><th>Run</th><th>Type</th><th>Status</th><th>Eligible</th><th>Expected</th><th>Posted</th><th>Error</th></tr>
            </thead>
            <tbody>
            <?php if ($runs === []): ?>
                <tr><td colspan="8" class="text-secondary">No dry-run history yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><?= esc($run['business_date']) ?></td>
                    <td><code><?= esc($run['run_ref']) ?></code></td>
                    <td><?= esc($run['run_type']) ?></td>
                    <td><?= esc($run['status']) ?></td>
                    <td><?= esc((string) $run['eligible_count']) ?></td>
                    <td><?= esc($run['total_expected_amount']) ?></td>
                    <td><?= esc($run['total_posted_amount']) ?></td>
                    <td class="small"><?= esc((string) ($run['error_summary'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
