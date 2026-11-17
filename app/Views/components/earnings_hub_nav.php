<?php
/** @var string $section one of mining|commissions|wallets */
$section = $section ?? '';
?>
<div class="section-hub-links">
    <?php if ($section === 'mining'): ?>
        <?php $miningTab = (string) service('request')->getGet('tab'); ?>
        <a class="admin-tab <?= $miningTab !== 'p2p' && ! str_contains(uri_string(), 'rates') ? 'active' : '' ?>" href="<?= site_url('admin/mining') ?>">Mining Dashboard</a>
        <a class="admin-tab <?= str_contains(uri_string(), 'rates') ? 'active' : '' ?>" href="<?= site_url('admin/mining/rates') ?>">Rate Config</a>
        <a class="admin-tab <?= $miningTab === 'p2p' ? 'active' : '' ?>" href="<?= site_url('admin/mining?tab=p2p') ?>">P2P Monitoring</a>
        <span class="admin-tab" style="opacity:.7;cursor:default">Simulation only · posting/P2P off</span>
    <?php elseif ($section === 'commissions'): ?>
        <a class="admin-tab <?= str_starts_with(uri_string(), 'admin/commissions') && ! str_contains(uri_string(), 'wallet') ? 'active' : '' ?>" href="<?= site_url('admin/commissions') ?>">Commissions</a>
        <a class="admin-tab <?= str_starts_with(uri_string(), 'admin/referrals') || uri_string() === 'admin/genealogy' ? 'active' : '' ?>" href="<?= site_url('admin/referrals') ?>">Referrals &amp; Genealogy</a>
        <a class="admin-tab <?= str_starts_with(uri_string(), 'admin/commission-wallet') || str_starts_with(uri_string(), 'admin/commission-postings') ? 'active' : '' ?>" href="<?= site_url('admin/commission-wallet') ?>">Commission Wallet</a>
        <span class="admin-tab" style="opacity:.7;cursor:default">Posting &amp; withdrawals disabled</span>
    <?php else: ?>
        <a class="admin-tab <?= str_starts_with(uri_string(), 'admin/wallets') || str_starts_with(uri_string(), 'admin/wallet') ? 'active' : '' ?>" href="<?= site_url('admin/wallets') ?>">Wallets</a>
        <a class="admin-tab <?= str_starts_with(uri_string(), 'admin/withdrawals') ? 'active' : '' ?>" href="<?= site_url('admin/withdrawals') ?>">Withdrawals</a>
        <span class="admin-tab" style="opacity:.7;cursor:default">Blockchain payout disabled</span>
    <?php endif; ?>
</div>
