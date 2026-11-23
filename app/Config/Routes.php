<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Home::index');

// app/Config/Routes.php

$routes->get('run-migration', static function () {
    service('migrations')->latest();

    return service('response')->setBody('Migration Success!');
});

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

$routes->get('verify-email/(:segment)', 'Auth\RegistrationEmailVerificationController::verify/$1');
$routes->get('resend-verification', 'Auth\RegistrationEmailVerificationController::resendForm');
$routes->post('resend-verification', 'Auth\RegistrationEmailVerificationController::resend');

$routes->post('api/sponsor-lookup', 'Auth\SponsorLookupController::lookup');
$routes->get('logout', 'Auth\LoginController::logout');
$routes->post('logout', 'Auth\LoginController::logout');

// Phase 10D isolated admin support-view (path-scoped cookie; Shield admin session unchanged)
$routes->group('support-view', ['filter' => 'supportView'], static function (RouteCollection $routes): void {
    $routes->post('start', 'SupportView\SessionController::start');
    $routes->post('end', 'SupportView\SessionController::end');
    $routes->get('ended', 'SupportView\SessionController::ended');
    $routes->get('dashboard', 'SupportView\PortalController::dashboard');
    $routes->get('profile', 'SupportView\PortalController::profile');
    $routes->post('profile', 'SupportView\PortalController::blocked');
    $routes->get('purchases', 'SupportView\PortalController::purchases');
    $routes->get('purchases/(:segment)', 'SupportView\PortalController::purchaseShow/$1');
    $routes->post('purchases', 'SupportView\PortalController::blocked');
    $routes->get('deposits', 'SupportView\PortalController::deposits');
    $routes->post('deposits', 'SupportView\PortalController::blocked');
    $routes->get('wallet', 'SupportView\PortalController::wallet');
    $routes->get('locking', 'SupportView\PortalController::locking');
    $routes->post('locking', 'SupportView\PortalController::blocked');
    $routes->get('coin-selling', 'SupportView\PortalController::blocked');
    $routes->post('coin-selling', 'SupportView\PortalController::blocked');
    $routes->get('referrals', 'SupportView\PortalController::referrals');
    $routes->get('genealogy', 'SupportView\PortalController::genealogy');
    $routes->get('mining', 'SupportView\PortalController::mining');
    $routes->get('mining/history', 'SupportView\PortalController::miningHistory');
    $routes->get('commissions', 'SupportView\PortalController::commissions');
    $routes->get('support', 'SupportView\PortalController::support');
    $routes->post('support', 'SupportView\PortalController::blocked');
    $routes->get('buy-coins', 'SupportView\PortalController::blocked');
    $routes->post('buy-coins', 'SupportView\PortalController::blocked');
    $routes->get('withdrawals', 'SupportView\PortalController::blocked');
    $routes->post('withdrawals', 'SupportView\PortalController::blocked');
    $routes->get('security', 'SupportView\PortalController::blocked');
    $routes->post('security/password', 'SupportView\PortalController::blocked');
});

// Pending email-change confirmation (single-use token; login identity switches only after verify)
$routes->get('confirm-email-change/(:segment)', 'ConfirmEmailChangeController::confirm/$1');

// Authorized media (auth checked inside controllers)
$routes->get('media/payment-qr/(:segment)', 'Media\PaymentQrController::show/$1');
$routes->get('media/payment-logo/(:segment)', 'Admin\PaymentDetailsController::logo/$1');
$routes->get('media/platform-logo', 'Media\PlatformLogoController::show');

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
    // Phase 10D — POST only; opens new-tab support view grant
    $routes->post('members/(:segment)/impersonate', 'Admin\ImpersonationController::grant/$1');
    $routes->get('members/(:segment)', 'Admin\MemberController::show/$1');

    // Phase 9C support tickets (under Members context)
    $routes->get('support', 'Admin\SupportController::index');
    $routes->get('support/(:segment)', 'Admin\SupportController::show/$1');
    $routes->post('support/(:segment)/reply', 'Admin\SupportController::reply/$1');
    $routes->post('support/(:segment)/status', 'Admin\SupportController::status/$1');

    // Coin inventory & pricing (Step 2)
    $routes->get('coins', 'Admin\CoinController::index');
    $routes->get('coins/inventory', 'Admin\CoinController::inventory');
    $routes->get('coins/inventory/add', 'Admin\CoinController::addForm');
    $routes->post('coins/inventory/add', 'Admin\CoinController::addStore');
    $routes->get('coins/inventory/history', 'Admin\CoinController::inventoryHistory');
    $routes->get('coins/price', 'Admin\CoinController::price');
    $routes->post('coins/price', 'Admin\CoinController::priceUpdate');
    $routes->get('coins/price/history', 'Admin\CoinController::priceHistory');
    // Phase 11E coin selling (execution OFF)
    $routes->get('coins/selling', 'Admin\CoinSellingController::index');
    $routes->get('coins/selling/(:segment)', 'Admin\CoinSellingController::show/$1');
    $routes->post('coins/selling/(:segment)/approve', 'Admin\CoinSellingController::approve/$1');
    $routes->post('coins/selling/(:segment)/reject', 'Admin\CoinSellingController::reject/$1');
    $routes->get('coming-soon/coin-selling', static function () {
        return redirect()->to(site_url('admin/coins/selling'));
    });

    // Unified Payment Approvals (purchase + deposit workflows)
    $routes->get('payment-approvals', 'Admin\PaymentApprovalsController::index');
    $routes->get('payment-approvals/deposit/(:segment)', 'Admin\PaymentApprovalsController::depositShow/$1');
    $routes->get('payment-approvals/(:segment)', 'Admin\PaymentApprovalsController::show/$1');
    $routes->get('payment-monitoring', 'Admin\PaymentMonitoringController::index');

    // Coin purchase requests (Step 3) — GET redirects to Payment Approvals; POST preserved
    $routes->get('purchases', static function () {
        $status = trim((string) service('request')->getGet('status'));
        $q      = trim((string) service('request')->getGet('q'));
        $tab = match ($status) {
            'pending'  => 'pending',
            'approved' => 'completed',
            'rejected' => 'rejected',
            default    => 'all',
        };
        $url = site_url('admin/payment-approvals?tab=' . rawurlencode($tab));
        if ($q !== '') {
            $url .= '&q=' . rawurlencode($q);
        }

        return redirect()->to($url);
    });
    $routes->get('purchases/(:segment)', static function (string $ref) {
        return redirect()->to(site_url('admin/payment-approvals/' . rawurlencode($ref)));
    });
    $routes->post('purchases/(:segment)/approve', 'Admin\PurchaseController::approve/$1');
    $routes->post('purchases/(:segment)/reject', 'Admin\PurchaseController::reject/$1');

    // Simplified BEP20 Payment Details (replaces Payment Settings + Payment Networks UI)
    $routes->get('payment-details', 'Admin\PaymentDetailsController::index');
    $routes->post('payment-details', 'Admin\PaymentDetailsController::save');
    $routes->post('payment-details/approve/(:segment)', 'Admin\PaymentDetailsController::approveDestination/$1');
    $routes->post('payment-details/reject/(:segment)', 'Admin\PaymentDetailsController::rejectDestination/$1');

    // Legacy redirects — keep bookmarks working
    $routes->get('payment-settings', static fn () => redirect()->to(site_url('admin/payment-details')));
    $routes->get('networks', static fn () => redirect()->to(site_url('admin/payment-details')));
    $routes->get('networks/create', static fn () => redirect()->to(site_url('admin/payment-details')));
    $routes->get('networks/(:num)/edit', static fn () => redirect()->to(site_url('admin/payment-details')));

    // USDT deposits (Step 4A) — GET redirects to Payment Approvals; POST/evidence preserved
    $routes->get('deposits', static function () {
        $status = trim((string) service('request')->getGet('status'));
        $q      = trim((string) service('request')->getGet('q'));
        $tab = in_array($status, ['submitted', 'under_review'], true) ? 'pending' : 'all';
        if ($status === 'rejected') {
            $tab = 'rejected';
        }
        if ($status === 'verified') {
            $tab = 'completed';
        }
        $url = site_url('admin/payment-approvals?tab=' . rawurlencode($tab));
        if ($q !== '') {
            $url .= '&q=' . rawurlencode($q);
        }

        return redirect()->to($url);
    });
    $routes->get('deposits/(:segment)/evidence', 'Admin\DepositController::evidence/$1');
    $routes->post('deposits/(:segment)/under-review', 'Admin\DepositController::underReview/$1');
    $routes->post('deposits/(:segment)/reject', 'Admin\DepositController::reject/$1');
    $routes->post('deposits/(:segment)/notes', 'Admin\DepositController::notes/$1');
    $routes->post('deposits/(:segment)/attempt-verify', 'Admin\DepositController::attemptVerify/$1');
    $routes->post('deposits/(:segment)/manual-verify', 'Admin\DepositController::manualVerifyBlocked/$1');
    $routes->post('deposits/(:segment)/approve-payment', 'Admin\DepositController::approvePayment/$1');
    $routes->get('deposits/(:segment)', static function (string $ref) {
        $deposit = service('usdtDepositService')->findByRef($ref);
        if ($deposit !== null && ! empty($deposit['linked_purchase_ref'])) {
            return redirect()->to(site_url('admin/payment-approvals/' . rawurlencode((string) $deposit['linked_purchase_ref'])));
        }
        $match = $deposit !== null ? service('usdtDepositService')->findMatchingPendingPurchase($deposit) : null;
        if ($match !== null && ! empty($match['purchase_ref'])) {
            return redirect()->to(site_url('admin/payment-approvals/' . rawurlencode((string) $match['purchase_ref'])));
        }

        return redirect()->to(site_url('admin/payment-approvals/deposit/' . rawurlencode($ref)));
    });

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
    $routes->get('wallets/(:segment)/transactions/export', 'Admin\WalletController::export/$1');
    $routes->get('wallets/(:segment)/transactions', 'Admin\WalletController::transactions/$1');
    $routes->get('wallets/(:segment)/transactions/(:segment)', 'Admin\WalletController::transaction/$1/$2');
    $routes->get('wallets/(:segment)', 'Admin\WalletController::show/$1');

    // Phase 7A referral & genealogy (admin read-only)
    $routes->get('referrals', 'Admin\ReferralController::index');
    $routes->get('genealogy', 'Admin\ReferralController::genealogy');
    $routes->get('referrals/(:segment)', 'Admin\ReferralController::show/$1');

    // Phase 11A mining panel (dry-run / rates / wallet monitor — posting OFF)
    $routes->get('mining', 'Admin\MiningController::index');
    $routes->get('mining/rates', 'Admin\MiningController::rates');
    $routes->get('mining/rates/create', 'Admin\MiningController::rateCreate');
    $routes->post('mining/rates', 'Admin\MiningController::rateStore');
    $routes->get('mining/rates/(:segment)', 'Admin\MiningController::rateShow/$1');
    $routes->post('mining/rates/(:segment)/approve', 'Admin\MiningController::rateApprove/$1');
    $routes->post('mining/rates/(:segment)/disable', 'Admin\MiningController::rateDisable/$1');
    $routes->post('mining/simulate', 'Admin\MiningController::simulate');
    $routes->get('mining/wallets/(:num)', 'Admin\MiningController::walletShow/$1');

    // Phase 7C level commission configuration & simulation (non-payable)
    $routes->get('commissions', 'Admin\CommissionController::index');
    $routes->get('commissions/plans', 'Admin\CommissionController::plans');
    $routes->get('commissions/plans/create', 'Admin\CommissionController::planCreate');
    $routes->post('commissions/plans', 'Admin\CommissionController::planStore');
    $routes->get('commissions/plans/(:segment)', 'Admin\CommissionController::planShow/$1');
    $routes->post('commissions/plans/(:segment)/update', 'Admin\CommissionController::planUpdate/$1');
    $routes->post('commissions/plans/(:segment)/approve', 'Admin\CommissionController::planApprove/$1');
    $routes->post('commissions/plans/(:segment)/disable', 'Admin\CommissionController::planDisable/$1');
    $routes->post('commissions/simulate', 'Admin\CommissionController::simulate');
    $routes->get('commissions/history', 'Admin\CommissionController::history');
    $routes->get('commissions/history/export', 'Admin\CommissionController::export');

    // Phase 7D commission wallet / posting proposals / withdrawals (activation OFF)
    $routes->get('commission-wallet', 'Admin\CommissionWalletController::index');
    $routes->get('commission-wallet/reconcile', 'Admin\CommissionWalletController::reconcile');
    $routes->get('commission-postings', 'Admin\CommissionPostingController::index');
    $routes->get('commission-postings/(:segment)', 'Admin\CommissionPostingController::show/$1');
    $routes->post('commission-postings', 'Admin\CommissionPostingController::store');
    $routes->post('commission-postings/(:segment)/submit', 'Admin\CommissionPostingController::submit/$1');
    $routes->post('commission-postings/(:segment)/approve', 'Admin\CommissionPostingController::approve/$1');
    $routes->post('commission-postings/(:segment)/reject', 'Admin\CommissionPostingController::reject/$1');
    // Phase 11D / Phase 1 withdrawal management (mutations gated; emergency unlock remains OFF until deployer enables)
    $routes->get('withdrawals', 'Admin\WithdrawalController::index');
    $routes->get('withdrawal-settings', 'Admin\WithdrawalSettingsController::index');
    $routes->post('withdrawal-settings', 'Admin\WithdrawalSettingsController::update');
    $routes->get('withdrawals/(:segment)', 'Admin\WithdrawalController::show/$1');
    $routes->post('withdrawals/(:segment)/approve', 'Admin\WithdrawalController::approve/$1');
    $routes->post('withdrawals/(:segment)/reject', 'Admin\WithdrawalController::reject/$1');
    $routes->post('withdrawals/(:segment)/record-payout', 'Admin\WithdrawalController::recordPayout/$1');
    $routes->post('withdrawals/(:segment)/verify-payout', 'Admin\WithdrawalController::verifyPayout/$1');

    // Phase 9B reports & analytics
    $routes->get('reports', 'Admin\ReportsController::index');
    $routes->get('reports/export', 'Admin\ReportsController::export');
    $routes->get('coming-soon/reports', static function () {
        return redirect()->to(site_url('admin/reports'));
    });

    // Financial Automation Control Center (daily ON/OFF operations; flips DB-backed switches only)
    $routes->get('financial-automation', 'Admin\FinancialAutomationController::index');
    $routes->post('financial-automation/start-all', 'Admin\FinancialAutomationController::startAll');
    $routes->post('financial-automation/stop-all', 'Admin\FinancialAutomationController::stopAll');
    $routes->post('financial-automation/(:segment)/on', 'Admin\FinancialAutomationController::on/$1');
    $routes->post('financial-automation/(:segment)/off', 'Admin\FinancialAutomationController::off/$1');

    // Phase 12E financial policies hub (advanced configuration / approval only — no kill-switch enablement)
    $routes->get('financial-policies', 'Admin\FinancialPoliciesController::index');
    $routes->get('financial-policies/create', 'Admin\FinancialPoliciesController::create');
    $routes->get('financial-policies/preview', 'Admin\FinancialPoliciesController::preview');
    $routes->get('financial-policies/mining-worksheet', 'Admin\FinancialPoliciesController::miningWorksheet');
    $routes->post('financial-policies', 'Admin\FinancialPoliciesController::store');
    $routes->get('financial-policies/(:segment)', 'Admin\FinancialPoliciesController::show/$1');
    $routes->post('financial-policies/(:segment)/submit', 'Admin\FinancialPoliciesController::submit/$1');
    $routes->post('financial-policies/(:segment)/revise', 'Admin\FinancialPoliciesController::revise/$1');
    $routes->post('financial-policies/(:segment)/approve', 'Admin\FinancialPoliciesController::approve/$1');
    $routes->post('financial-policies/(:segment)/activate', 'Admin\FinancialPoliciesController::activate/$1');
    $routes->post('financial-policies/(:segment)/reject', 'Admin\FinancialPoliciesController::reject/$1');

    // Single-admin financial governance (review → separate confirmation; only when platform.financialGovernanceMode = single_admin)
    $routes->get('financial-governance/(:segment)/(:segment)/(:segment)', 'Admin\FinancialGovernanceController::show/$1/$2/$3');
    $routes->post('financial-governance/(:segment)/(:segment)/(:segment)/review', 'Admin\FinancialGovernanceController::review/$1/$2/$3');
    $routes->post('financial-governance/(:segment)/(:segment)/(:segment)/confirm', 'Admin\FinancialGovernanceController::confirm/$1/$2/$3');

    // Phase 9C platform settings
    $routes->get('settings', 'Admin\SettingsController::index');
    $routes->post('settings', 'Admin\SettingsController::update');
    $routes->post('settings/logo', 'Admin\SettingsController::uploadLogo');
    $routes->get('coming-soon/settings', static function () {
        return redirect()->to(site_url('admin/settings'));
    });

    $routes->get('coming-soon/(:segment)', 'Admin\PlaceholderController::show/$1');
});

// Member panel
$routes->group('member', ['filter' => 'member'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Member\DashboardController::index');
    $routes->get('profile', 'Member\ProfileController::index');
    $routes->post('profile', 'Member\ProfileController::update');
    $routes->post('profile/cancel-email-change', 'Member\ProfileController::cancelPendingEmail');
    $routes->get('security', 'Member\SecurityController::index');
    $routes->post('security/password', 'Member\SecurityController::updatePassword');

    // Phase 8 guided buy-coins wizard
    $routes->get('buy-coins', 'Member\BuyCoinsController::index');
    $routes->post('buy-coins', 'Member\BuyCoinsController::store');
    $routes->get('buy-coins/pay/(:segment)', 'Member\BuyCoinsController::pay/$1');
    $routes->post('buy-coins/pay/(:segment)', 'Member\BuyCoinsController::submitPayment/$1');
    $routes->get('buy-coins/status/(:segment)', 'Member\BuyCoinsController::status/$1');

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

    // Phase 3 daily principal release (schedule preview; posting OFF)
    $routes->get('principal-release', 'Member\PrincipalReleaseController::index');

    // Phase 11E coin selling (execution OFF)
    $routes->get('coin-selling', 'Member\CoinSellingController::index');
    $routes->post('coin-selling/preview', 'Member\CoinSellingController::preview');
    $routes->post('coin-selling', 'Member\CoinSellingController::store');
    $routes->get('coming-soon/coin-selling', static function () {
        return redirect()->to(site_url('member/coin-selling'));
    });

    // Phase 6 internal coin wallet
    $routes->get('wallet', 'Member\WalletController::index');
    $routes->get('wallet/transactions', 'Member\WalletController::transactions');
    $routes->get('wallet/transactions/export', 'Member\WalletController::export');
    $routes->get('wallet/transactions/(:segment)', 'Member\WalletController::show/$1');

    // Phase 7A referrals & genealogy + Phase 2 business volume (read-only)
    $routes->get('referrals', 'Member\ReferralController::index');
    $routes->get('referrals/business', 'Member\ReferralController::business');
    $routes->get('referrals/business/(:segment)', 'Member\ReferralController::businessMember/$1');
    $routes->get('genealogy', 'Member\ReferralController::genealogy');
    $routes->get('genealogy/children', 'Member\ReferralController::children');

    // Mining Wallet (actual posted credits, read-only) + Mining Earnings History
    $routes->get('mining', 'Member\MiningController::index');
    $routes->get('mining/history', 'Member\MiningController::history');

    // Phase 11C mining wallet P2P (execution kill-switched OFF)
    $routes->get('p2p', 'Member\P2pController::index');
    $routes->post('p2p/lookup', 'Member\P2pController::lookup');
    $routes->post('p2p/preview', 'Member\P2pController::preview');
    $routes->post('p2p/transfer', 'Member\P2pController::transfer');
    $routes->get('coming-soon/p2p', static function () {
        return redirect()->to(site_url('member/p2p'));
    });

    // Phase 7C commission simulation (read-only preview)
    $routes->get('commissions', 'Member\CommissionController::index');
    $routes->get('commissions/history', 'Member\CommissionController::history');

    // Phase 7D/11D/Phase1 commission wallet & withdrawals (activation OFF until Admin + deployer gates)
    $routes->get('commission-wallet', 'Member\CommissionWalletController::index');
    $routes->get('withdrawals', 'Member\WithdrawalController::index');
    $routes->get('withdrawals/create', 'Member\WithdrawalController::create');
    $routes->post('withdrawals/preview', 'Member\WithdrawalController::preview');
    $routes->post('withdrawals', 'Member\WithdrawalController::store');
    $routes->get('withdrawals/(:segment)', 'Member\WithdrawalController::show/$1');

    // Phase 9C member support
    $routes->get('support', 'Member\SupportController::index');
    $routes->get('support/create', 'Member\SupportController::create');
    $routes->post('support', 'Member\SupportController::store');
    $routes->get('support/(:segment)', 'Member\SupportController::show/$1');
    $routes->post('support/(:segment)/reply', 'Member\SupportController::reply/$1');
    $routes->get('coming-soon/support', static function () {
        return redirect()->to(site_url('member/support'));
    });

    $routes->get('coming-soon/(:segment)', 'Member\PlaceholderController::show/$1');
});
