<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Transfer posted mining wallet balances to another member. External withdrawal is not available.</p>

<?php if (empty($enabled)): ?>
    <div class="alert alert-warning">
        <strong>P2P Transfers Not Activated</strong>
        · Asset <?= esc($summary['asset_code']) ?> (DRAFT denomination)
        · Submit remains disabled and all execution endpoints are blocked server-side.
    </div>
<?php else: ?>
    <div class="alert alert-danger">P2P kill-switch reports ON but Phase 11C still refuses execution until separately authorized.</div>
<?php endif; ?>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-3">
    <div class="col-lg-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Send to Member</h2>
            <form method="post" action="<?= site_url('member/p2p/lookup') ?>" class="mb-3">
                <?= csrf_field() ?>
                <label class="form-label" for="receiver_member_id">Receiver Member ID</label>
                <div class="input-group">
                    <input class="form-control" type="text" name="receiver_member_id" id="receiver_member_id"
                           value="<?= esc(old('receiver_member_id') ?? '') ?>" required placeholder="e.g. NGW000001">
                    <button class="btn btn-outline-gold" type="submit">Verify Receiver</button>
                </div>
                <div class="form-text">Only Member ID and name are shown after verification.</div>
            </form>

            <form method="post" action="<?= site_url('member/p2p/preview') ?>" class="mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="receiver_member_id" value="<?= esc(old('receiver_member_id') ?? '') ?>">
                <label class="form-label" for="amount">Transfer Amount (<?= esc($summary['asset_code']) ?>)</label>
                <input class="form-control" type="text" name="amount" id="amount"
                       value="<?= esc(old('amount') ?? '') ?>" required placeholder="0.00000000">
                <button class="btn btn-outline-gold btn-sm mt-2" type="submit">Transfer Preview</button>
            </form>

            <?php if (! empty($preview) && is_array($preview)): ?>
                <div class="notice-box mb-3">
                    <div><strong>Preview</strong> · <?= esc($preview['note']) ?></div>
                    <div class="mt-1">To <?= esc($preview['receiver']['member_id']) ?> · <?= esc($preview['receiver']['member_name']) ?></div>
                    <div>Amount <?= esc($preview['amount']) ?> <?= esc($preview['asset_code']) ?></div>
                    <div>Your available <?= esc($preview['available_balance']) ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= site_url('member/p2p/transfer') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="receiver_member_id" value="<?= esc(old('receiver_member_id') ?? '') ?>">
                <input type="hidden" name="amount" value="<?= esc(old('amount') ?? '') ?>">
                <input type="hidden" name="idempotency_key" value="<?= esc(bin2hex(random_bytes(16))) ?>">
                <label class="form-label" for="password">Confirm with password</label>
                <input class="form-control mb-2" type="password" name="password" id="password" autocomplete="current-password" <?= empty($enabled) ? 'disabled' : 'required' ?>>
                <button class="btn btn-gold" type="submit" <?= empty($enabled) ? 'disabled' : '' ?>>
                    Confirm Transfer
                </button>
            </form>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Transfer History</h2>
            <div class="table-responsive">
                <table class="table table-dark-premium table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Date</th>
                        <th>Counterparty</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($history)): ?>
                        <tr><td colspan="5" class="text-secondary">No transfers yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($history as $row): ?>
                            <?php
                            $mine = (string) $profile->member_id;
                            $counter = $row['sender_member_id'] === $mine
                                ? $row['receiver_member_id']
                                : $row['sender_member_id'];
                            $dir = $row['sender_member_id'] === $mine ? 'Sent' : 'Received';
                            ?>
                            <tr>
                                <td><code><?= esc($row['transfer_ref']) ?></code></td>
                                <td><?= esc($row['created_at']) ?></td>
                                <td><?= esc($dir) ?> · <?= esc($counter) ?></td>
                                <td><?= esc($row['amount']) ?> <?= esc($row['asset_code']) ?></td>
                                <td><?= esc($row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
