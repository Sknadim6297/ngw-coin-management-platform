<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">USDT receiving configuration. Destination changes require dual-control. Existing deposit address snapshots never change.</p>
<div class="alert alert-warning">
    Blockchain verifier: <code><?= esc($verifier) ?></code>.
    Only enable networks your verifier can actually check. QR codes are payment instructions, not proof of payment.
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/networks') ?>">Manage Networks</a>
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/deposits') ?>">Deposits</a>
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/purchases') ?>">Purchases</a>
</div>

<div class="row g-3">
    <?php foreach ($networks as $network): ?>
        <div class="col-lg-6">
            <div class="card-premium h-100">
                <div class="d-flex justify-content-between">
                    <h2 class="h5 mb-1"><?= esc($network['network_name']) ?></h2>
                    <span class="badge bg-secondary"><?= ! empty($network['is_enabled']) ? 'Enabled' : 'Disabled' ?></span>
                </div>
                <div class="small text-secondary mb-2">
                    Key <code><?= esc($network['network_key']) ?></code>
                    · Verifier <?= esc($network['verifier_type'] ?? 'none') ?>
                    · Chain <?= esc((string) ($network['chain_id'] ?? '—')) ?>
                </div>
                <div class="mb-2"><strong>Receiving wallet</strong><br>
                    <code class="text-break" id="wallet-<?= (int) $network['id'] ?>"><?= esc($network['destination_wallet'] ?: 'Not configured') ?></code>
                </div>
                <?php if (! empty($network['destination_wallet'])): ?>
                    <div class="mb-3 text-center">
                        <img alt="Payment QR" width="160" height="160"
                             src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&amp;data=<?= rawurlencode((string) $network['destination_wallet']) ?>">
                        <div class="small text-secondary mt-1">QR encodes the receiving address above</div>
                    </div>
                <?php endif; ?>
                <div class="small mb-2">Min <?= esc($network['min_deposit_usdt']) ?> · Max <?= esc($network['max_deposit_usdt']) ?> USDT</div>
                <?php if (! empty($network['instructions'])): ?>
                    <div class="small text-secondary mb-2"><?= esc($network['instructions']) ?></div>
                <?php endif; ?>

                <?php if ($canPropose): ?>
                    <hr>
                    <form method="post" action="<?= site_url('admin/payment-settings/propose-destination') ?>" class="row g-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="network_id" value="<?= (int) $network['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Propose new destination wallet</label>
                            <input class="form-control form-control-sm" name="to_destination_wallet" required placeholder="0x...">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Instructions (optional)</label>
                            <textarea class="form-control form-control-sm" name="instructions" rows="2"><?= esc($network['instructions'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12 form-check">
                            <input class="form-check-input" type="checkbox" name="display_enabled" value="1" id="disp-<?= (int) $network['id'] ?>" checked>
                            <label class="form-check-label" for="disp-<?= (int) $network['id'] ?>">Keep display enabled after approval (if network already enabled)</label>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-gold btn-sm" type="submit">Propose Change</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card-premium mt-4">
    <h2 class="h5">Destination Change Audit</h2>
    <div class="table-responsive">
        <table class="table table-dark-premium table-sm mb-0">
            <thead><tr><th>Ref</th><th>Network</th><th>From → To</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($proposals)): ?>
                <tr><td colspan="5" class="text-secondary">No destination change proposals.</td></tr>
            <?php else: foreach ($proposals as $p): ?>
                <tr>
                    <td><code><?= esc($p['proposal_ref']) ?></code></td>
                    <td><?= esc($p['network_key']) ?></td>
                    <td class="small">
                        <code><?= esc($p['from_destination_wallet'] ?? '—') ?></code><br>
                        → <code><?= esc($p['to_destination_wallet']) ?></code>
                    </td>
                    <td><?= esc($p['status']) ?></td>
                    <td>
                        <?php if ($p['status'] === 'pending' && $canApprove): ?>
                            <form class="d-inline" method="post" action="<?= site_url('admin/payment-settings/approve-destination/' . rawurlencode($p['proposal_ref'])) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-gold btn-sm" type="submit">Approve</button>
                            </form>
                            <form class="d-inline" method="post" action="<?= site_url('admin/payment-settings/reject-destination/' . rawurlencode($p['proposal_ref'])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="reason" value="Rejected by approver">
                                <button class="btn btn-outline-danger btn-sm" type="submit">Reject</button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
