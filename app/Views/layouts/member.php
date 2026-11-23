<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= csrf_token() ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <?php if (! empty($supportViewMode)): ?>
        <meta http-equiv="Cache-Control" content="no-store">
    <?php endif; ?>
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
$supportViewMode = ! empty($supportViewMode);
$panelBase       = $supportViewMode ? 'support-view' : 'member';
$uri             = uri_string();
$is = static function (string $prefix) use ($uri): bool {
    return $uri === $prefix || str_starts_with($uri, $prefix . '/');
};
$panelUrl = static function (string $path) use ($panelBase): string {
    return site_url($panelBase . '/' . ltrim($path, '/'));
};
$sv = $supportViewContext ?? null;
?>
<div class="app-shell" data-app-shell>
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img class="brand-logo-sm" src="<?= base_url('assets/images/logo.jpeg') ?>" alt="<?= esc(config('Platform')->appName) ?> logo">
            <span>NGW</span>
        </div>

        <div class="nav-section-title">Account</div>
        <a class="side-link <?= $is($panelBase . '/dashboard') ? 'active' : '' ?>" href="<?= $panelUrl('dashboard') ?>">
            <i class="bi bi-grid-1x2"></i><span class="nav-label">Dashboard</span>
        </a>
        <a class="side-link <?= $is($panelBase . '/profile') ? 'active' : '' ?>" href="<?= $panelUrl('profile') ?>">
            <i class="bi bi-person-circle"></i><span class="nav-label">My Profile</span>
        </a>
        <?php if (! $supportViewMode): ?>
        <a class="side-link <?= $uri === 'member/security' ? 'active' : '' ?>" href="<?= site_url('member/security') ?>">
            <i class="bi bi-shield-lock"></i><span class="nav-label">Security</span>
        </a>
        <?php endif; ?>

        <div class="nav-section-title">Coins &amp; Payments</div>
        <?php if (! $supportViewMode): ?>
        <a class="side-link <?= $is('member/buy-coins') || $uri === 'member/purchases/buy' ? 'active' : '' ?>" href="<?= site_url('member/buy-coins') ?>">
            <i class="bi bi-cart-plus"></i><span class="nav-label">Buy Coins</span>
        </a>
        <?php endif; ?>
        <a class="side-link <?= $is($panelBase . '/purchases') ? 'active' : '' ?>" href="<?= $panelUrl('purchases') ?>">
            <i class="bi bi-receipt"></i><span class="nav-label">My Purchases</span>
        </a>
        <a class="side-link <?= $is($panelBase . '/deposits') ? 'active' : '' ?>" href="<?= $panelUrl('deposits') ?>">
            <i class="bi bi-clock-history"></i><span class="nav-label">Payment History</span>
        </a>
        <a class="side-link <?= $is($panelBase . '/wallet') ? 'active' : '' ?>" href="<?= $panelUrl('wallet') ?>">
            <i class="bi bi-coin"></i><span class="nav-label">My Wallet</span>
        </a>
        <a class="side-link ps-4 <?= $uri === $panelBase . '/mining' ? 'active' : '' ?>" href="<?= $panelUrl('mining') ?>">
            <i class="bi bi-cpu"></i><span class="nav-label">Mining Wallet</span>
        </a>
        <a class="side-link ps-4 <?= $is($panelBase . '/mining/history') ? 'active' : '' ?>" href="<?= $panelUrl('mining/history') ?>">
            <i class="bi bi-journal-text"></i><span class="nav-label">Mining History</span>
        </a>
        <a class="side-link <?= $is($panelBase . '/locking') ? 'active' : '' ?>" href="<?= $panelUrl('locking') ?>">
            <i class="bi bi-lock"></i><span class="nav-label">Coin Locking</span>
        </a>
        <?php if (! $supportViewMode): ?>
        <a class="side-link <?= $is('member/coin-selling') ? 'active' : '' ?>" href="<?= site_url('member/coin-selling') ?>">
            <i class="bi bi-shop"></i><span class="nav-label">Coin Selling</span>
        </a>
        <?php endif; ?>

        <div class="nav-section-title">Team</div>
        <a class="side-link <?= $is($panelBase . '/referrals') && ! $is($panelBase . '/referrals/business') ? 'active' : '' ?>" href="<?= $panelUrl('referrals') ?>">
            <i class="bi bi-people"></i><span class="nav-label">My Referrals</span>
        </a>
        <a class="side-link <?= $is($panelBase . '/referrals/business') ? 'active' : '' ?>" href="<?= $panelUrl('referrals/business') ?>">
            <i class="bi bi-graph-up"></i><span class="nav-label">Business Volume</span>
        </a>
        <a class="side-link <?= $is($panelBase . '/genealogy') ? 'active' : '' ?>" href="<?= $panelUrl('genealogy') ?>">
            <i class="bi bi-diagram-3"></i><span class="nav-label">Genealogy</span>
        </a>

        <div class="nav-section-title">Earnings</div>
        <a class="side-link <?= $is($panelBase . '/commissions') ? 'active' : '' ?>" href="<?= $panelUrl('commissions') ?>">
            <i class="bi bi-layers"></i><span class="nav-label">Commission Simulation</span>
        </a>
        <?php if (! $supportViewMode): ?>
        <a class="side-link <?= $is('member/p2p') ? 'active' : '' ?>" href="<?= site_url('member/p2p') ?>">
            <i class="bi bi-arrow-left-right"></i><span class="nav-label">Mining P2P</span>
        </a>
        <a class="side-link <?= $is('member/commission-wallet') ? 'active' : '' ?>" href="<?= site_url('member/commission-wallet') ?>">
            <i class="bi bi-wallet"></i><span class="nav-label">Commission Wallet</span>
        </a>
        <a class="side-link <?= $is('member/withdrawals') ? 'active' : '' ?>" href="<?= site_url('member/withdrawals') ?>">
            <i class="bi bi-cash-coin"></i><span class="nav-label">Withdrawals</span>
        </a>
        <?php endif; ?>

        <div class="nav-section-title">Help</div>
        <a class="side-link <?= $is($panelBase . '/support') ? 'active' : '' ?>" href="<?= $panelUrl('support') ?>">
            <i class="bi bi-life-preserver"></i><span class="nav-label">Support</span>
        </a>
        <?php if ($supportViewMode): ?>
        <form method="post" action="<?= site_url('support-view/end') ?>" class="px-3 mt-2">
            <?= csrf_field() ?>
            <button class="btn btn-outline-gold btn-sm w-100" type="submit">
                <i class="bi bi-x-circle"></i> End Member View
            </button>
        </form>
        <?php else: ?>
        <a class="side-link" href="<?= site_url('logout') ?>">
            <i class="bi bi-box-arrow-left"></i><span class="nav-label">Logout</span>
        </a>
        <?php endif; ?>
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
            <div class="text-secondary small"><?= $supportViewMode ? 'Admin Support View' : 'Member Panel' ?></div>
        </header>
        <main class="content">
            <?php if ($supportViewMode && is_array($sv)): ?>
                <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3" role="status">
                    <div>
                        <strong>ADMIN VIEW — Viewing member <?= esc((string) ($sv['target_member_id'] ?? '')) ?></strong>
                        <div class="small mb-0">
                            <?= esc((string) ($sv['target_name'] ?? '')) ?> · Read-only Support Mode · Not a member login
                        </div>
                    </div>
                    <form method="post" action="<?= site_url('support-view/end') ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-dark" type="submit">End Session</button>
                    </form>
                </div>
            <?php endif; ?>
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
