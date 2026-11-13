<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <p class="page-subtitle mb-0">Configure USDT payment networks and destination wallets. Networks stay disabled until you enable them.</p>
    <a class="btn btn-gold" href="<?= site_url('admin/networks/create') ?>">Add Network</a>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Key</th>
                <th>Name</th>
                <th>Destination</th>
                <th>Min / Max</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($networks === []): ?>
                <tr><td colspan="6" class="text-secondary">No networks configured yet.</td></tr>
            <?php else: ?>
                <?php foreach ($networks as $network): ?>
                    <tr>
                        <td><?= esc($network['network_key']) ?></td>
                        <td><?= esc($network['network_name']) ?></td>
                        <td><code class="text-break"><?= esc($network['destination_wallet'] ?: '—') ?></code></td>
                        <td><?= esc($network['min_deposit_usdt']) ?> / <?= esc($network['max_deposit_usdt']) ?></td>
                        <td>
                            <?php if ((int) $network['is_enabled'] === 1): ?>
                                <span class="badge-status approved">Enabled</span>
                            <?php else: ?>
                                <span class="badge-status cancelled">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?= site_url('admin/networks/' . $network['id'] . '/edit') ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
