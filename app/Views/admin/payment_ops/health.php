<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/payment_monitoring_tabs', ['active' => 'health']) ?>
<p class="page-subtitle">RPC and verification health. RPC credentials are never displayed.</p>

<div class="d-flex flex-wrap gap-2 mb-3">
    <form method="post" action="<?= site_url('admin/payment-health/probe') ?>" data-loading>
        <?= csrf_field() ?>
        <button class="btn btn-gold" type="submit">Run RPC Probe</button>
    </form>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/payment-reconciliation') ?>">Reconciliation</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/deployment-preflight') ?>">Preflight</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">RPC Configured</div><strong><?= ! empty($data['rpc_configured']) ? 'Yes' : 'No' ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Connectivity</div><strong><?= esc((string) ($data['rpc_connectivity_status'] ?? 'unavailable')) ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Chain ID Match</div><strong><?php
        if ($data['chain_id_consistent'] === null) {
            echo 'unavailable';
        } else {
            echo $data['chain_id_consistent'] ? 'Yes' : 'No';
        }
    ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Latest Head Block</div><strong><?= esc((string) ($data['latest_verified_block'] ?? '—')) ?></strong></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Pending Deposits</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $data['pending_deposits'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Awaiting Confirmations</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $data['awaiting_confirmations'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Failures (24h)</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $data['verification_failures_24h'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Avg RPC Latency</div><strong><?= $data['avg_verification_latency_ms'] !== null ? esc((string) $data['avg_verification_latency_ms']) . ' ms' : 'unavailable' ?></strong></div></div>
</div>

<div class="card-premium mb-3">
    <h2 class="h5 mb-3">Configuration (redacted)</h2>
    <div class="row g-3">
        <div class="col-md-4"><div class="text-secondary mb-1">Verifier Mode</div><code><?= esc((string) $data['deposit_verifier']) ?></code></div>
        <div class="col-md-4"><div class="text-secondary mb-1">RPC Host</div><code><?= esc((string) ($data['rpc_host_redacted'] ?? '—')) ?></code></div>
        <div class="col-md-2"><div class="text-secondary mb-1">Expected Chain</div><strong><?= esc((string) ($data['chain_id_expected'] ?? '—')) ?></strong></div>
        <div class="col-md-2"><div class="text-secondary mb-1">Reported Chain</div><strong><?= esc((string) ($data['chain_id_reported'] ?? '—')) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Last Health Check</div><strong><?= esc((string) ($data['last_health_check_at'] ?? 'unavailable')) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Last Success Verify</div><strong><?= esc((string) ($data['last_successful_verification_at'] ?? 'unavailable')) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Last Probe Error</div><strong><?= esc((string) ($data['last_health_error'] ?? '—')) ?></strong></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-premium">
            <h2 class="h5 mb-3">Error Categories (24h)</h2>
            <?php if (($data['error_categories'] ?? []) === []): ?>
                <div class="text-secondary">No recorded verification failures in the last 24 hours (or no attempts yet).</div>
            <?php else: ?>
                <table class="table-premium">
                    <thead><tr><th>Category</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['error_categories'] as $cat => $cnt): ?>
                        <tr><td><code><?= esc((string) $cat) ?></code></td><td><?= (int) $cnt ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-premium">
            <h2 class="h5 mb-3">Integrity Flags</h2>
            <?php if (($data['integrity_flags'] ?? []) === []): ?>
                <div class="text-secondary">No integrity flags recorded.</div>
            <?php else: ?>
                <table class="table-premium">
                    <thead><tr><th>Flag</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['integrity_flags'] as $flag => $cnt): ?>
                        <tr><td><code><?= esc((string) $flag) ?></code></td><td><?= (int) $cnt ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
