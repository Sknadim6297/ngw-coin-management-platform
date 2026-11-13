<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

/**
 * Phase 6: admin read-only member wallet lookup and reconciliation.
 */
class WalletController extends BaseController
{
    public function index()
    {
        $memberId = strtoupper(trim((string) $this->request->getGet('member_id')));
        $result   = null;

        if ($memberId !== '') {
            $result = service('memberWalletService')->lookupByMemberId($memberId);
            if ($result === null) {
                return view('admin/wallet/index', [
                    'title'    => 'Wallet Management',
                    'memberId' => $memberId,
                    'result'   => null,
                    'error'    => 'No member found for that Member ID.',
                ]);
            }
        }

        return view('admin/wallet/index', [
            'title'    => 'Wallet Management',
            'memberId' => $memberId,
            'result'   => $result,
            'error'    => null,
        ]);
    }

    public function show(string $memberId)
    {
        $result = service('memberWalletService')->lookupByMemberId($memberId);
        if ($result === null) {
            return redirect()->to(site_url('admin/wallets'))
                ->with('error', 'Member wallet not found.');
        }

        $history = service('memberWalletService')->history((int) $result['profile']->user_id, [
            'type'      => $this->request->getGet('type'),
            'date_from' => $this->request->getGet('date_from'),
            'date_to'   => $this->request->getGet('date_to'),
            'per_page'  => 25,
        ]);

        return view('admin/wallet/show', [
            'title'   => 'Member Wallet',
            'result'  => $result,
            'rows'    => $history['rows'],
            'pager'   => $history['pager'],
            'filters' => $history['filters'],
        ]);
    }

    public function transaction(string $memberId, string $transactionRef)
    {
        $result = service('memberWalletService')->lookupByMemberId($memberId);
        if ($result === null) {
            return redirect()->to(site_url('admin/wallets'))
                ->with('error', 'Member wallet not found.');
        }

        try {
            $txn = service('memberWalletService')->findOwnedTransaction(
                (int) $result['profile']->user_id,
                $transactionRef
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/wallets/' . rawurlencode($memberId)))
                ->with('error', $e->getMessage());
        }

        return view('admin/wallet/transaction', [
            'title'  => 'Wallet Audit Detail',
            'result' => $result,
            'txn'    => $txn,
        ]);
    }

    public function reconcile()
    {
        $memberId = strtoupper(trim((string) ($this->request->getGet('member_id') ?: $this->request->getPost('member_id'))));
        $report   = service('walletReconciliationService')->reconcile(
            null,
            $memberId !== '' ? $memberId : null
        );

        return view('admin/wallet/reconcile', [
            'title'    => 'Wallet Reconciliation',
            'report'   => $report,
            'memberId' => $memberId,
        ]);
    }
}
