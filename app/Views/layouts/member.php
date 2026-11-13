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
<?php $uri = uri_string(); ?>
<div class="app-shell" data-app-shell>
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img class="brand-logo-sm" src="<?= base_url('assets/images/logo.jpeg') ?>" alt="<?= esc(config('Platform')->appName) ?> logo">
            <span>NGW</span>
        </div>

        <a class="side-link <?= $uri === 'member/dashboard' ? 'active' : '' ?>" href="<?= site_url('member/dashboard') ?>">
            <i class="bi bi-grid-1x2"></i><span class="nav-label">Dashboard</span>
        </a>
        <a class="side-link <?= $uri === 'member/purchases/buy' ? 'active' : '' ?>" href="<?= site_url('member/purchases/buy') ?>">
            <i class="bi bi-cart3"></i><span class="nav-label">Buy Coins</span>
        </a>
        <a class="side-link <?= $uri === 'member/purchases' || (str_starts_with($uri, 'member/purchases/') && $uri !== 'member/purchases/buy' && $uri !== 'member/purchases/holdings') ? 'active' : '' ?>" href="<?= site_url('member/purchases') ?>">
            <i class="bi bi-receipt"></i><span class="nav-label">Purchase History</span>
        </a>
        <a class="side-link <?= $uri === 'member/wallet' || $uri === 'member/purchases/holdings' ? 'active' : '' ?>" href="<?= site_url('member/wallet') ?>">
            <i class="bi bi-coin"></i><span class="nav-label">Coin Wallet</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'member/wallet/transactions') ? 'active' : '' ?>" href="<?= site_url('member/wallet/transactions') ?>">
            <i class="bi bi-clock-history"></i><span class="nav-label">Wallet History</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'member/locking') ? 'active' : '' ?>" href="<?= site_url('member/locking') ?>">
            <i class="bi bi-lock"></i><span class="nav-label">Coin Locking</span>
        </a>
        <a class="side-link <?= $uri === 'member/deposits/create' ? 'active' : '' ?>" href="<?= site_url('member/deposits/create') ?>">
            <i class="bi bi-wallet2"></i><span class="nav-label">USDT Deposit</span>
        </a>
        <a class="side-link <?= $uri === 'member/deposits' || (str_starts_with($uri, 'member/deposits/') && $uri !== 'member/deposits/create') ? 'active' : '' ?>" href="<?= site_url('member/deposits') ?>">
            <i class="bi bi-clock-history"></i><span class="nav-label">Deposit History</span>
        </a>
        <a class="side-link" href="<?= site_url('member/coming-soon/mining') ?>"><i class="bi bi-cpu"></i><span class="nav-label">Daily Mining</span></a>
        <a class="side-link" href="<?= site_url('member/coming-soon/mining-wallet') ?>"><i class="bi bi-safe2"></i><span class="nav-label">Mining Wallet</span></a>
        <a class="side-link" href="<?= site_url('member/coming-soon/p2p') ?>"><i class="bi bi-arrow-left-right"></i><span class="nav-label">P2P Transfer</span></a>
        <a class="side-link" href="<?= site_url('member/coming-soon/referrals') ?>"><i class="bi bi-people"></i><span class="nav-label">My Referrals</span></a>
        <a class="side-link" href="<?= site_url('member/coming-soon/level-income') ?>"><i class="bi bi-layers"></i><span class="nav-label">Level Income</span></a>
        <a class="side-link" href="<?= site_url('member/coming-soon/commission-wallet') ?>"><i class="bi bi-wallet2"></i><span class="nav-label">Commission Wallet</span></a>
        <a class="side-link" href="<?= site_url('member/coming-soon/withdrawal') ?>"><i class="bi bi-cash-coin"></i><span class="nav-label">Withdrawal</span></a>
        <a class="side-link <?= $uri === 'member/profile' ? 'active' : '' ?>" href="<?= site_url('member/profile') ?>">
            <i class="bi bi-person-circle"></i><span class="nav-label">My Profile</span>
        </a>
        <a class="side-link <?= $uri === 'member/security' ? 'active' : '' ?>" href="<?= site_url('member/security') ?>">
            <i class="bi bi-shield-lock"></i><span class="nav-label">Security</span>
        </a>
        <a class="side-link" href="<?= site_url('member/coming-soon/support') ?>"><i class="bi bi-life-preserver"></i><span class="nav-label">Support</span></a>
        <a class="side-link" href="<?= site_url('logout') ?>"><i class="bi bi-box-arrow-left"></i><span class="nav-label">Logout</span></a>
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
</body>
</html>
