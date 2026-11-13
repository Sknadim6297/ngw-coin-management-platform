<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Never store private keys here. Destination wallets must be addresses you control and have verified offline.</p>

<div class="card-premium">
    <form method="post" action="<?= $mode === 'create' ? site_url('admin/networks') : site_url('admin/networks/' . $network['id']) ?>" data-loading data-live-validate>
        <?= csrf_field() ?>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="network_key">Network Key</label>
                <input class="form-control" type="text" id="network_key" name="network_key"
                       value="<?= esc(old('network_key', $network['network_key'] ?? '')) ?>"
                       <?= $mode === 'edit' ? 'readonly' : 'required' ?>
                       placeholder="bsc_usdt">
            </div>
            <div class="col-md-8">
                <label class="form-label" for="network_name">Network Name</label>
                <input class="form-control" type="text" id="network_name" name="network_name" required
                       value="<?= esc(old('network_name', $network['network_name'] ?? '')) ?>"
                       placeholder="BNB Smart Chain (BEP20) USDT">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="verifier_type">Verifier Type</label>
                <select class="form-select" id="verifier_type" name="verifier_type">
                    <?php $vt = old('verifier_type', $network['verifier_type'] ?? 'none'); ?>
                    <option value="none" <?= $vt === 'none' ? 'selected' : '' ?>>none</option>
                    <option value="evm_erc20" <?= $vt === 'evm_erc20' ? 'selected' : '' ?>>evm_erc20</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="chain_id">Chain ID</label>
                <input class="form-control" type="number" id="chain_id" name="chain_id" min="1"
                       value="<?= esc(old('chain_id', (string) ($network['chain_id'] ?? ''))) ?>"
                       placeholder="56 for BNB Smart Chain">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="token_symbol">Token Symbol</label>
                <input class="form-control" type="text" id="token_symbol" name="token_symbol"
                       value="<?= esc(old('token_symbol', $network['token_symbol'] ?? 'USDT')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="token_decimals">Token Decimals</label>
                <input class="form-control" type="number" id="token_decimals" name="token_decimals" min="0" max="36" required
                       value="<?= esc(old('token_decimals', (string) ($network['token_decimals'] ?? '18'))) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="required_confirmations">Required Confirmations</label>
                <input class="form-control" type="number" id="required_confirmations" name="required_confirmations" min="1" required
                       value="<?= esc(old('required_confirmations', (string) ($network['required_confirmations'] ?? '12'))) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="token_contract_address">Token Contract</label>
                <input class="form-control" type="text" id="token_contract_address" name="token_contract_address"
                       value="<?= esc(old('token_contract_address', $network['token_contract_address'] ?? '')) ?>">
                <div class="field-hint">Required for evm_erc20. Must match this chain’s USDT contract.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="destination_wallet">Destination Wallet</label>
                <input class="form-control" type="text" id="destination_wallet" name="destination_wallet"
                       value="<?= esc(old('destination_wallet', $network['destination_wallet'] ?? '')) ?>">
                <div class="field-hint">Required before enabling. Do not invent addresses.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="min_deposit_usdt">Minimum Deposit (USDT)</label>
                <input class="form-control" type="text" id="min_deposit_usdt" name="min_deposit_usdt" required
                       value="<?= esc(old('min_deposit_usdt', $network['min_deposit_usdt'] ?? '5.00000000')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="max_deposit_usdt">Maximum Deposit (USDT)</label>
                <input class="form-control" type="text" id="max_deposit_usdt" name="max_deposit_usdt" required
                       value="<?= esc(old('max_deposit_usdt', $network['max_deposit_usdt'] ?? '100000.00000000')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="address_pattern">Address Pattern (regex)</label>
                <input class="form-control" type="text" id="address_pattern" name="address_pattern"
                       value="<?= esc(old('address_pattern', $network['address_pattern'] ?? config('Platform')->bep20Pattern)) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="tx_hash_pattern">Tx Hash Pattern (regex)</label>
                <input class="form-control" type="text" id="tx_hash_pattern" name="tx_hash_pattern"
                       value="<?= esc(old('tx_hash_pattern', $network['tx_hash_pattern'] ?? '/^0x[a-fA-F0-9]{64}$/')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="sort_order">Sort Order</label>
                <input class="form-control" type="number" id="sort_order" name="sort_order"
                       value="<?= esc(old('sort_order', (string) ($network['sort_order'] ?? '100'))) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="explorer_tx_url_template">Explorer TX URL Template</label>
                <input class="form-control" type="text" id="explorer_tx_url_template" name="explorer_tx_url_template"
                       value="<?= esc(old('explorer_tx_url_template', $network['explorer_tx_url_template'] ?? '')) ?>"
                       placeholder="https://bscscan.com/tx/{tx_hash}">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="rpc_url_env_key">RPC URL Env Key</label>
                <input class="form-control" type="text" id="rpc_url_env_key" name="rpc_url_env_key"
                       value="<?= esc(old('rpc_url_env_key', $network['rpc_url_env_key'] ?? '')) ?>"
                       placeholder="blockchain.evmRpcUrl">
                <div class="field-hint">Optional override. Default uses blockchain.evmRpcUrl. Never paste RPC secrets into the DB.</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="instructions">Member Instructions</label>
                <textarea class="form-control" id="instructions" name="instructions" rows="3"><?= esc(old('instructions', $network['instructions'] ?? '')) ?></textarea>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="is_enabled" name="is_enabled"
                        <?= old('is_enabled', (string) ($network['is_enabled'] ?? '0')) === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_enabled">Enable this network for members (requires destination wallet)</label>
                </div>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-gold" type="submit"><?= $mode === 'create' ? 'Create Network' : 'Save Changes' ?></button>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/networks') ?>">Cancel</a>
        </div>
    </form>
</div>
<?= $this->endSection() ?>
