<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'wallets']) ?>
<p class="page-subtitle">Read-only member coin wallet lookup. Search by Member ID, name, mobile, or email. Direct balance editing is not available.</p>

<form class="card-premium mb-4" method="get" action="<?= site_url('admin/wallets') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-8">
            <label class="form-label" for="q">Search members</label>
            <input class="form-control" id="q" name="q" value="<?= esc($query) ?>" placeholder="Member ID, name, mobile, or email" required>
        </div>
        <div class="col-md-4 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Search Wallets</button>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/wallets/reconcile') ?>">Reconciliation</a>
        </div>
    </div>
</form>

<?php if (! empty($error)): ?>
    <div class="alert alert-warning"><?= esc($error) ?></div>
<?php endif; ?>

<?php if ($results !== []): ?>
    <div class="card-premium">
        <h2 class="h5 mb-3">Search Results</h2>
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Member ID</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Total Owned</th>
                    <th>Available</th>
                    <th>Locked</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $result): ?>
                    <?php $wallet = $result['overview']; $profile = $result['profile']; ?>
                    <tr>
                        <td><?= esc($profile->member_id) ?></td>
                        <td><?= esc($profile->member_name) ?></td>
                        <td><?= esc($profile->account_status) ?></td>
                        <td style="color:var(--gold-bright)"><?= esc($wallet['balance']) ?></td>
                        <td><?= esc($wallet['available']) ?></td>
                        <td><?= esc($wallet['locked']) ?></td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-gold" href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id)) ?>">Overview</a>
                            <a class="btn btn-sm btn-outline-gold" href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id) . '/transactions') ?>">Ledger</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($query === ''): ?>
    <div class="card-premium text-secondary">Enter a Member ID or name to inspect a wallet. All wallet pages are read-only.</div>
<?php endif; ?>
<?= $this->endSection() ?>
