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
<?php $uri = uri_string(); ?>
<div class="app-shell" data-app-shell>
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img class="brand-logo-sm" src="<?= base_url('assets/images/logo.jpeg') ?>" alt="<?= esc(config('Platform')->appName) ?> logo">
            <span>NGW Admin</span>
        </div>

        <div class="nav-section-title">Main</div>
        <a class="side-link <?= $uri === 'admin/dashboard' ? 'active' : '' ?>" href="<?= site_url('admin/dashboard') ?>">
            <i class="bi bi-grid-1x2"></i><span class="nav-label">Dashboard</span>
        </a>

        <div class="nav-section-title">Member Management</div>
        <a class="side-link <?= str_starts_with($uri, 'admin/members/create') ? 'active' : '' ?>" href="<?= site_url('admin/members/create') ?>">
            <i class="bi bi-person-plus"></i><span class="nav-label">Register Member</span>
        </a>
        <a class="side-link <?= $uri === 'admin/members' ? 'active' : '' ?>" href="<?= site_url('admin/members') ?>">
            <i class="bi bi-people"></i><span class="nav-label">All Members</span>
        </a>
        <div class="submenu">
            <a class="<?= $uri === 'admin/members/active' ? 'active' : '' ?>" href="<?= site_url('admin/members/active') ?>">Active Members</a>
            <a class="<?= $uri === 'admin/members/inactive' ? 'active' : '' ?>" href="<?= site_url('admin/members/inactive') ?>">Inactive Members</a>
            <a class="<?= str_starts_with($uri, 'admin/members/') && ! in_array($uri, ['admin/members/create', 'admin/members/active', 'admin/members/inactive'], true) ? 'active' : '' ?>" href="<?= site_url('admin/members') ?>">Member Profile</a>
        </div>

        <div class="nav-section-title">Coin Management</div>
        <a class="side-link <?= $uri === 'admin/coins' ? 'active' : '' ?>" href="<?= site_url('admin/coins') ?>">
            <i class="bi bi-speedometer2"></i><span class="nav-label">Overview</span>
        </a>
        <a class="side-link <?= $uri === 'admin/coins/inventory' ? 'active' : '' ?>" href="<?= site_url('admin/coins/inventory') ?>">
            <i class="bi bi-box-seam"></i><span class="nav-label">Coin Inventory</span>
        </a>
        <a class="side-link <?= $uri === 'admin/coins/inventory/add' ? 'active' : '' ?>" href="<?= site_url('admin/coins/inventory/add') ?>">
            <i class="bi bi-plus-circle"></i><span class="nav-label">Add Coins</span>
        </a>
        <a class="side-link <?= $uri === 'admin/coins/inventory/history' ? 'active' : '' ?>" href="<?= site_url('admin/coins/inventory/history') ?>">
            <i class="bi bi-journal-text"></i><span class="nav-label">Inventory History</span>
        </a>
        <a class="side-link <?= $uri === 'admin/coins/price' ? 'active' : '' ?>" href="<?= site_url('admin/coins/price') ?>">
            <i class="bi bi-currency-exchange"></i><span class="nav-label">Coin Price Settings</span>
        </a>
        <a class="side-link <?= $uri === 'admin/coins/price/history' ? 'active' : '' ?>" href="<?= site_url('admin/coins/price/history') ?>">
            <i class="bi bi-clock-history"></i><span class="nav-label">Price Change History</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/purchases') && str_contains(current_url(), 'status=pending') ? 'active' : '' ?>" href="<?= site_url('admin/purchases?status=pending') ?>">
            <i class="bi bi-bag-check"></i><span class="nav-label">Purchase Requests</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/purchases') && ! str_contains(current_url(), 'status=pending') ? 'active' : '' ?>" href="<?= site_url('admin/purchases') ?>">
            <i class="bi bi-journal-check"></i><span class="nav-label">Purchase History</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/locking') ? 'active' : '' ?>" href="<?= site_url('admin/locking') ?>">
            <i class="bi bi-lock"></i><span class="nav-label">Coin Locking</span>
        </a>

        <div class="nav-section-title">USDT Deposits</div>
        <a class="side-link <?= str_starts_with($uri, 'admin/deposits') && str_contains(current_url(), 'status=submitted') ? 'active' : '' ?>" href="<?= site_url('admin/deposits?status=submitted') ?>">
            <i class="bi bi-hourglass-split"></i><span class="nav-label">Submitted</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/deposits') && str_contains(current_url(), 'status=under_review') ? 'active' : '' ?>" href="<?= site_url('admin/deposits?status=under_review') ?>">
            <i class="bi bi-search"></i><span class="nav-label">Under Review</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/deposits') && ! str_contains(current_url(), 'status=') ? 'active' : '' ?>" href="<?= site_url('admin/deposits') ?>">
            <i class="bi bi-collection"></i><span class="nav-label">All Deposits</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/networks') ? 'active' : '' ?>" href="<?= site_url('admin/networks') ?>">
            <i class="bi bi-diagram-3"></i><span class="nav-label">Payment Networks</span>
        </a>

        <div class="nav-section-title">Payment Ops</div>
        <a class="side-link <?= $uri === 'admin/payment-health' ? 'active' : '' ?>" href="<?= site_url('admin/payment-health') ?>">
            <i class="bi bi-heart-pulse"></i><span class="nav-label">Payment Health</span>
        </a>
        <a class="side-link <?= $uri === 'admin/payment-reconciliation' ? 'active' : '' ?>" href="<?= site_url('admin/payment-reconciliation') ?>">
            <i class="bi bi-clipboard2-check"></i><span class="nav-label">Reconciliation</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/payment-audit') ? 'active' : '' ?>" href="<?= site_url('admin/payment-audit') ?>">
            <i class="bi bi-shield-check"></i><span class="nav-label">Payment Audit</span>
        </a>
        <a class="side-link <?= $uri === 'admin/deployment-preflight' ? 'active' : '' ?>" href="<?= site_url('admin/deployment-preflight') ?>">
            <i class="bi bi-rocket-takeoff"></i><span class="nav-label">Preflight</span>
        </a>
        <a class="side-link <?= $uri === 'admin/finance' ? 'active' : '' ?>" href="<?= site_url('admin/finance') ?>">
            <i class="bi bi-pie-chart"></i><span class="nav-label">Finance Overview</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/finance/alerts') ? 'active' : '' ?>" href="<?= site_url('admin/finance/alerts') ?>">
            <i class="bi bi-bell"></i><span class="nav-label">Financial Alerts</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/finance/notifications') ? 'active' : '' ?>" href="<?= site_url('admin/finance/notifications') ?>">
            <i class="bi bi-envelope"></i><span class="nav-label">Alert Outbox</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/finance/allocations') ? 'active' : '' ?>" href="<?= site_url('admin/finance/allocations') ?>">
            <i class="bi bi-link-45deg"></i><span class="nav-label">Allocations</span>
        </a>
        <a class="side-link <?= str_starts_with($uri, 'admin/finance/remediations') ? 'active' : '' ?>" href="<?= site_url('admin/finance/remediations') ?>">
            <i class="bi bi-journal-medical"></i><span class="nav-label">Remediation</span>
        </a>

        <div class="nav-section-title">More</div>
        <a class="side-link" href="<?= site_url('admin/coming-soon/mining') ?>"><i class="bi bi-cpu"></i><span class="nav-label">Mining Management</span></a>
        <a class="side-link" href="<?= site_url('admin/coming-soon/referrals') ?>"><i class="bi bi-diagram-3"></i><span class="nav-label">Referral & Genealogy</span></a>
        <a class="side-link <?= str_starts_with($uri, 'admin/wallets') ? 'active' : '' ?>" href="<?= site_url('admin/wallets') ?>"><i class="bi bi-safe"></i><span class="nav-label">Wallet Management</span></a>
        <a class="side-link" href="<?= site_url('admin/coming-soon/withdrawals') ?>"><i class="bi bi-cash-stack"></i><span class="nav-label">Withdrawals</span></a>
        <a class="side-link" href="<?= site_url('admin/coming-soon/coin-selling') ?>"><i class="bi bi-graph-up-arrow"></i><span class="nav-label">Coin Selling</span></a>
        <a class="side-link" href="<?= site_url('admin/coming-soon/reports') ?>"><i class="bi bi-bar-chart"></i><span class="nav-label">Reports</span></a>
        <a class="side-link" href="<?= site_url('admin/coming-soon/settings') ?>"><i class="bi bi-gear"></i><span class="nav-label">Settings</span></a>
        <a class="side-link" href="<?= site_url('admin/logout') ?>"><i class="bi bi-box-arrow-left"></i><span class="nav-label">Logout</span></a>
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
