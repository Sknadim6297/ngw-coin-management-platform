<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Read-only commission wallet reconciliation. Never auto-repairs balances.</p>
<a class="btn btn-outline-gold btn-sm mb-3" href="<?= site_url('admin/commission-wallet') ?>">Back</a>
<div class="card-premium">
    <div class="mb-2">Issues: <strong><?= (int) $result['issue_count'] ?></strong></div>
    <?php if (empty($result['issues'])): ?>
        <div class="text-secondary">No integrity issues detected.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark-premium table-sm mb-0">
                <thead><tr><th>Code</th><th>Member</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($result['issues'] as $issue): ?>
                    <tr>
                        <td><code><?= esc($issue['code']) ?></code></td>
                        <td><?= esc((string) ($issue['member'] ?? '—')) ?></td>
                        <td><?= esc($issue['detail'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
