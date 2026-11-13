<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Append-only inventory ledger. Historical rows are never edited.</p>

<div class="card-premium mb-3">
    <strong>Available balance:</strong> <?= esc($inventory['available_balance']) ?>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Ref</th>
                <th>Type</th>
                <th>Direction</th>
                <th>Quantity</th>
                <th>Previous</th>
                <th>New</th>
                <th>Actor</th>
                <th>Reference</th>
                <th>When</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="text-secondary">No ledger entries yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['transaction_ref']) ?></td>
                        <td><?= esc($row['type']) ?></td>
                        <td><?= esc($row['direction']) ?></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['previous_balance']) ?></td>
                        <td><?= esc($row['new_balance']) ?></td>
                        <td><?= esc($actorNames[(int) ($row['actor_user_id'] ?? 0)] ?? '—') ?></td>
                        <td><?= esc($row['reference'] ?? '—') ?></td>
                        <td><?= esc($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
