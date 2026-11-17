<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'commissions']) ?>
<p class="page-subtitle">Phase 11B purchase-triggered commission engine. Estimates are <strong>not</strong> withdrawable. Posting kill-switch remains OFF.</p>
<div class="alert alert-warning mb-3">
    COMMISSION POSTING IS NOT CREDITING WALLETS · Default display rates L1 10% / L2 5% / L3 3% · Draft base = approved purchase USDT (pending sign-off)
</div>

<ul class="nav nav-tabs mb-3">
    <?php
    $tabs = [
        'overview'   => 'Overview',
        'rates'      => 'Dynamic Rates',
        'preview'    => 'Purchase Preview',
        'proposals'  => 'Commission Proposals',
        'ledger'     => 'Commission Ledger',
        'reconcile'  => 'Reconciliation',
    ];
    foreach ($tabs as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link<?= $tab === $key ? ' active' : '' ?>" href="<?= site_url('admin/commissions?tab=' . $key) ?>"><?= esc($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
    <?= view('components/kpi_grid', ['kpis' => $kpis]) ?>
    <div class="row g-3 my-2">
        <div class="col-md-6">
            <div class="card-premium h-100">
                <h2 class="h5 mb-3">Active plan / kill-switches</h2>
                <?php if (empty($status['active_plan'])): ?>
                    <div class="text-secondary mb-2">No approved plan covering today. Create &amp; dual-approve a plan (defaults 10/5/3).</div>
                <?php else: ?>
                    <?php $p = $status['active_plan']; $lv = $p['levels'] ?? []; ?>
                    <div><code><?= esc($p['plan_ref']) ?></code> · <?= esc($p['plan_name']) ?></div>
                    <div class="mt-2">L1 <?= esc((string) ($lv[1] ?? '—')) ?>% · L2 <?= esc((string) ($lv[2] ?? '—')) ?>% · L3 <?= esc((string) ($lv[3] ?? '—')) ?>%</div>
                <?php endif; ?>
                <ul class="small mt-3 mb-0">
                    <li>commission_posting_enabled=<?= ! empty($status['commission_posting_enabled']) ? 'true' : 'false' ?></li>
                    <li>commission_withdrawal_enabled=<?= ! empty($status['commission_withdrawal_enabled']) ? 'true' : 'false' ?></li>
                    <li>blockchain_payouts=<?= ! empty($status['blockchain_payouts']) ? 'true' : 'false' ?></li>
                </ul>
                <div class="mt-3 d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commissions?tab=rates') ?>">Rates</a>
                    <?php if ($canPropose): ?>
                        <a class="btn btn-gold btn-sm" href="<?= site_url('admin/commissions/plans/create') ?>">New Draft Plan</a>
                    <?php endif; ?>
                    <?php if ($canWallet): ?>
                        <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commission-wallet') ?>">Wallet</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-premium h-100">
                <h2 class="h5 mb-3">Batch simulation (legacy)</h2>
                <?php if (empty($snap['last_run'])): ?>
                    <div class="text-secondary">No day-batch simulation runs yet.</div>
                <?php else: ?>
                    <div><code><?= esc($snap['last_run']['run_ref']) ?></code></div>
                    <div class="small text-secondary">Date <?= esc($snap['last_run']['calculation_date']) ?></div>
                    <div>Preview total: <?= esc($snap['last_run']['total_preview_amount']) ?> USDT (simulation only)</div>
                <?php endif; ?>
                <?php if ($canSimulate): ?>
                    <form class="mt-3" method="post" action="<?= site_url('admin/commissions/simulate') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="date" value="<?= esc(date('Y-m-d')) ?>">
                        <button class="btn btn-gold btn-sm" type="submit">Run Today Simulation</button>
                    </form>
                <?php endif; ?>
                <a class="btn btn-outline-gold btn-sm mt-2" href="<?= site_url('admin/commissions/history') ?>">Simulation History</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'rates'): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Dynamic Rates (L1 / L2 / L3)</h2>
        <?php if ($canPropose): ?>
            <a class="btn btn-gold btn-sm" href="<?= site_url('admin/commissions/plans/create') ?>">New Draft Plan</a>
        <?php endif; ?>
    </div>
    <p class="small text-secondary">Default display: L1 10% · L2 5% · L3 3%. Drafts require dual-control approval. Historical calcs keep their rate snapshot.</p>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead><tr><th>Ref</th><th>Name</th><th>From</th><th>To</th><th>L1</th><th>L2</th><th>L3</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($plans)): ?>
                <tr><td colspan="9" class="text-secondary">No plans yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($plans as $plan): ?>
                <?php $by = $plan['levels'] ?? service('commissionPlanService')->levelsFor((string) $plan['plan_ref']); ?>
                <tr>
                    <td><code><?= esc($plan['plan_ref']) ?></code></td>
                    <td><?= esc($plan['plan_name']) ?></td>
                    <td><?= esc($plan['effective_from']) ?></td>
                    <td><?= esc((string) ($plan['effective_to'] ?? '—')) ?></td>
                    <td><?= esc((string) ($by[1] ?? '—')) ?>%</td>
                    <td><?= esc((string) ($by[2] ?? '—')) ?>%</td>
                    <td><?= esc((string) ($by[3] ?? '—')) ?>%</td>
                    <td><?= esc($plan['status']) ?></td>
                    <td><a href="<?= site_url('admin/commissions/plans/' . rawurlencode($plan['plan_ref'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'preview'): ?>
    <form class="row g-2 align-items-end mb-3" method="get" action="<?= site_url('admin/commissions') ?>">
        <input type="hidden" name="tab" value="preview">
        <div class="col-md-4">
            <label class="form-label">Purchase ref</label>
            <input class="form-control form-control-sm" type="text" name="purchase" value="<?= esc($purchaseRef) ?>" placeholder="CPR-…">
        </div>
        <div class="col-auto"><button class="btn btn-gold btn-sm" type="submit">Preview</button></div>
    </form>
    <?php if (is_array($purchaseCalc) && empty($purchaseCalc['error'])): ?>
        <div class="alert alert-info small">
            Base (DRAFT)= purchase USDT · Expected total <?= esc($purchaseCalc['totals']['expected_amount_usdt']) ?> ·
            <?= esc($purchaseCalc['posting_status']) ?>
        </div>
        <div class="table-responsive card-premium mb-3">
            <table class="table table-sm mb-0">
                <thead><tr><th>Level</th><th>Beneficiary</th><th>Rate%</th><th>Expected</th><th>Posted</th><th>Status</th><th>Reasons</th></tr></thead>
                <tbody>
                <?php foreach ($purchaseCalc['lines'] as $line): ?>
                    <tr>
                        <td>L<?= (int) $line['sponsor_level'] ?></td>
                        <td><?= esc((string) ($line['beneficiary_member_id'] ?? '—')) ?></td>
                        <td><?= esc((string) ($line['rate_percent'] ?? '—')) ?></td>
                        <td><?= esc($line['expected_amount_usdt']) ?></td>
                        <td><?= esc($line['posted_amount_usdt']) ?></td>
                        <td><?= esc($line['status']) ?></td>
                        <td class="small"><?= esc(implode(',', $line['reason_codes'] ?? [])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif (is_array($purchaseCalc) && ! empty($purchaseCalc['error'])): ?>
        <div class="alert alert-danger"><?= esc($purchaseCalc['error']) ?></div>
    <?php endif; ?>

    <h2 class="h5">Recent purchase calculations</h2>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead><tr><th>Date</th><th>Purchase</th><th>L</th><th>Beneficiary</th><th>Rate</th><th>Expected</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($calcRows === []): ?>
                <tr><td colspan="7" class="text-secondary">No purchase calculations yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($calcRows as $row): ?>
                <tr>
                    <td><?= esc($row['business_date']) ?></td>
                    <td><code><?= esc($row['purchase_ref']) ?></code></td>
                    <td><?= (int) $row['sponsor_level'] ?></td>
                    <td><?= esc((string) ($row['beneficiary_member_id'] ?? '—')) ?></td>
                    <td><?= esc((string) ($row['rate_percent'] ?? '—')) ?></td>
                    <td><?= esc($row['expected_amount_usdt']) ?></td>
                    <td><?= esc($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'proposals'): ?>
    <div class="d-flex justify-content-between mb-3">
        <h2 class="h5 mb-0">Commission Proposals</h2>
        <?php if ($canPosting): ?>
            <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commission-postings') ?>">Open Postings</a>
        <?php endif; ?>
    </div>
    <p class="small text-secondary">Proposal approval never posts while the kill-switch is OFF. No Enable Posting control in this phase.</p>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead><tr><th>Ref</th><th>Purchase</th><th>Level</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($proposals === []): ?>
                <tr><td colspan="5" class="text-secondary">No proposals.</td></tr>
            <?php endif; ?>
            <?php foreach ($proposals as $p): ?>
                <tr>
                    <td><a href="<?= site_url('admin/commission-postings/' . rawurlencode($p['proposal_ref'])) ?>"><code><?= esc($p['proposal_ref']) ?></code></a></td>
                    <td><?= esc($p['purchase_ref']) ?></td>
                    <td><?= (int) $p['sponsor_level'] ?></td>
                    <td><?= esc($p['proposed_amount']) ?></td>
                    <td><?= esc($p['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'ledger'): ?>
    <div class="d-flex justify-content-between mb-3">
        <h2 class="h5 mb-0">Commission Ledger / Wallet</h2>
        <?php if ($canWallet): ?>
            <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commission-wallet') ?>">Open Wallet Monitor</a>
        <?php endif; ?>
    </div>
    <div class="alert alert-info small">Posted ledger entries only. Simulation and purchase-calc estimates are never shown as wallet balances.</div>
    <p class="text-secondary">Use the commission wallet screen for account balances and ledger history. Expected (unposted) amounts remain on the Purchase Preview tab.</p>
<?php endif; ?>

<?php if ($tab === 'reconcile' && is_array($reconcile)): ?>
    <h2 class="h5">Reconciliation (read-only)</h2>
    <p class="small text-secondary"><?= esc($reconcile['note'] ?? '') ?> · mismatches=<?= (int) ($reconcile['summary']['mismatches'] ?? 0) ?></p>
    <div class="table-responsive card-premium mb-3">
        <table class="table table-sm mb-0">
            <thead><tr><th>Type</th><th>Detail</th></tr></thead>
            <tbody>
            <?php if (empty($reconcile['mismatches'])): ?>
                <tr><td colspan="2" class="text-secondary">No calculation mismatches in sample.</td></tr>
            <?php endif; ?>
            <?php foreach ($reconcile['mismatches'] as $m): ?>
                <tr>
                    <td><?= esc($m['type'] ?? '') ?></td>
                    <td class="small"><code><?= esc(json_encode($m, JSON_UNESCAPED_SLASHES)) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (! empty($reconcile['wallet_issues'])): ?>
        <h3 class="h6">Wallet reconcile issues</h3>
        <ul class="small">
            <?php foreach ($reconcile['wallet_issues'] as $issue): ?>
                <li><?= esc(($issue['code'] ?? '') . ' — ' . ($issue['detail'] ?? '')) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
<?= $this->endSection() ?>
