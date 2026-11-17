<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= csrf_token() ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title><?= esc($title ?? 'Member') ?> · <?= esc(config('Platform')->appName) ?></title>
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
?>
<div class="app-shell" data-app-shell>
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img class="brand-logo-sm" src="<?= base_url('assets/images/logo.jpeg') ?>" alt="<?= esc(config('Platform')->appName) ?> logo">
            <span>NGW</span>
        </div>

        <div class="nav-section-title">Account</div>
        <a class="side-link <?= $uri === 'member/dashboard' ? 'active' : '' ?>" href="<?= site_url('member/dashboard') ?>">
            <i class="bi bi-grid-1x2"></i><span class="nav-label">Dashboard</span>
        </a>
        <a class="side-link <?= $uri === 'member/profile' ? 'active' : '' ?>" href="<?= site_url('member/profile') ?>">
            <i class="bi bi-person-circle"></i><span class="nav-label">My Profile</span>
        </a>
        <a class="side-link <?= $uri === 'member/security' ? 'active' : '' ?>" href="<?= site_url('member/security') ?>">
            <i class="bi bi-shield-lock"></i><span class="nav-label">Security</span>
        </a>

        <div class="nav-section-title">Coins &amp; Payments</div>
        <a class="side-link <?= $is('member/buy-coins') || $uri === 'member/purchases/buy' ? 'active' : '' ?>" href="<?= site_url('member/buy-coins') ?>">
            <i class="bi bi-cart-plus"></i><span class="nav-label">Buy Coins</span>
        </a>
        <a class="side-link <?= $uri === 'member/purchases' || ($is('member/purchases') && $uri !== 'member/purchases/buy' && $uri !== 'member/purchases/holdings') ? 'active' : '' ?>" href="<?= site_url('member/purchases') ?>">
            <i class="bi bi-receipt"></i><span class="nav-label">My Purchases</span>
        </a>
        <a class="side-link <?= $is('member/deposits') ? 'active' : '' ?>" href="<?= site_url('member/deposits') ?>">
            <i class="bi bi-clock-history"></i><span class="nav-label">Payment History</span>
        </a>
        <a class="side-link <?= $uri === 'member/wallet' || $uri === 'member/purchases/holdings' || $is('member/wallet/transactions') ? 'active' : '' ?>" href="<?= site_url('member/wallet') ?>">
            <i class="bi bi-coin"></i><span class="nav-label">My Wallet</span>
        </a>
        <a class="side-link <?= $is('member/locking') ? 'active' : '' ?>" href="<?= site_url('member/locking') ?>">
            <i class="bi bi-lock"></i><span class="nav-label">Coin Locking</span>
        </a>

        <div class="nav-section-title">Team</div>
        <a class="side-link <?= $uri === 'member/referrals' ? 'active' : '' ?>" href="<?= site_url('member/referrals') ?>">
            <i class="bi bi-people"></i><span class="nav-label">My Referrals</span>
        </a>
        <a class="side-link <?= $is('member/genealogy') ? 'active' : '' ?>" href="<?= site_url('member/genealogy') ?>">
            <i class="bi bi-diagram-3"></i><span class="nav-label">Genealogy</span>
        </a>

        <div class="nav-section-title">Earnings (Preview)</div>
        <a class="side-link <?= $is('member/mining') ? 'active' : '' ?>" href="<?= site_url('member/mining') ?>">
            <i class="bi bi-cpu"></i><span class="nav-label">Mining Simulation</span>
        </a>
        <a class="side-link <?= $is('member/commissions') ? 'active' : '' ?>" href="<?= site_url('member/commissions') ?>">
            <i class="bi bi-layers"></i><span class="nav-label">Commission Simulation</span>
        </a>
        <a class="side-link <?= $is('member/commission-wallet') ? 'active' : '' ?>" href="<?= site_url('member/commission-wallet') ?>">
            <i class="bi bi-wallet"></i><span class="nav-label">Commission Wallet</span>
        </a>
        <a class="side-link <?= $is('member/withdrawals') ? 'active' : '' ?>" href="<?= site_url('member/withdrawals') ?>">
            <i class="bi bi-cash-coin"></i><span class="nav-label">Withdrawals</span>
        </a>

        <div class="nav-section-title">Help</div>
        <a class="side-link <?= str_starts_with($uri, 'member/support') || str_contains($uri, 'coming-soon/support') ? 'active' : '' ?>" href="<?= site_url('member/support') ?>">
            <i class="bi bi-life-preserver"></i><span class="nav-label">Support</span>
        </a>
        <a class="side-link" href="<?= site_url('logout') ?>">
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
                <strong><?= esc($title ?? 'Member') ?></strong>
            </div>
            <div class="text-secondary small">Member Panel</div>
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
