<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= csrf_token() ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title><?= esc($title ?? 'Admin') ?> · <?= esc(config('Platform')->appName) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/images/coin.svg') ?>">
    <link rel="alternate icon" type="image/png" href="<?= base_url('assets/images/coin.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body>
<?php
$uri = uri_string();
$is = static function (string $prefix) use ($uri): bool {
    return $uri === $prefix || str_starts_with($uri, $prefix . '/');
};
$isPaymentApprovals = $is('admin/payment-approvals')
    || $is('admin/purchases')
    || $is('admin/deposits');
$isPaymentMonitoring = $is('admin/payment-monitoring')
    || $uri === 'admin/payment-health'
    || $uri === 'admin/payment-reconciliation'
    || $is('admin/payment-audit')
    || $uri === 'admin/deployment-preflight'
    || $uri === 'admin/finance'
    || $is('admin/finance/alerts')
    || $is('admin/finance/notifications')
    || $is('admin/finance/allocations')
    || $is('admin/finance/remediations');
$isCommissions = $is('admin/commissions')
    || $is('admin/commission-wallet')
    || $is('admin/commission-postings')
    || $is('admin/referrals')
    || $uri === 'admin/genealogy';
$isWallets = $is('admin/wallets') || $is('admin/withdrawals');
?>
<div class="app-shell" data-app-shell>
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img class="brand-logo-sm" src="<?= base_url('assets/images/logo.jpeg') ?>" alt="<?= esc(config('Platform')->appName) ?> logo">
            <span>NGW Admin</span>
        </div>

        <div class="nav-section-title">Dashboard</div>
        <a class="side-link <?= $uri === 'admin/dashboard' ? 'active' : '' ?>" href="<?= site_url('admin/dashboard') ?>">
            <i class="bi bi-grid-1x2"></i><span class="nav-label">Dashboard</span>
        </a>

        <div class="nav-section-title">Member Management</div>
        <a class="side-link <?= ($uri === 'admin/members' || $uri === 'admin/members/active' || $uri === 'admin/members/inactive' || (str_starts_with($uri, 'admin/members/') && $uri !== 'admin/members/create')) ? 'active' : '' ?>" href="<?= site_url('admin/members') ?>">
            <i class="bi bi-people"></i><span class="nav-label">All Members</span>
        </a>
        <a class="side-link <?= $uri === 'admin/members/create' ? 'active' : '' ?>" href="<?= site_url('admin/members/create') ?>">
            <i class="bi bi-person-plus"></i><span class="nav-label">Register Member</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/support') ? 'active' : '' ?>" href="<?= site_url('admin/support') ?>">
            <i class="bi bi-life-preserver"></i><span class="nav-label">Support Tickets</span>
        </a>

        <div class="nav-section-title">Coin Management</div>
        <a class="side-link <?= str_starts_with($uri, 'admin/coins/inventory') || $uri === 'admin/coins' ? 'active' : '' ?>" href="<?= site_url('admin/coins/inventory') ?>">
            <i class="bi bi-box-seam"></i><span class="nav-label">Coin Inventory</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/coins/price') ? 'active' : '' ?>" href="<?= site_url('admin/coins/price') ?>">
            <i class="bi bi-currency-exchange"></i><span class="nav-label">Coin Pricing</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/locking') ? 'active' : '' ?>" href="<?= site_url('admin/locking') ?>">
            <i class="bi bi-lock"></i><span class="nav-label">Coin Locking</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/coins/selling') ? 'active' : '' ?>" href="<?= site_url('admin/coins/selling') ?>">
            <i class="bi bi-shop"></i><span class="nav-label">Coin Selling</span>
        </a>

        <div class="nav-section-title">Payment Management</div>
        <a class="side-link <?= $isPaymentApprovals ? 'active' : '' ?>" href="<?= site_url('admin/payment-approvals') ?>">
            <i class="bi bi-shield-check"></i><span class="nav-label">Payment Approvals</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/payment-details') || str_starts_with($uri, 'admin/payment-settings') || str_starts_with($uri, 'admin/networks') ? 'active' : '' ?>" href="<?= site_url('admin/payment-details') ?>">
            <i class="bi bi-qr-code"></i><span class="nav-label">Payment Details</span>
        </a>
        <a class="side-link <?= $isPaymentMonitoring ? 'active' : '' ?>" href="<?= site_url('admin/payment-monitoring') ?>">
            <i class="bi bi-activity"></i><span class="nav-label">Payment Monitoring</span>
        </a>

        <div class="nav-section-title">Earnings &amp; Wallets</div>
        <a class="side-link <?= str_starts_with($uri, 'admin/mining') ? 'active' : '' ?>" href="<?= site_url('admin/mining') ?>">
            <i class="bi bi-cpu"></i><span class="nav-label">Mining</span>
        </a>
        <a class="side-link <?= $isCommissions ? 'active' : '' ?>" href="<?= site_url('admin/commissions') ?>">
            <i class="bi bi-diagram-3"></i><span class="nav-label">Commissions &amp; Referrals</span>
        </a>
        <a class="side-link <?= $isWallets ? 'active' : '' ?>" href="<?= site_url('admin/wallets') ?>">
            <i class="bi bi-safe"></i><span class="nav-label">Wallets &amp; Withdrawals</span>
        </a>

        <div class="nav-section-title">Automation</div>
        <a class="side-link <?= str_starts_with($uri, 'admin/financial-automation') ? 'active' : '' ?>" href="<?= site_url('admin/financial-automation') ?>">
            <i class="bi bi-lightning-charge"></i><span class="nav-label">Financial Automation</span>
        </a>

        <div class="nav-section-title">System</div>
        <a class="side-link <?= str_contains($uri, 'admin/reports') || str_contains($uri, 'coming-soon/reports') ? 'active' : '' ?>" href="<?= site_url('admin/reports') ?>">
            <i class="bi bi-bar-chart"></i><span class="nav-label">Reports</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/financial-policies') ? 'active' : '' ?>" href="<?= site_url('admin/financial-policies') ?>">
            <i class="bi bi-sliders"></i><span class="nav-label">Advanced: Financial Policies</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/settings') || str_contains($uri, 'coming-soon/settings') ? 'active' : '' ?>" href="<?= site_url('admin/settings') ?>">
            <i class="bi bi-gear"></i><span class="nav-label">Settings</span>
        </a>
        <a class="side-link" href="<?= site_url('admin/logout') ?>">
            <i class="bi bi-box-arrow-left"></i><span class="nav-label">Logout</span>
        </a>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-gold mobile-toggle" type="button" data-sidebar-toggle aria-label="Toggle sidebar">
                    <i class="bi bi-list"></i>
                </button>
                <button class="btn btn-outline-gold d-none d-lg-inline-flex" type="button" data-sidebar-toggle aria-label="Collapse sidebar">
                    <i class="bi bi-layout-sidebar"></i>
                </button>
                <strong><?= esc($title ?? 'Admin') ?></strong>
            </div>
            <div class="text-secondary small"><?= esc(auth()->user()?->getEmail() ?? '') ?></div>
        </header>
        <main class="content">
            <?= view('components/alerts') ?>
            <?= $this->renderSection('content') ?>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
