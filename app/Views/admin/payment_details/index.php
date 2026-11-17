<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Manage BEP20 USDT receiving details. First real wallet setup applies immediately. Later wallet / QR / enable changes use dual-control (platform admin with Settings may approve their own proposal).</p>

<?php if (! empty($setupHint)): ?>
<div class="alert alert-info"><?= esc($setupHint) ?></div>
<?php endif; ?>

<?php if (! empty($pending)): ?>
<div class="alert alert-warning">
    <strong>Pending destination change</strong>
    <code><?= esc($pending['proposal_ref'] ?? '') ?></code>
    → <code><?= esc($pending['to_destination_wallet'] ?? '') ?></code>
    · Enable: <?= ! empty($pending['display_enabled']) ? 'Yes' : 'No' ?>
    <?php
        $actorId = (int) (auth()->id() ?? 0);
        $isOwnPending = (int) ($pending['proposed_by'] ?? 0) === $actorId;
        $showApprove = ! empty($canApprove) && (! $isOwnPending || ! empty($allowSelfApprove));
    ?>
    <?php if ($showApprove): ?>
        <form method="post" action="<?= site_url('admin/payment-details/approve/' . rawurlencode((string) $pending['proposal_ref'])) ?>" class="d-inline ms-2">
            <?= csrf_field() ?>
            <button class="btn btn-gold btn-sm" type="submit"><?= $isOwnPending ? 'Approve (platform admin)' : 'Approve' ?></button>
        </form>
    <?php elseif ($isOwnPending && empty($allowSelfApprove)): ?>
        <span class="ms-2 small">Awaiting a second finance approver (or a platform admin with Settings).</span>
    <?php endif; ?>
    <?php if (! empty($canCancelPending)): ?>
        <form method="post" action="<?= site_url('admin/payment-details/reject/' . rawurlencode((string) $pending['proposal_ref'])) ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="reason" value="<?= $isOwnPending ? 'Cancelled by proposer.' : 'Rejected by finance approver.' ?>">
            <button class="btn btn-outline-gold btn-sm" type="submit"><?= $isOwnPending ? 'Cancel pending' : 'Reject' ?></button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card-premium" style="max-width: 920px;">
    <form method="post" action="<?= site_url('admin/payment-details') ?>" enctype="multipart/form-data" <?= empty($canSave) || ! empty($pending) ? 'onsubmit="return false;"' : '' ?>>
        <?= csrf_field() ?>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Network</label>
                <input class="form-control form-control-sm" type="text" value="BEP20" readonly>
            </div>
            <div class="col-md-8">
                <label class="form-label">USDT Token Contract Address</label>
                <?php
                    $contractVal = old('token_contract_address', $network['token_contract_address'] ?? '');
                    if ($contractVal !== '' && ! preg_match('/^0x[a-fA-F0-9]{40}$/', (string) $contractVal)) {
                        $contractVal = '';
                    }
                ?>
                <input class="form-control form-control-sm" name="token_contract_address" maxlength="128"
                       value="<?= esc($contractVal) ?>"
                       placeholder="0x… (BSC USDT contract)" <?= empty($canSave) || ! empty($pending) ? 'disabled' : '' ?>>
            </div>

            <div class="col-md-8">
                <label class="form-label">Receiving Wallet Address</label>
                <?php
                    $walletVal = old('receiving_wallet_address', $network['destination_wallet'] ?? '');
                    if ($walletVal !== '' && ! preg_match('/^0x[a-fA-F0-9]{40}$/', (string) $walletVal)) {
                        $walletVal = '';
                    }
                ?>
                <input class="form-control form-control-sm" name="receiving_wallet_address" maxlength="128"
                       value="<?= esc($walletVal) ?>"
                       placeholder="0x… (your deposit receiving wallet)" pattern="^0x[a-fA-F0-9]{40}$"
                       <?= empty($canSave) || ! empty($pending) ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label">Payment Recipient Name</label>
                <input class="form-control form-control-sm" name="recipient_name" maxlength="120"
                       value="<?= esc(old('recipient_name', $network['recipient_name'] ?? '')) ?>"
                       <?= empty($canSave) || ! empty($pending) ? 'disabled' : '' ?>>
            </div>

            <div class="col-md-4">
                <label class="form-label">Minimum Deposit (USDT)</label>
                <input class="form-control form-control-sm" type="text" inputmode="decimal" name="min_deposit_usdt"
                       value="<?= esc(old('min_deposit_usdt', $network['min_deposit_usdt'] ?? '5.00000000')) ?>"
                       <?= empty($canSave) || ! empty($pending) ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-8">
                <label class="form-label">Payment Instructions</label>
                <textarea class="form-control form-control-sm" name="instructions" rows="2" <?= empty($canSave) || ! empty($pending) ? 'disabled' : '' ?>><?= esc(old('instructions', $network['instructions'] ?? '')) ?></textarea>
            </div>

            <div class="col-md-6">
                <label class="form-label">Network Logo</label>
                <?php if (! empty($logoUrl)): ?>
                    <div class="mb-2">
                        <img src="<?= esc($logoUrl) ?>" alt="Network logo" style="max-height:56px;max-width:140px;object-fit:contain;border:1px solid rgba(212,175,55,.25);padding:.3rem;background:#0d0d0d;">
                    </div>
                <?php endif; ?>
                <input class="form-control form-control-sm" type="file" name="network_logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" <?= empty($canSave) || ! empty($pending) ? 'disabled' : '' ?>>
                <div class="field-hint">PNG, JPG or WebP · max 4 MB</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Payment QR Image</label>
                <?php if (! empty($qrUrl)): ?>
                    <div class="mb-2">
                        <img src="<?= esc($qrUrl) ?>" alt="Payment QR" style="max-height:120px;max-width:120px;object-fit:contain;border:1px solid rgba(212,175,55,.25);padding:.4rem;background:#fff;">
                    </div>
                <?php endif; ?>
                <input class="form-control form-control-sm" type="file" name="payment_qr" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" <?= empty($canSave) || ! empty($pending) ? 'disabled' : '' ?>>
                <div class="field-hint">PNG, JPG or WebP · max 4 MB</div>
                <?php if (! empty($qrUrl) && ! empty($canSave) && empty($pending)): ?>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="remove_qr" value="1" id="remove_qr">
                        <label class="form-check-label" for="remove_qr">Remove uploaded QR</label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" name="is_enabled" value="1" id="is_enabled"
                        <?= old('is_enabled', (string) ((int) ($network['is_enabled'] ?? 0))) === '1' ? 'checked' : '' ?>
                        <?= empty($canSave) || ! empty($pending) ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="is_enabled">Enable BEP20 Deposits</label>
                </div>
            </div>
            <div class="col-md-6 d-flex align-items-end justify-content-md-end">
                <?php if (! empty($canSave) && empty($pending)): ?>
                    <button class="btn btn-gold btn-sm" type="submit">Save Deposit Settings</button>
                <?php elseif (! empty($pending)): ?>
                    <span class="small text-secondary">Form locked until pending change is approved or cancelled.</span>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>
<?= $this->endSection() ?>
