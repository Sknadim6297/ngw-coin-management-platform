<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use InvalidArgumentException;

/**
 * Phase 6: member internal coin wallet (read-only reporting).
 */
class WalletController extends BaseController
{
    public function index()
    {
        $userId = (int) auth()->id();

        return view('member/wallet/index', [
            'title'   => 'Coin Wallet',
            'wallet'  => service('memberWalletService')->overview($userId),
            'profile' => model(MemberProfileModel::class)->findByUserId($userId),
        ]);
    }

    public function transactions()
    {
        $userId = (int) auth()->id();

        try {
            $history = service('memberWalletService')->history($userId, [
                'type'      => $this->request->getGet('type'),
                'date_from' => $this->request->getGet('date_from'),
                'date_to'   => $this->request->getGet('date_to'),
                'per_page'  => $this->request->getGet('per_page'),
            ]);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/wallet/transactions'))
                ->with('error', $e->getMessage());
        }

        return view('member/wallet/transactions', [
            'title'   => 'Wallet Transactions',
            'rows'    => $history['rows'],
            'pager'   => $history['pager'],
            'filters' => $history['filters'],
            'summary' => service('memberCoinOwnershipService')->summaryForUser($userId),
            'profile' => model(MemberProfileModel::class)->findByUserId($userId),
        ]);
    }

    public function show(string $transactionRef)
    {
        $userId = (int) auth()->id();

        try {
            $txn = service('memberWalletService')->findOwnedTransaction($userId, $transactionRef);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/wallet/transactions'))
                ->with('error', $e->getMessage());
        }

        return view('member/wallet/show', [
            'title'   => 'Wallet Transaction',
            'txn'     => $txn,
            'profile' => model(MemberProfileModel::class)->findByUserId($userId),
        ]);
    }

    public function export()
    {
        $userId = (int) auth()->id();

        try {
            $filters = [
                'type'      => $this->request->getGet('type'),
                'date_from' => $this->request->getGet('date_from'),
                'date_to'   => $this->request->getGet('date_to'),
            ];
            $rows = service('memberWalletService')->exportRows($userId, $filters);
            $csv  = service('memberWalletService')->buildCsv($rows);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/wallet/transactions'))
                ->with('error', $e->getMessage());
        }

        $filename = 'wallet-transactions-' . date('Ymd-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($csv);
    }
}
