<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Read-only wallet reconciliation. Findings are reported only — balances are never auto-corrected.</p>

<form class="card-premium mb-4" method="get" action="<?= site_url('admin/wallets/reconcile') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-6">
            <label class="form-label" for="member_id">Member ID (optional)</label>
            <input class="form-control" id="member_id" name="member_id" value="<?= esc($memberId) ?>" placeholder="Leave blank for bounded platform scan">
        </div>
        <div class="col-md-6">
            <button class="btn btn-gold" type="submit">Run Reconciliation</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Findings</div><div class="h4"><?= (int) $report['summary']['total_findings'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Critical</div><div class="h4"><?= (int) $report['summary']['critical'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Warning</div><div class="h4"><?= (int) $report['summary']['warning'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Auto Repair</div><div class="h4">Disabled</div></div></div>
</div>

<div class="card-premium">
    <div class="text-secondary mb-3">Generated <?= esc($report['generated_at']) ?></div>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Severity</th>
                <th>Code</th>
                <th>Message</th>
                <th>Member</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($report['findings'] === []): ?>
                <tr><td colspan="4" class="text-secondary">No findings in this scan window.</td></tr>
            <?php else: ?>
                <?php foreach ($report['findings'] as $finding): ?>
                    <tr>
                        <td><?= esc($finding['severity'] ?? 'info') ?></td>
                        <td><code><?= esc($finding['code'] ?? '') ?></code></td>
                        <td><?= esc($finding['message'] ?? '') ?></td>
                        <td>
                            <?php if (! empty($finding['member_id'])): ?>
                                <a href="<?= site_url('admin/wallets/' . rawurlencode((string) $finding['member_id'])) ?>"><?= esc($finding['member_id']) ?></a>
                            <?php else: ?>
                                <?= esc((string) ($finding['member_user_id'] ?? '—')) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
