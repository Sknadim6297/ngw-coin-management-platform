<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Home::index');

// Public auth
$routes->group('', ['filter' => 'guest'], static function (RouteCollection $routes): void {
    $routes->get('register', 'Auth\RegisterController::index');
    $routes->post('register', 'Auth\RegisterController::store');
    $routes->get('login', 'Auth\LoginController::index');
    $routes->post('login', 'Auth\LoginController::attempt');
    $routes->get('forgot-password', 'Auth\ForgotPasswordController::index');
    $routes->post('forgot-password', 'Auth\ForgotPasswordController::send');
    $routes->get('reset-password/(:segment)', 'Auth\ForgotPasswordController::resetForm/$1');
    $routes->post('reset-password/(:segment)', 'Auth\ForgotPasswordController::reset/$1');
    $routes->get('admin/login', 'Admin\AuthController::login');
    $routes->post('admin/login', 'Admin\AuthController::attempt');
});

$routes->post('api/sponsor-lookup', 'Auth\SponsorLookupController::lookup');
$routes->get('logout', 'Auth\LoginController::logout');
$routes->post('logout', 'Auth\LoginController::logout');

// Admin panel
$routes->group('admin', ['filter' => 'admin'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Admin\DashboardController::index');
    $routes->get('logout', 'Admin\AuthController::logout');
    $routes->post('logout', 'Admin\AuthController::logout');

    $routes->get('members', 'Admin\MemberController::index');
    $routes->get('members/active', 'Admin\MemberController::index/active');
    $routes->get('members/inactive', 'Admin\MemberController::index/inactive');
    $routes->get('members/create', 'Admin\MemberController::create');
    $routes->post('members', 'Admin\MemberController::store');
    $routes->get('members/(:segment)', 'Admin\MemberController::show/$1');

    // Coin inventory & pricing (Step 2)
    $routes->get('coins', 'Admin\CoinController::index');
    $routes->get('coins/inventory', 'Admin\CoinController::inventory');
    $routes->get('coins/inventory/add', 'Admin\CoinController::addForm');
    $routes->post('coins/inventory/add', 'Admin\CoinController::addStore');
    $routes->get('coins/inventory/history', 'Admin\CoinController::inventoryHistory');
    $routes->get('coins/price', 'Admin\CoinController::price');
    $routes->post('coins/price', 'Admin\CoinController::priceUpdate');
    $routes->get('coins/price/history', 'Admin\CoinController::priceHistory');

    // Coin purchase requests (Step 3)
    $routes->get('purchases', 'Admin\PurchaseController::index');
    $routes->get('purchases/(:segment)', 'Admin\PurchaseController::show/$1');
    $routes->post('purchases/(:segment)/approve', 'Admin\PurchaseController::approve/$1');
    $routes->post('purchases/(:segment)/reject', 'Admin\PurchaseController::reject/$1');

    // USDT deposits & networks (Step 4A)
    $routes->get('deposits', 'Admin\DepositController::index');
    $routes->get('deposits/(:segment)/evidence', 'Admin\DepositController::evidence/$1');
    $routes->post('deposits/(:segment)/under-review', 'Admin\DepositController::underReview/$1');
    $routes->post('deposits/(:segment)/reject', 'Admin\DepositController::reject/$1');
    $routes->post('deposits/(:segment)/notes', 'Admin\DepositController::notes/$1');
    $routes->post('deposits/(:segment)/attempt-verify', 'Admin\DepositController::attemptVerify/$1');
    $routes->post('deposits/(:segment)/manual-verify', 'Admin\DepositController::manualVerifyBlocked/$1');
    $routes->get('deposits/(:segment)', 'Admin\DepositController::show/$1');

    $routes->get('networks', 'Admin\PaymentNetworkController::index');
    $routes->get('networks/create', 'Admin\PaymentNetworkController::create');
    $routes->post('networks', 'Admin\PaymentNetworkController::store');
    $routes->get('networks/(:num)/edit', 'Admin\PaymentNetworkController::edit/$1');
    $routes->post('networks/(:num)', 'Admin\PaymentNetworkController::update/$1');

    // Phase 4C payment operations (admin-only, no live activation)
    $routes->get('payment-health', 'Admin\PaymentOpsController::health');
    $routes->post('payment-health/probe', 'Admin\PaymentOpsController::healthProbe');
    $routes->get('payment-reconciliation', 'Admin\PaymentOpsController::reconciliation');
    $routes->get('payment-audit', 'Admin\PaymentOpsController::audit');
    $routes->get('payment-audit/(:segment)', 'Admin\PaymentOpsController::auditShow/$1');
    $routes->post('payment-audit/(:segment)/retry-verify', 'Admin\PaymentOpsController::retryVerification/$1');
    $routes->post('payment-audit/(:segment)/integrity-recheck', 'Admin\PaymentOpsController::integrityRecheck/$1');
    $routes->get('deployment-preflight', 'Admin\PaymentOpsController::preflight');

    // Phase 4D/4E finance controls
    $routes->get('finance', 'Admin\FinanceOpsController::dashboard');
    $routes->get('finance/alerts', 'Admin\FinanceOpsController::alerts');
    $routes->post('finance/alerts/(:segment)/ack', 'Admin\FinanceOpsController::acknowledgeAlert/$1');
    $routes->post('finance/monitor/run', 'Admin\FinanceOpsController::runMonitor');
    $routes->get('finance/notifications', 'Admin\FinanceOpsController::notifications');
    $routes->get('finance/allocations', 'Admin\FinanceOpsController::allocations');
    $routes->get('finance/allocations/(:segment)', 'Admin\FinanceOpsController::allocationShow/$1');
    $routes->get('finance/remediations', 'Admin\FinanceOpsController::remediations');
    $routes->get('finance/remediations/create', 'Admin\FinanceOpsController::remediationCreate');
    $routes->post('finance/remediations', 'Admin\FinanceOpsController::remediationStore');
    $routes->get('finance/remediations/(:segment)', 'Admin\FinanceOpsController::remediationShow/$1');
    $routes->post('finance/remediations/(:segment)/approve', 'Admin\FinanceOpsController::remediationApprove/$1');
    $routes->post('finance/remediations/(:segment)/reject', 'Admin\FinanceOpsController::remediationReject/$1');

    // Phase 5 coin locking
    $routes->get('locking', 'Admin\CoinLockingController::index');
    $routes->get('locking/plans', 'Admin\CoinLockingController::plans');
    $routes->get('locking/plans/create', 'Admin\CoinLockingController::planCreate');
    $routes->post('locking/plans', 'Admin\CoinLockingController::planStore');
    $routes->post('locking/plans/(:num)/toggle', 'Admin\CoinLockingController::planToggle/$1');
    $routes->post('locking/maturity/run', 'Admin\CoinLockingController::runMaturity');
    $routes->get('locking/(:segment)', 'Admin\CoinLockingController::show/$1');

    // Phase 6 member wallet management (admin read-only)
    $routes->get('wallets', 'Admin\WalletController::index');
    $routes->get('wallets/reconcile', 'Admin\WalletController::reconcile');
    $routes->get('wallets/(:segment)', 'Admin\WalletController::show/$1');
    $routes->get('wallets/(:segment)/transactions/(:segment)', 'Admin\WalletController::transaction/$1/$2');

    $routes->get('coming-soon/(:segment)', 'Admin\PlaceholderController::show/$1');
});

// Member panel
$routes->group('member', ['filter' => 'member'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Member\DashboardController::index');
    $routes->get('profile', 'Member\ProfileController::index');
    $routes->get('security', 'Member\SecurityController::index');
    $routes->post('security/password', 'Member\SecurityController::updatePassword');

    // Coin purchase requests (Step 3)
    $routes->get('purchases/buy', 'Member\PurchaseController::buyForm');
    $routes->post('purchases', 'Member\PurchaseController::buyStore');
    $routes->get('purchases', 'Member\PurchaseController::history');
    $routes->get('purchases/holdings', 'Member\PurchaseController::holdings');
    $routes->get('purchases/(:segment)', 'Member\PurchaseController::show/$1');
    $routes->post('purchases/(:segment)/cancel', 'Member\PurchaseController::cancel/$1');
    $routes->post('purchases/(:segment)/link-deposit', 'Member\PurchaseController::linkDeposit/$1');

    // USDT deposits (Step 4A)
    $routes->get('deposits/create', 'Member\DepositController::create');
    $routes->post('deposits', 'Member\DepositController::store');
    $routes->get('deposits', 'Member\DepositController::index');
    $routes->get('deposits/(:segment)/evidence', 'Member\DepositController::evidence/$1');
    $routes->post('deposits/(:segment)/cancel', 'Member\DepositController::cancel/$1');
    $routes->get('deposits/(:segment)', 'Member\DepositController::show/$1');

    // Phase 5 coin locking
    $routes->get('locking', 'Member\CoinLockingController::index');
    $routes->post('locking', 'Member\CoinLockingController::store');
    $routes->get('locking/history', 'Member\CoinLockingController::history');
    $routes->get('locking/(:segment)', 'Member\CoinLockingController::show/$1');
    $routes->post('locking/(:segment)/unlock', 'Member\CoinLockingController::unlock/$1');

    // Phase 6 internal coin wallet
    $routes->get('wallet', 'Member\WalletController::index');
    $routes->get('wallet/transactions', 'Member\WalletController::transactions');
    $routes->get('wallet/transactions/export', 'Member\WalletController::export');
    $routes->get('wallet/transactions/(:segment)', 'Member\WalletController::show/$1');

    $routes->get('coming-soon/(:segment)', 'Member\PlaceholderController::show/$1');
});
