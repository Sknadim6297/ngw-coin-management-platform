<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'wallets']) ?>
<p class="page-subtitle">Configure commission withdrawal minimum, fee, and request activation. Emergency unlock and automatic blockchain payout remain server-side controls.</p>

<?php
$settings = $settings ?? [];
$canActivate = ! empty($settings['can_activate_requests']);
?>
<div class="alert <?= ! empty($settings['operational_ready']) && ! empty($settings['requests_enabled']) ? 'alert-success' : 'alert-warning' ?> mb-3">
    Policy: <?= esc((string) ($settings['policy_status'] ?? 'pending')) ?>
    · Emergency unlock <?= ! empty($settings['emergency_unlock']) ? 'ON' : 'OFF' ?>
    · Requests <?= ! empty($settings['requests_enabled']) ? 'ENABLED' : 'OFF' ?>
    · Auto blockchain payout <?= ! empty($status['blockchain_payout_enabled']) ? 'ON (must stay OFF)' : 'OFF' ?>
    <?php if (! empty($settings['blockers'])): ?>
        <ul class="mb-0 mt-2 small">
            <?php foreach ($settings['blockers'] as $b): ?>
                <li><?= esc((string) $b) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card-premium">
            <h2 class="h5 mb-3">Withdrawal Policy</h2>
            <form method="post" action="<?= site_url('admin/withdrawal-settings') ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="minimum_usdt">Minimum withdrawal (USDT)</label>
                    <input class="form-control" type="text" name="minimum_usdt" id="minimum_usdt" required
                           value="<?= esc(old('minimum_usdt', $settings['minimum_usdt'] ?? '')) ?>"
                           placeholder="Must be set by Admin — no default">
                    <div class="form-text">Must be greater than zero. Exact 8 decimal places.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="fee_usdt">Withdrawal fee (USDT)</label>
                    <input class="form-control" type="text" name="fee_usdt" id="fee_usdt" required
                           value="<?= esc(old('fee_usdt', $settings['fee_usdt'] ?? '')) ?>"
                           placeholder="Must be set by Admin — no default">
                    <div class="form-text">Non-negative. Deducted from requested amount for net BEP20 payout.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="notes">Notes (optional)</label>
                    <input class="form-control" type="text" name="notes" id="notes"
                           value="<?= esc(old('notes', $settings['notes'] ?? '')) ?>" maxlength="500">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="approve_policy" value="1" id="approve_policy"
                        <?= old('approve_policy', ($settings['policy_status'] ?? '') === 'approved' ? '1' : '') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="approve_policy">Approve fee / minimum policy</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="requests_enabled" value="1" id="requests_enabled"
                        <?= ! empty($settings['requests_enabled']) ? 'checked' : '' ?>
                        <?= $canActivate || ! empty($settings['requests_enabled']) ? '' : 'disabled' ?>>
                    <label class="form-check-label" for="requests_enabled">
                        Activate member withdrawal requests
                        <?php if (! $canActivate && empty($settings['requests_enabled'])): ?>
                            <span class="text-secondary">(blocked until policy approved + emergency unlock ON + auto payout OFF)</span>
                        <?php endif; ?>
                    </label>
                </div>
                <?php if (! $canActivate && empty($settings['requests_enabled'])): ?>
                    <input type="hidden" name="requests_enabled" value="0">
                <?php endif; ?>
                <button class="btn btn-gold" type="submit">Save settings</button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-premium mb-3">
            <h2 class="h5 mb-3">Current values</h2>
            <div class="mb-2"><span class="text-secondary">Minimum</span><div><?= $settings['minimum_usdt'] === null ? 'Not configured' : esc($settings['minimum_usdt']) . ' USDT' ?></div></div>
            <div class="mb-2"><span class="text-secondary">Fee</span><div><?= $settings['fee_usdt'] === null ? 'Not configured' : esc($settings['fee_usdt']) . ' USDT' ?></div></div>
            <div class="mb-2"><span class="text-secondary">Last updated</span><div><?= esc((string) ($settings['updated_at'] ?? '—')) ?></div></div>
            <div class="small text-secondary mt-3">Manual BEP20 payout: Admin sends USDT to the member registered wallet, records TX hash, then independent on-chain verification is required before PAID. This page never enables automatic blockchain payout.</div>
        </div>
    </div>
</div>

<div class="card-premium">
    <h2 class="h5 mb-3">Settings audit</h2>
    <div class="table-responsive">
        <table class="table table-dark-premium table-sm mb-0">
            <thead>
            <tr><th>When</th><th>Actor</th><th>Action</th><th>Old</th><th>New</th></tr>
            </thead>
            <tbody>
            <?php if (empty($audit)): ?>
                <tr><td colspan="5" class="text-secondary">No settings changes yet.</td></tr>
            <?php else: foreach ($audit as $row): ?>
                <tr>
                    <td><?= esc((string) ($row['created_at'] ?? '')) ?></td>
                    <td><?= esc((string) ($row['actor_user_id'] ?? '')) ?></td>
                    <td><?= esc((string) ($row['action'] ?? '')) ?></td>
                    <td><code class="small text-break"><?= esc((string) ($row['old_json'] ?? '')) ?></code></td>
                    <td><code class="small text-break"><?= esc((string) ($row['new_json'] ?? '')) ?></code></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
