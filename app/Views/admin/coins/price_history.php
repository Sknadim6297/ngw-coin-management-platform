<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Immutable price change audit trail.</p>

<div class="card-premium mb-3">
    <strong>Current price:</strong>
    <?= $settings['current_price_usdt'] !== null ? esc($settings['current_price_usdt']) . ' USDT' : 'Not configured' ?>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Previous</th>
                <th>New</th>
                <th>Reason</th>
                <th>Changed By</th>
                <th>Effective At</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="text-secondary">No price history yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $row['previous_price_usdt'] !== null ? esc($row['previous_price_usdt']) : '—' ?></td>
                        <td><?= esc($row['new_price_usdt']) ?></td>
                        <td><?= esc($row['reason']) ?></td>
                        <td><?= esc($actorNames[(int) $row['actor_user_id']] ?? ('#' . $row['actor_user_id'])) ?></td>
                        <td><?= esc($row['effective_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
