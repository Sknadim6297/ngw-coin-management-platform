<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Submit USDT deposit evidence for review. Submission is not payment confirmation.</p>

<div class="alert alert-warning" role="alert">
    <strong>Network warning:</strong> Sending tokens on the wrong network can cause irreversible loss.
    Only use the destination address shown for the network you select. Never send from an unverified source.
</div>

<?php if ($networks === []): ?>
    <div class="card-premium">
        <div class="notice-box mb-0">
            No deposit networks are enabled yet. An administrator must configure and enable a destination wallet before deposits can be submitted.
        </div>
    </div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium">
            <form method="post" action="<?= site_url('member/deposits') ?>" enctype="multipart/form-data" data-loading data-live-validate id="deposit-form">
                <?= csrf_field() ?>
                <input type="hidden" name="idempotency_key" value="<?= esc($idempotencyKey) ?>">

                <div class="mb-3">
                    <label class="form-label" for="network_id">Blockchain Network</label>
                    <select class="form-select" id="network_id" name="network_id" required data-validate="required" data-label="Network">
                        <option value="">Select network</option>
                        <?php foreach ($networks as $network): ?>
                            <option value="<?= (int) $network['id'] ?>"
                                    data-wallet="<?= esc($network['destination_wallet']) ?>"
                                    data-min="<?= esc($network['min_deposit_usdt']) ?>"
                                    data-max="<?= esc($network['max_deposit_usdt']) ?>"
                                    data-instructions="<?= esc($network['instructions'] ?? '') ?>"
                                    <?= (string) old('network_id') === (string) $network['id'] ? 'selected' : '' ?>>
                                <?= esc($network['network_name']) ?> (<?= esc($network['token_symbol']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="card-premium mb-3" style="background: rgba(212,175,55,0.05);">
                    <div class="text-secondary mb-1">Destination wallet</div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <code id="wallet-display" class="text-break">Select a network</code>
                        <button class="btn btn-outline-gold btn-sm" type="button" id="copy-wallet" disabled>Copy</button>
                    </div>
                    <div class="field-hint mt-2" id="network-instructions">Network-specific instructions appear after selection.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="expected_amount_usdt">Expected USDT Amount</label>
                    <input class="form-control" type="text" inputmode="decimal" id="expected_amount_usdt" name="expected_amount_usdt"
                           value="<?= esc(old('expected_amount_usdt')) ?>" required
                           data-validate="required|decimal|gt:0" data-label="Expected USDT Amount">
                    <div class="field-hint" id="amount-hint">Enter the amount you sent. Limits depend on the selected network.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="tx_hash">Transaction Hash</label>
                    <input class="form-control" type="text" id="tx_hash" name="tx_hash"
                           value="<?= esc(old('tx_hash')) ?>" required maxlength="128"
                           data-validate="required|min:10" data-label="Transaction Hash"
                           placeholder="0x...">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="evidence">Optional Evidence (JPG/PNG/WEBP/PDF, max 5 MB)</label>
                    <input class="form-control" type="file" id="evidence" name="evidence" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                    <div class="field-hint">Screenshots are evidence only and do not verify payment.</div>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
                    <label class="form-check-label" for="confirm">
                        I understand this submission is not payment confirmation and wrong-network transfers may be unrecoverable.
                    </label>
                </div>

                <button class="btn btn-gold" type="submit">Submit Deposit Request</button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="notice-box">
            After submission, an administrator may place the request under review.
            Blockchain verification requires a trusted provider (Phase 4B) and is not automatic.
        </div>
    </div>
</div>

<script>
(() => {
    const select = document.getElementById('network_id');
    const wallet = document.getElementById('wallet-display');
    const copyBtn = document.getElementById('copy-wallet');
    const instructions = document.getElementById('network-instructions');
    const amountHint = document.getElementById('amount-hint');
    if (!select) return;

    const sync = () => {
        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) {
            wallet.textContent = 'Select a network';
            copyBtn.disabled = true;
            instructions.textContent = 'Network-specific instructions appear after selection.';
            amountHint.textContent = 'Enter the amount you sent. Limits depend on the selected network.';
            return;
        }
        wallet.textContent = opt.dataset.wallet || '—';
        copyBtn.disabled = !(opt.dataset.wallet);
        instructions.textContent = opt.dataset.instructions || 'Follow the selected network carefully. Wrong-network sends can be lost forever.';
        amountHint.textContent = 'Min ' + (opt.dataset.min || '—') + ' / Max ' + (opt.dataset.max || '—') + ' USDT for this network.';
    };

    copyBtn.addEventListener('click', async () => {
        const text = wallet.textContent.trim();
        if (!text || text === 'Select a network') return;
        try {
            await navigator.clipboard.writeText(text);
            copyBtn.textContent = 'Copied';
            setTimeout(() => copyBtn.textContent = 'Copy', 1500);
        } catch (e) {
            copyBtn.textContent = 'Copy failed';
        }
    });

    select.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>
<?= $this->endSection() ?>
