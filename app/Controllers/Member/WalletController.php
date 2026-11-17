<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use InvalidArgumentException;

/**
 * Phase 6/9A: member internal coin wallet with consolidated tabs.
 */
class WalletController extends BaseController
{
    public function index()
    {
        $userId = (int) auth()->id();
        $tab    = trim((string) $this->request->getGet('tab'));
        if (! in_array($tab, ['overview', 'transactions', 'locking'], true)) {
            $tab = 'overview';
        }

        $payload = [
            'title'   => 'My Wallet',
            'tab'     => $tab,
            'wallet'  => service('memberWalletService')->overview($userId),
            'profile' => model(MemberProfileModel::class)->findByUserId($userId),
        ];

        if ($tab === 'transactions') {
            try {
                $history = service('memberWalletService')->history($userId, $this->filterInput());
            } catch (InvalidArgumentException $e) {
                return redirect()->to(site_url('member/wallet?tab=transactions'))
                    ->with('error', $e->getMessage());
            }
            $payload['rows']    = $history['rows'];
            $payload['pager']   = $history['pager'];
            $payload['filters'] = $history['filters'];
            $payload['summary'] = service('memberCoinOwnershipService')->summaryForUser($userId);
        } elseif ($tab === 'locking') {
            $status = trim((string) $this->request->getGet('status'));
            $result = service('coinLockingService')->searchLocks(
                $status !== '' ? $status : null,
                $userId
            );
            $payload['lockRows']  = $result['rows'];
            $payload['pager']     = $result['pager'];
            $payload['lockStatus']= $status;
        }

        return view('member/wallet/index', $payload);
    }

    public function transactions()
    {
        $q = http_build_query(array_filter([
            'tab'       => 'transactions',
            'type'      => $this->request->getGet('type'),
            'date_from' => $this->request->getGet('date_from'),
            'date_to'   => $this->request->getGet('date_to'),
            'ref'       => $this->request->getGet('ref'),
            'sort'      => $this->request->getGet('sort'),
            'dir'       => $this->request->getGet('dir'),
            'per_page'  => $this->request->getGet('per_page'),
        ], static fn ($v) => $v !== null && $v !== ''));

        return redirect()->to(site_url('member/wallet' . ($q !== '' ? '?' . $q : '?tab=transactions')));
    }

    public function show(string $transactionRef)
    {
        $userId = (int) auth()->id();

        try {
            $txn = service('memberWalletService')->findOwnedTransaction($userId, $transactionRef);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/wallet?tab=transactions'))
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
            $filters = $this->filterInput();
            $rows    = service('memberWalletService')->exportRows($userId, $filters);
            $csv     = service('memberWalletService')->buildCsv($rows);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/wallet?tab=transactions'))
                ->with('error', $e->getMessage());
        }

        $filename = 'wallet-transactions-' . date('Ymd-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($csv);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterInput(): array
    {
        return [
            'type'      => $this->request->getGet('type'),
            'date_from' => $this->request->getGet('date_from'),
            'date_to'   => $this->request->getGet('date_to'),
            'ref'       => $this->request->getGet('ref'),
            'sort'      => $this->request->getGet('sort'),
            'dir'       => $this->request->getGet('dir'),
            'per_page'  => $this->request->getGet('per_page'),
        ];
    }
}
