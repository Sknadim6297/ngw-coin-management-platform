<?php

namespace Config;

use App\Libraries\Blockchain\DepositVerificationProviderInterface;
use App\Libraries\Blockchain\EvmUsdtVerificationProvider;
use App\Libraries\Blockchain\JsonRpcClient;
use App\Libraries\Blockchain\NotConfiguredDepositVerificationProvider;
use App\Services\AuditLogger;
use App\Services\CoinInventoryService;
use App\Services\CoinLedgerService;
use App\Services\CoinPriceService;
use App\Services\CoinPurchaseService;
use App\Services\EvidenceUploadService;
use App\Services\MemberAuthService;
use App\Services\MemberCoinOwnershipService;
use App\Services\MemberIdGenerator;
use App\Services\PasswordResetService;
use App\Services\RegistrationService;
use App\Services\SponsorValidator;
use App\Services\DeploymentPreflightService;
use App\Services\CoinLockingService;
use App\Services\MemberWalletService;
use App\Services\WalletReconciliationService;
use App\Services\AdminDashboardService;
use App\Services\MemberDashboardService;
use App\Services\ReferralTeamService;
use App\Services\GenealogyIntegrityService;
use App\Services\MiningEligibilityService;
use App\Services\MiningRateService;
use App\Services\MiningSimulationService;
use App\Services\MiningWalletService;
use App\Services\MiningDailyEngineService;
use App\Services\CoinPlanAuditService;
use App\Services\ImpersonationService;
use App\Services\CommissionPlanService;
use App\Services\CommissionGenealogyService;
use App\Services\CommissionEligibilityService;
use App\Services\CommissionSimulationService;
use App\Services\PurchaseSponsorSnapshotService;
use App\Services\CommissionWalletService;
use App\Services\CommissionPostingProposalService;
use App\Services\CommissionWithdrawalService;
use App\Services\CommissionWalletReconciliationService;
use App\Services\MemberActivationService;
use App\Services\MemberProfileService;
use App\Services\AdminReportsService;
use App\Services\PlatformSettingsService;
use App\Services\SupportTicketService;
use App\Services\PaymentDetailsService;
use App\Services\PaymentDestinationChangeService;
use App\Services\PaymentQrService;
use App\Services\FinanceDashboardService;
use App\Services\FinancialAlertService;
use App\Services\FinancialNotificationService;
use App\Services\FinancialRemediationService;
use App\Services\PaymentApprovalsService;
use App\Services\PaymentAuditService;
use App\Services\PaymentHealthService;
use App\Services\PaymentIntegrityMonitorService;
use App\Services\PaymentReconciliationService;
use App\Services\UsdtDepositService;
use App\Services\UsdtPaymentAllocationService;
use App\Services\UsdtPaymentNetworkService;
use App\Services\VerificationRetryService;
use CodeIgniter\Config\BaseService;

class Services extends BaseService
{
    public static function memberIdGenerator(bool $getShared = true): MemberIdGenerator
    {
        if ($getShared) {
            return static::getSharedInstance('memberIdGenerator');
        }

        return new MemberIdGenerator();
    }

    public static function sponsorValidator(bool $getShared = true): SponsorValidator
    {
        if ($getShared) {
            return static::getSharedInstance('sponsorValidator');
        }

        return new SponsorValidator();
    }

    public static function registrationService(bool $getShared = true): RegistrationService
    {
        if ($getShared) {
            return static::getSharedInstance('registrationService');
        }

        return new RegistrationService();
    }

    public static function passwordResetService(bool $getShared = true): PasswordResetService
    {
        if ($getShared) {
            return static::getSharedInstance('passwordResetService');
        }

        return new PasswordResetService();
    }

    public static function memberAuthService(bool $getShared = true): MemberAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('memberAuthService');
        }

        return new MemberAuthService();
    }

    public static function auditLogger(bool $getShared = true): AuditLogger
    {
        if ($getShared) {
            return static::getSharedInstance('auditLogger');
        }

        return new AuditLogger();
    }

    public static function coinInventoryService(bool $getShared = true): CoinInventoryService
    {
        if ($getShared) {
            return static::getSharedInstance('coinInventoryService');
        }

        return new CoinInventoryService();
    }

    public static function coinPriceService(bool $getShared = true): CoinPriceService
    {
        if ($getShared) {
            return static::getSharedInstance('coinPriceService');
        }

        return new CoinPriceService();
    }

    public static function coinLedgerService(bool $getShared = true): CoinLedgerService
    {
        if ($getShared) {
            return static::getSharedInstance('coinLedgerService');
        }

        return new CoinLedgerService();
    }

    public static function memberCoinOwnershipService(bool $getShared = true): MemberCoinOwnershipService
    {
        if ($getShared) {
            return static::getSharedInstance('memberCoinOwnershipService');
        }

        return new MemberCoinOwnershipService();
    }

    public static function coinPurchaseService(bool $getShared = true): CoinPurchaseService
    {
        if ($getShared) {
            return static::getSharedInstance('coinPurchaseService');
        }

        return new CoinPurchaseService();
    }

    public static function evidenceUploadService(bool $getShared = true): EvidenceUploadService
    {
        if ($getShared) {
            return static::getSharedInstance('evidenceUploadService');
        }

        return new EvidenceUploadService();
    }

    public static function usdtPaymentNetworkService(bool $getShared = true): UsdtPaymentNetworkService
    {
        if ($getShared) {
            return static::getSharedInstance('usdtPaymentNetworkService');
        }

        return new UsdtPaymentNetworkService();
    }

    public static function jsonRpcClient(bool $getShared = true): JsonRpcClient
    {
        if ($getShared) {
            return static::getSharedInstance('jsonRpcClient');
        }

        return new JsonRpcClient();
    }

    public static function depositVerificationProvider(bool $getShared = true): DepositVerificationProviderInterface
    {
        if ($getShared) {
            return static::getSharedInstance('depositVerificationProvider');
        }

        $mode = strtolower((string) (config('Blockchain')->depositVerifier ?? 'not_configured'));
        if ($mode === 'evm_erc20') {
            return new EvmUsdtVerificationProvider(static::jsonRpcClient(false));
        }

        return new NotConfiguredDepositVerificationProvider();
    }

    public static function usdtDepositService(bool $getShared = true): UsdtDepositService
    {
        if ($getShared) {
            return static::getSharedInstance('usdtDepositService');
        }

        return new UsdtDepositService(
            verifier: static::depositVerificationProvider(false)
        );
    }

    public static function usdtPaymentAllocationService(bool $getShared = true): UsdtPaymentAllocationService
    {
        if ($getShared) {
            return static::getSharedInstance('usdtPaymentAllocationService');
        }

        return new UsdtPaymentAllocationService();
    }

    public static function paymentApprovalsService(bool $getShared = true): PaymentApprovalsService
    {
        if ($getShared) {
            return static::getSharedInstance('paymentApprovalsService');
        }

        return new PaymentApprovalsService();
    }

    public static function paymentHealthService(bool $getShared = true): PaymentHealthService
    {
        if ($getShared) {
            return static::getSharedInstance('paymentHealthService');
        }

        return new PaymentHealthService();
    }

    public static function paymentReconciliationService(bool $getShared = true): PaymentReconciliationService
    {
        if ($getShared) {
            return static::getSharedInstance('paymentReconciliationService');
        }

        return new PaymentReconciliationService();
    }

    public static function verificationRetryService(bool $getShared = true): VerificationRetryService
    {
        if ($getShared) {
            return static::getSharedInstance('verificationRetryService');
        }

        return new VerificationRetryService();
    }

    public static function paymentAuditService(bool $getShared = true): PaymentAuditService
    {
        if ($getShared) {
            return static::getSharedInstance('paymentAuditService');
        }

        return new PaymentAuditService();
    }

    public static function deploymentPreflightService(bool $getShared = true): DeploymentPreflightService
    {
        if ($getShared) {
            return static::getSharedInstance('deploymentPreflightService');
        }

        return new DeploymentPreflightService();
    }

    public static function financialAlertService(bool $getShared = true): FinancialAlertService
    {
        if ($getShared) {
            return static::getSharedInstance('financialAlertService');
        }

        return new FinancialAlertService();
    }

    public static function financialRemediationService(bool $getShared = true): FinancialRemediationService
    {
        if ($getShared) {
            return static::getSharedInstance('financialRemediationService');
        }

        return new FinancialRemediationService();
    }

    public static function paymentIntegrityMonitorService(bool $getShared = true): PaymentIntegrityMonitorService
    {
        if ($getShared) {
            return static::getSharedInstance('paymentIntegrityMonitorService');
        }

        return new PaymentIntegrityMonitorService();
    }

    public static function financialNotificationService(bool $getShared = true): FinancialNotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('financialNotificationService');
        }

        return new FinancialNotificationService();
    }

    public static function financeDashboardService(bool $getShared = true): FinanceDashboardService
    {
        if ($getShared) {
            return static::getSharedInstance('financeDashboardService');
        }

        return new FinanceDashboardService();
    }

    public static function coinLockingService(bool $getShared = true): CoinLockingService
    {
        if ($getShared) {
            return static::getSharedInstance('coinLockingService');
        }

        return new CoinLockingService();
    }

    public static function memberWalletService(bool $getShared = true): MemberWalletService
    {
        if ($getShared) {
            return static::getSharedInstance('memberWalletService');
        }

        return new MemberWalletService();
    }

    public static function walletReconciliationService(bool $getShared = true): WalletReconciliationService
    {
        if ($getShared) {
            return static::getSharedInstance('walletReconciliationService');
        }

        return new WalletReconciliationService();
    }

    public static function referralTeamService(bool $getShared = true): ReferralTeamService
    {
        if ($getShared) {
            return static::getSharedInstance('referralTeamService');
        }

        return new ReferralTeamService();
    }

    public static function genealogyIntegrityService(bool $getShared = true): GenealogyIntegrityService
    {
        if ($getShared) {
            return static::getSharedInstance('genealogyIntegrityService');
        }

        return new GenealogyIntegrityService();
    }

    public static function miningRateService(bool $getShared = true): MiningRateService
    {
        if ($getShared) {
            return static::getSharedInstance('miningRateService');
        }

        return new MiningRateService();
    }

    public static function miningEligibilityService(bool $getShared = true): MiningEligibilityService
    {
        if ($getShared) {
            return static::getSharedInstance('miningEligibilityService');
        }

        return new MiningEligibilityService();
    }

    public static function miningSimulationService(bool $getShared = true): MiningSimulationService
    {
        if ($getShared) {
            return static::getSharedInstance('miningSimulationService');
        }

        return new MiningSimulationService();
    }

    public static function coinPlanAuditService(bool $getShared = true): CoinPlanAuditService
    {
        if ($getShared) {
            return static::getSharedInstance('coinPlanAuditService');
        }

        return new CoinPlanAuditService();
    }

    public static function miningWalletService(bool $getShared = true): MiningWalletService
    {
        if ($getShared) {
            return static::getSharedInstance('miningWalletService');
        }

        return new MiningWalletService();
    }

    public static function miningDailyEngineService(bool $getShared = true): MiningDailyEngineService
    {
        if ($getShared) {
            return static::getSharedInstance('miningDailyEngineService');
        }

        return new MiningDailyEngineService();
    }

    public static function impersonationService(bool $getShared = true): ImpersonationService
    {
        if ($getShared) {
            return static::getSharedInstance('impersonationService');
        }

        return new ImpersonationService();
    }

    public static function commissionPlanService(bool $getShared = true): CommissionPlanService
    {
        if ($getShared) {
            return static::getSharedInstance('commissionPlanService');
        }

        return new CommissionPlanService();
    }

    public static function commissionGenealogyService(bool $getShared = true): CommissionGenealogyService
    {
        if ($getShared) {
            return static::getSharedInstance('commissionGenealogyService');
        }

        return new CommissionGenealogyService();
    }

    public static function commissionEligibilityService(bool $getShared = true): CommissionEligibilityService
    {
        if ($getShared) {
            return static::getSharedInstance('commissionEligibilityService');
        }

        return new CommissionEligibilityService();
    }

    public static function commissionSimulationService(bool $getShared = true): CommissionSimulationService
    {
        if ($getShared) {
            return static::getSharedInstance('commissionSimulationService');
        }

        return new CommissionSimulationService();
    }

    public static function purchaseSponsorSnapshotService(bool $getShared = true): PurchaseSponsorSnapshotService
    {
        if ($getShared) {
            return static::getSharedInstance('purchaseSponsorSnapshotService');
        }

        return new PurchaseSponsorSnapshotService();
    }

    public static function commissionWalletService(bool $getShared = true): CommissionWalletService
    {
        if ($getShared) {
            return static::getSharedInstance('commissionWalletService');
        }

        return new CommissionWalletService();
    }

    public static function commissionPostingProposalService(bool $getShared = true): CommissionPostingProposalService
    {
        if ($getShared) {
            return static::getSharedInstance('commissionPostingProposalService');
        }

        return new CommissionPostingProposalService();
    }

    public static function commissionWithdrawalService(bool $getShared = true): CommissionWithdrawalService
    {
        if ($getShared) {
            return static::getSharedInstance('commissionWithdrawalService');
        }

        return new CommissionWithdrawalService();
    }

    public static function commissionWalletReconciliationService(bool $getShared = true): CommissionWalletReconciliationService
    {
        if ($getShared) {
            return static::getSharedInstance('commissionWalletReconciliationService');
        }

        return new CommissionWalletReconciliationService();
    }

    public static function memberActivationService(bool $getShared = true): MemberActivationService
    {
        if ($getShared) {
            return static::getSharedInstance('memberActivationService');
        }

        return new MemberActivationService();
    }

    public static function paymentDestinationChangeService(bool $getShared = true): PaymentDestinationChangeService
    {
        if ($getShared) {
            return static::getSharedInstance('paymentDestinationChangeService');
        }

        return new PaymentDestinationChangeService();
    }

    public static function paymentQrService(bool $getShared = true): PaymentQrService
    {
        if ($getShared) {
            return static::getSharedInstance('paymentQrService');
        }

        return new PaymentQrService();
    }

    public static function paymentDetailsService(bool $getShared = true): PaymentDetailsService
    {
        if ($getShared) {
            return static::getSharedInstance('paymentDetailsService');
        }

        return new PaymentDetailsService();
    }

    public static function adminDashboardService(bool $getShared = true): AdminDashboardService
    {
        if ($getShared) {
            return static::getSharedInstance('adminDashboardService');
        }

        return new AdminDashboardService();
    }

    public static function memberProfileService(bool $getShared = true): MemberProfileService
    {
        if ($getShared) {
            return static::getSharedInstance('memberProfileService');
        }

        return new MemberProfileService();
    }

    public static function memberDashboardService(bool $getShared = true): MemberDashboardService
    {
        if ($getShared) {
            return static::getSharedInstance('memberDashboardService');
        }

        return new MemberDashboardService();
    }

    public static function adminReportsService(bool $getShared = true): AdminReportsService
    {
        if ($getShared) {
            return static::getSharedInstance('adminReportsService');
        }

        return new AdminReportsService();
    }

    public static function platformSettingsService(bool $getShared = true): PlatformSettingsService
    {
        if ($getShared) {
            return static::getSharedInstance('platformSettingsService');
        }

        return new PlatformSettingsService();
    }

    public static function supportTicketService(bool $getShared = true): SupportTicketService
    {
        if ($getShared) {
            return static::getSharedInstance('supportTicketService');
        }

        return new SupportTicketService();
    }
}
