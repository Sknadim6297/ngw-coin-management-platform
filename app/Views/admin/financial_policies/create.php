<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Create a financial policy draft. Leave unresolved values empty — do not invent fees or rates.</p>

<div class="card-premium" style="max-width:920px;">
    <form method="post" action="<?= site_url('admin/financial-policies') ?>">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Domain</label>
                <select class="form-select form-select-sm" name="domain" required>
                    <?php foreach ($catalog as $key => $meta): ?>
                        <option value="<?= esc($key) ?>" <?= $domain === $key ? 'selected' : '' ?>><?= esc($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">Title</label>
                <input class="form-control form-control-sm" name="title" maxlength="160" required
                       value="<?= esc(old('title', ($catalog[$domain]['label'] ?? 'Policy') . ' draft ' . date('Y-m-d'))) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Effective from (optional)</label>
                <input class="form-control form-control-sm" type="date" name="effective_from" value="<?= esc(old('effective_from')) ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label">Notes</label>
                <input class="form-control form-control-sm" name="notes" maxlength="500" value="<?= esc(old('notes')) ?>">
            </div>

            <?php if ($domain === 'mining'): ?>
                <div class="col-12"><hr class="border-secondary"><strong>Mining fields</strong> <span class="small text-secondary">(leave blank if unresolved)</span></div>
                <div class="col-md-4">
                    <label class="form-label">Monthly percent</label>
                    <input class="form-control form-control-sm" name="payload[monthly_percent]" placeholder="e.g. 3">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Day divisor mode</label>
                    <select class="form-select form-select-sm" name="payload[day_divisor_mode]">
                        <option value="">— unresolved —</option>
                        <option value="fixed_30">Fixed 30 days</option>
                        <option value="calendar_days">Calendar days</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Earning asset</label>
                    <select class="form-select form-select-sm" name="payload[earning_asset]">
                        <option value="">— unresolved —</option>
                        <option value="COIN">COIN</option>
                        <option value="USDT">USDT</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Approval-day policy</label>
                    <select class="form-select form-select-sm" name="payload[approval_day_policy]">
                        <option value="">— unresolved —</option>
                        <option value="full_day">Include approval date</option>
                        <option value="exclude_approval_day">Start following business date</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Post-maturity mining</label>
                    <select class="form-select form-select-sm" name="payload[post_maturity_eligibility]">
                        <option value="">— unresolved —</option>
                        <option value="eligible">Eligible</option>
                        <option value="not_eligible">Not eligible</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Post-sale mining</label>
                    <select class="form-select form-select-sm" name="payload[post_sale_eligibility]">
                        <option value="">— unresolved —</option>
                        <option value="eligible">Eligible</option>
                        <option value="not_eligible">Not eligible</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Decimal precision</label>
                    <input class="form-control form-control-sm" name="payload[decimal_precision]" placeholder="8">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Rounding mode</label>
                    <input class="form-control form-control-sm" name="payload[rounding_mode]" placeholder="unresolved">
                </div>
            <?php elseif ($domain === 'commission'): ?>
                <div class="col-12"><hr class="border-secondary"><strong>Commission fields</strong></div>
                <div class="col-md-3"><label class="form-label">L1 %</label><input class="form-control form-control-sm" name="payload[level1_percent]" value="10"></div>
                <div class="col-md-3"><label class="form-label">L2 %</label><input class="form-control form-control-sm" name="payload[level2_percent]" value="5"></div>
                <div class="col-md-3"><label class="form-label">L3 %</label><input class="form-control form-control-sm" name="payload[level3_percent]" value="3"></div>
                <div class="col-md-3">
                    <label class="form-label">Calculation base</label>
                    <select class="form-select form-select-sm" name="payload[calculation_base]">
                        <option value="">— unresolved —</option>
                        <option value="approved_purchase_usdt">Approved purchase USDT</option>
                        <option value="other_explicit">Other explicit base</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Missing sponsor handling</label>
                    <select class="form-select form-select-sm" name="payload[missing_sponsor_policy]">
                        <option value="">— unresolved —</option>
                        <option value="skip_level">Skip level</option>
                        <option value="hold">Hold</option>
                    </select>
                </div>
            <?php elseif ($domain === 'p2p'): ?>
                <div class="col-12"><hr class="border-secondary"><strong>P2P fields</strong> (all optional / unresolved)</div>
                <div class="col-md-3"><label class="form-label">Min transfer</label><input class="form-control form-control-sm" name="payload[min_transfer]" placeholder="unresolved"></div>
                <div class="col-md-3"><label class="form-label">Max transfer</label><input class="form-control form-control-sm" name="payload[max_transfer]" placeholder="unresolved"></div>
                <div class="col-md-3"><label class="form-label">Daily limit</label><input class="form-control form-control-sm" name="payload[daily_limit]" placeholder="unresolved"></div>
                <div class="col-md-3"><label class="form-label">Fee</label><input class="form-control form-control-sm" name="payload[fee]" placeholder="unresolved"></div>
                <div class="col-md-6"><label class="form-label">Reauthentication</label><input class="form-control form-control-sm" name="payload[reauthentication]" value="required"></div>
            <?php elseif ($domain === 'withdrawal'): ?>
                <div class="col-12"><hr class="border-secondary"><strong>Withdrawal fields</strong></div>
                <div class="col-md-3"><label class="form-label">Minimum</label><input class="form-control form-control-sm" name="payload[minimum]" placeholder="unresolved"></div>
                <div class="col-md-3"><label class="form-label">Maximum</label><input class="form-control form-control-sm" name="payload[maximum]" placeholder="unresolved"></div>
                <div class="col-md-3"><label class="form-label">Fee</label><input class="form-control form-control-sm" name="payload[fee]" placeholder="unresolved"></div>
                <div class="col-md-3"><label class="form-label">Fee method</label><input class="form-control form-control-sm" name="payload[fee_method]" placeholder="unresolved"></div>
                <div class="col-md-4"><label class="form-label">Network</label><input class="form-control form-control-sm" name="payload[network]" value="BEP20"></div>
                <div class="col-md-4"><label class="form-label">Token</label><input class="form-control form-control-sm" name="payload[token]" value="USDT"></div>
            <?php elseif ($domain === 'selling'): ?>
                <div class="col-12"><hr class="border-secondary"><strong>Coin selling fields</strong></div>
                <div class="col-md-4"><label class="form-label">Selling price policy</label><input class="form-control form-control-sm" name="payload[price_policy]" placeholder="unresolved"></div>
                <div class="col-md-4"><label class="form-label">Counterparty</label><input class="form-control form-control-sm" name="payload[counterparty]" placeholder="unresolved"></div>
                <div class="col-md-4"><label class="form-label">Settlement asset</label><input class="form-control form-control-sm" name="payload[settlement_asset]" placeholder="unresolved"></div>
                <div class="col-md-4"><label class="form-label">Fee</label><input class="form-control form-control-sm" name="payload[fee]" placeholder="unresolved"></div>
                <div class="col-md-4"><label class="form-label">Min qty</label><input class="form-control form-control-sm" name="payload[min_quantity]" placeholder="unresolved"></div>
                <div class="col-md-4"><label class="form-label">Partial selling</label>
                    <select class="form-select form-select-sm" name="payload[partial_selling]"><option value="">— unresolved —</option><option value="allowed">Allowed</option><option value="forbidden">Forbidden</option></select>
                </div>
            <?php elseif ($domain === 'member_id'): ?>
                <div class="col-12"><hr class="border-secondary"><strong>Member ID target (preview only — does not execute)</strong></div>
                <div class="col-md-4"><label class="form-label">Start number</label><input class="form-control form-control-sm" name="payload[target_start]" value="1234"></div>
                <div class="col-md-4"><label class="form-label">Padding</label><input class="form-control form-control-sm" name="payload[target_padding]" value="4"></div>
                <div class="col-md-4"><label class="form-label">Command</label><input class="form-control form-control-sm" name="payload[command]" value="php spark members:correct-sequence" readonly></div>
            <?php elseif ($domain === 'purchase_lock'): ?>
                <div class="col-12"><hr class="border-secondary"><strong>Purchase / lock</strong></div>
                <div class="col-md-4"><label class="form-label">Min purchase USDT</label><input class="form-control form-control-sm" name="payload[min_purchase_usdt]" value="5"></div>
                <div class="col-md-4"><label class="form-label">Lock days</label><input class="form-control form-control-sm" name="payload[lock_days]" value="1000"></div>
                <div class="col-md-4"><label class="form-label">Remediation command</label><input class="form-control form-control-sm" name="payload[remediation_command]" value="php spark locking:remediate-mandatory" readonly></div>
            <?php else: ?>
                <div class="col-12"><hr class="border-secondary"><strong>Blockchain checklist</strong> (no secrets)</div>
                <div class="col-md-4"><label class="form-label">Chain ID</label><input class="form-control form-control-sm" name="payload[chain_id]" value="56"></div>
                <div class="col-md-4"><label class="form-label">Confirmations</label><input class="form-control form-control-sm" name="payload[required_confirmations]" placeholder="unresolved"></div>
                <div class="col-md-4"><label class="form-label">Token decimals</label><input class="form-control form-control-sm" name="payload[token_decimals]" placeholder="unresolved"></div>
                <div class="col-12"><label class="form-label">Notes on contract / RPC</label><textarea class="form-control form-control-sm" name="payload[ops_notes]" rows="2" placeholder="Operator must supply verified contract + RPC; never paste secrets."></textarea></div>
            <?php endif; ?>

            <div class="col-12">
                <button class="btn btn-gold btn-sm" type="submit">Save Draft</button>
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-policies') ?>">Cancel</a>
            </div>
        </div>
    </form>
</div>

<details class="mt-3 small text-secondary">
    <summary>Current runtime snapshot</summary>
    <pre style="white-space:pre-wrap;"><?= esc(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</details>
<?= $this->endSection() ?>
