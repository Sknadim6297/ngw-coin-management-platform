<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Records an unverified internal inventory credit. This does not mint blockchain tokens or move external assets.</p>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium">
            <form method="post" action="<?= site_url('admin/coins/inventory/add') ?>" data-loading data-live-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="idempotency_key" value="<?= esc($idempotencyKey) ?>">

                <div class="mb-3">
                    <label class="form-label" for="quantity">Coin Quantity</label>
                    <input class="form-control" type="text" inputmode="decimal" id="quantity" name="quantity"
                           value="<?= esc(old('quantity')) ?>" required
                           data-validate="required|decimal|gt:0" data-label="Coin Quantity">
                    <div class="field-hint" data-validate-hint data-default-hint="Must be greater than zero. Use up to 8 decimal places.">Must be greater than zero. Use up to 8 decimal places.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reference">Reference / Source Note</label>
                    <input class="form-control" type="text" id="reference" name="reference"
                           value="<?= esc(old('reference')) ?>" maxlength="255"
                           placeholder="e.g. Internal allocation batch A1">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="remarks">Admin Remarks</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="3" maxlength="1000"><?= esc(old('remarks')) ?></textarea>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
                    <label class="form-check-label" for="confirm">
                        I confirm this is an internal inventory record and does not represent verified on-chain ownership.
                    </label>
                </div>

                <button class="btn btn-gold" type="submit">Credit Inventory</button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-premium">
            <img class="coin-thumb mb-3" src="<?= base_url('assets/images/coin.svg') ?>" alt="NGW Coin" width="72" height="72">
            <div class="text-secondary mb-1">Current available balance</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc($inventory['available_balance']) ?></div>
            <div class="notice-box mt-3">
                Duplicate form submissions are blocked by an idempotency key and cannot double-credit inventory.
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
