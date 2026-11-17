<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'commissions']) ?>
<p class="page-subtitle">Actual commission wallet balances come from posted ledger entries only. Simulation previews are separate.</p>
<div class="alert alert-warning">Commission Posting: <?= esc($wallet['posting_status']) ?> · Withdrawals: <?= esc($wallet['withdrawal_status']) ?> · Blockchain payout: DISABLED</div>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>
<div class="mt-3 d-flex gap-2">
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commission-wallet/reconcile') ?>">Reconciliation</a>
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commission-postings') ?>">Posting Proposals</a>
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/withdrawals') ?>">Withdrawals</a>
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commissions') ?>">Simulation Dashboard</a>
</div>
<?= $this->endSection() ?>
