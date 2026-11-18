<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">
    Central financial business rules hub. Drafts and approvals prepare activation —
    they never enable kill-switches or change live balances.
</p>

<?php $summary = $readiness['summary'] ?? []; $switches = $readiness['kill_switches'] ?? []; ?>

<div class="card-premium mb-3">
    <h2 class="h5 mb-3">Activation Readiness</h2>
    <div class="row g-2 small">
        <?php foreach ([
            'mining' => 'Mining Policy',
            'commission' => 'Commission Policy',
            'p2p' => 'P2P Policy',
            'withdrawal' => 'Withdrawal Policy',
            'selling' => 'Coin Selling Policy',
            'member_id_correction' => 'Member ID Correction',
            'historical_lock1000' => 'Historical LOCK1000',
            'blockchain_verifier' => 'Blockchain Verifier',
            'backup_restore' => 'Backup Restore',
            'scheduler' => 'Scheduler',
            'production_security' => 'Production Security',
        ] as $key => $label): ?>
            <div class="col-md-4 col-lg-3">
                <div class="border border-secondary rounded p-2 h-100">
                    <div class="text-secondary"><?= esc($label) ?></div>
                    <strong><?= esc((string) ($summary[$key] ?? 'MISSING')) ?></strong>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="mt-3 small">
        <strong>Kill-switches (must stay OFF):</strong>
        <?php foreach ($switches as $k => $v): if ($k === 'sandbox_window_open') continue; ?>
            <span class="me-2"><code><?= esc($k) ?></code>=<?= ! empty($v) ? '<span class="text-danger">ON</span>' : 'false' ?></span>
        <?php endforeach; ?>
        <?php if (! empty($readiness['all_kill_switches_off'])): ?>
            <span class="text-success ms-2">All OFF ✓</span>
        <?php endif; ?>
    </div>
</div>

<div class="card-premium mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0">Policy Domains</h2>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-policies/mining-worksheet') ?>">Mining Decision Worksheet</a>
            <?php if (! empty($canDraft)): ?>
                <a class="btn btn-gold btn-sm" href="<?= site_url('admin/financial-policies/create') ?>">New Draft</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-dark align-middle mb-0">
            <thead>
            <tr>
                <th>Domain</th>
                <th>Status</th>
                <th>Engine / Manage</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($catalog as $key => $meta): ?>
                <?php $sec = $readiness['sections'][$key] ?? []; ?>
                <tr>
                    <td><?= esc($meta['label']) ?></td>
                    <td><code><?= esc((string) ($sec['status'] ?? 'MISSING')) ?></code></td>
                    <td class="small">
                        <?= esc($meta['engine']) ?>
                        <?php if (! empty($meta['manage_url'])): ?>
                            · <a href="<?= esc($meta['manage_url']) ?>">Open module</a>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-policies?domain=' . rawurlencode($key)) ?>">Review</a>
                        <?php if (! empty($canDraft)): ?>
                            <a class="btn btn-gold btn-sm" href="<?= site_url('admin/financial-policies/create?domain=' . rawurlencode($key)) ?>">Draft</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($domain !== '' && $snapshot !== null): ?>
<div class="card-premium mb-3">
    <h2 class="h5 mb-3"><?= esc($catalog[$domain]['label'] ?? $domain) ?> — Current Snapshot</h2>
    <pre class="small mb-0" style="max-height:320px;overflow:auto;white-space:pre-wrap;"><?= esc(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php endif; ?>

<div class="card-premium">
    <h2 class="h5 mb-3">Recent Policy Versions<?= $domain !== '' ? ' (' . esc($domain) . ')' : '' ?></h2>
    <?php if ($versions === []): ?>
        <p class="text-secondary mb-0">No policy versions yet. Create a draft to begin dual-control review.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-dark align-middle mb-0">
                <thead>
                <tr>
                    <th>Ref</th>
                    <th>Domain</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>v</th>
                    <th>Effective</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($versions as $v): ?>
                    <tr>
                        <td><a href="<?= site_url('admin/financial-policies/' . rawurlencode((string) $v['policy_ref'])) ?>"><code><?= esc($v['policy_ref']) ?></code></a></td>
                        <td><?= esc($v['domain']) ?></td>
                        <td><?= esc($v['title']) ?></td>
                        <td><code><?= esc($v['status']) ?></code></td>
                        <td><?= (int) ($v['version_no'] ?? 0) ?></td>
                        <td><?= esc((string) ($v['effective_from'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
