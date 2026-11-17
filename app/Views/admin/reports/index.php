<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php
$tab     = $tab ?? 'overview';
$filters = $filters ?? [];
$rows    = $rows ?? [];
$total   = (int) ($total ?? 0);
$page    = (int) ($filters['page'] ?? 1);
$perPage = (int) ($filters['per_page'] ?? 25);
$pages   = max(1, (int) ceil($total / max(1, $perPage)));
$canExport = $canExport ?? false;
$exportUrl = $exportUrl ?? site_url('admin/reports/export?tab=' . rawurlencode($tab));

$queryBase = static function (array $overrides = []) use ($tab, $filters): string {
    $params = array_merge([
        'tab'       => $tab,
        'date_from' => $filters['date_from'] ? substr((string) $filters['date_from'], 0, 10) : '',
        'date_to'   => $filters['date_to'] ? substr((string) $filters['date_to'], 0, 10) : '',
        'status'    => $filters['status'] ?? '',
        'sponsor'   => $filters['sponsor'] ?? '',
        'member_id' => $filters['member_id'] ?? '',
        'q'         => $filters['q'] ?? '',
        'page'      => $filters['page'] ?? 1,
    ], $overrides);

    return site_url('admin/reports?' . http_build_query(array_filter($params, static fn ($v) => $v !== null && $v !== '')));
};
?>

<?= view('components/admin_tabs', ['active' => $tab, 'tabs' => $tabs]) ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-secondary small">Matching rows: <?= number_format($total) ?></div>
    <?php if ($canExport): ?>
        <a class="btn btn-sm btn-gold" href="<?= esc($exportUrl) ?>"><i class="bi bi-download me-1"></i>Download CSV</a>
    <?php endif; ?>
</div>

<?php if ($tab !== 'overview'): ?>
<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="<?= esc($tab) ?>">
        <div class="col-md-2">
            <label class="form-label" for="date_from">From</label>
            <input class="form-control" type="date" id="date_from" name="date_from"
                   value="<?= esc($filters['date_from'] ? substr((string) $filters['date_from'], 0, 10) : '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_to">To</label>
            <input class="form-control" type="date" id="date_to" name="date_to"
                   value="<?= esc($filters['date_to'] ? substr((string) $filters['date_to'], 0, 10) : '') ?>">
        </div>
        <?php if (in_array($tab, ['members', 'purchases', 'payments', 'referrals', 'wallets'], true)): ?>
            <div class="col-md-2">
                <label class="form-label" for="status">Status / Type</label>
                <input class="form-control" id="status" name="status" value="<?= esc($filters['status'] ?? '') ?>"
                       placeholder="<?= $tab === 'wallets' ? 'PURCHASE_CREDIT' : 'active / pending' ?>">
            </div>
        <?php endif; ?>
        <?php if (in_array($tab, ['members', 'referrals'], true)): ?>
            <div class="col-md-2">
                <label class="form-label" for="sponsor">Sponsor</label>
                <input class="form-control" id="sponsor" name="sponsor" value="<?= esc($filters['sponsor'] ?? '') ?>">
            </div>
        <?php endif; ?>
        <?php if (in_array($tab, ['members', 'purchases', 'payments', 'wallets', 'referrals'], true)): ?>
            <div class="col-md-2">
                <label class="form-label" for="member_id">Member ID</label>
                <input class="form-control" id="member_id" name="member_id" value="<?= esc($filters['member_id'] ?? '') ?>">
            </div>
        <?php endif; ?>
        <div class="col-md-2">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= esc($filters['q'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <button class="btn btn-gold w-100" type="submit">Apply</button>
        </div>
    </form>
</div>
<?php else: ?>
<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="overview">
        <div class="col-md-3">
            <label class="form-label" for="date_from">From</label>
            <input class="form-control" type="date" id="date_from" name="date_from"
                   value="<?= esc($filters['date_from'] ? substr((string) $filters['date_from'], 0, 10) : '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="date_to">To</label>
            <input class="form-control" type="date" id="date_to" name="date_to"
                   value="<?= esc($filters['date_to'] ? substr((string) $filters['date_to'], 0, 10) : '') ?>">
        </div>
        <div class="col-md-2">
            <button class="btn btn-gold w-100" type="submit">Apply</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?= view('components/kpi_grid', ['kpis' => $kpis ?? []]) ?>

<?php if ($tab === 'overview'): ?>
    <?php if (($notes ?? []) !== []): ?>
        <div class="text-secondary small mt-2 mb-3">
            <?php foreach ($notes as $note): ?>
                <div><?= esc($note) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'members'): ?>
    <div class="card-premium mt-3">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Member ID</th>
                    <th>Name</th>
                    <th>Sponsor</th>
                    <th>Registered</th>
                    <th>Activated</th>
                    <th>Status</th>
                    <th>Approved Purchases</th>
                    <th>Owned Coins</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-secondary">No members match these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><a href="<?= site_url('admin/members/' . rawurlencode((string) $row['member_id'])) ?>"><?= esc($row['member_id']) ?></a></td>
                            <td><?= esc($row['member_name'] ?? '') ?></td>
                            <td><?= esc($row['sponsor_member_id'] ?? '—') ?></td>
                            <td><?= esc($row['created_at'] ?? '') ?></td>
                            <td><?= esc($row['activated_at'] ?? '—') ?></td>
                            <td><span class="badge-status <?= esc($row['account_status'] ?? '') ?>"><?= esc(ucfirst((string) ($row['account_status'] ?? ''))) ?></span></td>
                            <td><?= (int) ($row['approved_purchases'] ?? 0) ?></td>
                            <td><?= esc($row['owned_coins'] ?? '0') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'inventory'): ?>
    <div class="row g-3 mt-1">
        <div class="col-lg-8">
            <div class="card-premium">
                <h2 class="h6 mb-3">Inventory Ledger</h2>
                <div class="table-responsive">
                    <table class="table-premium">
                        <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Type</th>
                            <th>Dir</th>
                            <th>Qty</th>
                            <th>Balance</th>
                            <th>When</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="6" class="text-secondary">No inventory transactions.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= esc($row['transaction_ref'] ?? '') ?></td>
                                    <td><?= esc($row['type'] ?? '') ?></td>
                                    <td><?= esc($row['direction'] ?? '') ?></td>
                                    <td><?= esc($row['quantity'] ?? '') ?></td>
                                    <td><?= esc($row['new_balance'] ?? '') ?></td>
                                    <td><?= esc($row['created_at'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-premium mb-3">
                <h2 class="h6 mb-2">Ownership</h2>
                <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Owned</span><strong><?= esc($ownership['balance'] ?? '0') ?></strong></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Available</span><strong><?= esc($ownership['available'] ?? '0') ?></strong></div>
                <div class="d-flex justify-content-between"><span class="text-secondary">Locked</span><strong><?= esc($ownership['locked'] ?? '0') ?></strong></div>
            </div>
            <div class="card-premium mb-3">
                <h2 class="h6 mb-2">Recent Locks</h2>
                <?php if (($locks ?? []) === []): ?>
                    <div class="text-secondary">None</div>
                <?php else: ?>
                    <ul class="mb-0 small">
                        <?php foreach ($locks as $lock): ?>
                            <li><?= esc($lock['lock_ref'] ?? '') ?> · <?= esc($lock['quantity'] ?? '') ?> · <?= esc($lock['status'] ?? '') ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="card-premium mb-3">
                <h2 class="h6 mb-2">Unlock History</h2>
                <?php if (($unlocks ?? []) === []): ?>
                    <div class="text-secondary">None</div>
                <?php else: ?>
                    <ul class="mb-0 small">
                        <?php foreach ($unlocks as $lock): ?>
                            <li><?= esc($lock['lock_ref'] ?? '') ?> · <?= esc($lock['quantity'] ?? '') ?> · <?= esc($lock['unlocked_at'] ?? '') ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="card-premium">
                <h2 class="h6 mb-2">Price History</h2>
                <?php if (($prices ?? []) === []): ?>
                    <div class="text-secondary">No price changes.</div>
                <?php else: ?>
                    <ul class="mb-0 small">
                        <?php foreach ($prices as $price): ?>
                            <?php $p = is_array($price) ? $price : (array) $price; ?>
                            <li><?= esc($p['new_price_usdt'] ?? $p['price_usdt'] ?? '') ?> USDT · <?= esc($p['created_at'] ?? '') ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'purchases'): ?>
    <div class="card-premium mt-3">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Purchase Ref</th>
                    <th>Member</th>
                    <th>Coins</th>
                    <th>Price Snapshot</th>
                    <th>Total USDT</th>
                    <th>Status</th>
                    <th>Approval Date</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-secondary">No purchases match these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['purchase_ref'] ?? '') ?></td>
                            <td><?= esc($row['member_id'] ?? '') ?></td>
                            <td><?= esc($row['coin_quantity'] ?? '') ?></td>
                            <td><?= esc($row['price_usdt_snapshot'] ?? '') ?></td>
                            <td><?= esc($row['total_usdt'] ?? '') ?></td>
                            <td><span class="badge-status <?= esc($row['status'] ?? '') ?>"><?= esc(ucfirst((string) ($row['status'] ?? ''))) ?></span></td>
                            <td><?= esc($row['reviewed_at'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'payments'): ?>
    <div class="card-premium mt-3">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Deposit Ref</th>
                    <th>Member</th>
                    <th>Network</th>
                    <th>Expected</th>
                    <th>Verified</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Linked Purchase</th>
                    <?php if (! empty($includeSensitive)): ?>
                        <th>TX Hash</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= ! empty($includeSensitive) ? 9 : 8 ?>" class="text-secondary">No payments match these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['deposit_ref'] ?? '') ?></td>
                            <td><?= esc($row['member_id'] ?? '') ?></td>
                            <td><?= esc($row['network_key'] ?? '') ?></td>
                            <td><?= esc($row['expected_amount_usdt'] ?? '') ?></td>
                            <td><?= esc($row['verified_amount_usdt'] ?: '—') ?></td>
                            <td><?= esc($row['verification_method'] ?: '—') ?></td>
                            <td><span class="badge-status <?= esc($row['status'] ?? '') ?>"><?= esc(ucfirst((string) ($row['status'] ?? ''))) ?></span></td>
                            <td><?= esc($row['linked_purchase_ref'] ?: '—') ?></td>
                            <?php if (! empty($includeSensitive)): ?>
                                <td class="small text-break"><?= esc($row['tx_hash'] ?? '—') ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'wallets'): ?>
    <div class="card-premium mt-3">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Ref</th>
                    <th>Member</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Balance</th>
                    <th>Available</th>
                    <th>Locked</th>
                    <th>When</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-secondary">No wallet transactions match these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['transaction_ref'] ?? '') ?></td>
                            <td><?= esc($row['member_id'] ?? '') ?></td>
                            <td><?= esc($row['type'] ?? '') ?></td>
                            <td><?= esc($row['quantity'] ?? '') ?></td>
                            <td><?= esc($row['new_balance'] ?? '') ?></td>
                            <td><?= esc($row['new_available'] ?? '') ?></td>
                            <td><?= esc($row['new_locked'] ?? '') ?></td>
                            <td><?= esc($row['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'referrals'): ?>
    <?php if (! empty($levels)): ?>
        <div class="card-premium mt-3 mb-3">
            <h2 class="h6 mb-2">Sponsor Team Levels</h2>
            <div class="row g-2">
                <div class="col-md-3"><div class="text-secondary">Direct</div><strong><?= (int) ($levels['direct'] ?? 0) ?></strong></div>
                <div class="col-md-3"><div class="text-secondary">L1</div><strong><?= (int) ($levels['levels'][1]['total'] ?? 0) ?></strong></div>
                <div class="col-md-3"><div class="text-secondary">L2</div><strong><?= (int) ($levels['levels'][2]['total'] ?? 0) ?></strong></div>
                <div class="col-md-3"><div class="text-secondary">L3</div><strong><?= (int) ($levels['levels'][3]['total'] ?? 0) ?></strong></div>
            </div>
        </div>
    <?php endif; ?>
    <div class="card-premium mt-3">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Member</th>
                    <th>Name</th>
                    <th>Sponsor</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Activated</th>
                    <th>Linked</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-secondary">No referral relationships match these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['member_id'] ?? '') ?></td>
                            <td><?= esc($row['member_name'] ?? '') ?></td>
                            <td><?= esc($row['sponsor_member_id'] ?? '') ?></td>
                            <td><span class="badge-status <?= esc($row['account_status'] ?? '') ?>"><?= esc(ucfirst((string) ($row['account_status'] ?? ''))) ?></span></td>
                            <td><?= esc($row['registered_at'] ?? '') ?></td>
                            <td><?= esc($row['activated_at'] ?? '—') ?></td>
                            <td><?= esc($row['linked_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'mining'): ?>
    <div class="alert alert-warning mt-3">Simulation preview only — actual mining is not activated. Values are not wallet balances.</div>
    <div class="card-premium">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Run Ref</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Members</th>
                    <th>Eligible</th>
                    <th>Preview Amount</th>
                    <th>Payable</th>
                    <th>Posted</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-secondary">No mining simulation runs yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['run_ref'] ?? '') ?></td>
                            <td><?= esc($row['calculation_date'] ?? '') ?></td>
                            <td><?= esc($row['status'] ?? '') ?></td>
                            <td><?= (int) ($row['member_count'] ?? 0) ?></td>
                            <td><?= (int) ($row['eligible_member_count'] ?? 0) ?></td>
                            <td><?= esc($row['total_preview_amount'] ?? '0') ?></td>
                            <td><?= ! empty($row['is_payable']) ? 'Yes' : 'No' ?></td>
                            <td><?= ! empty($row['is_posted']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'commissions'): ?>
    <div class="alert alert-warning mt-3">Simulation preview only — actual commission posting is not activated unless explicitly enabled.</div>
    <div class="card-premium">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Run Ref</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Eligible Purchases</th>
                    <th>Preview Amount</th>
                    <th>Payable</th>
                    <th>Posted</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-secondary">No commission simulation runs yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['run_ref'] ?? '') ?></td>
                            <td><?= esc($row['calculation_date'] ?? '') ?></td>
                            <td><?= esc($row['status'] ?? '') ?></td>
                            <td><?= (int) ($row['eligible_purchase_count'] ?? 0) ?></td>
                            <td><?= esc($row['total_preview_amount'] ?? '0') ?></td>
                            <td><?= ! empty($row['is_payable']) ? 'Yes' : 'No' ?></td>
                            <td><?= ! empty($row['is_posted']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab !== 'overview' && $pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-secondary small">Page <?= $page ?> of <?= $pages ?></div>
        <div class="d-flex gap-2">
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-outline-gold" href="<?= esc($queryBase(['page' => $page - 1])) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $pages): ?>
                <a class="btn btn-sm btn-outline-gold" href="<?= esc($queryBase(['page' => $page + 1])) ?>">Next</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
