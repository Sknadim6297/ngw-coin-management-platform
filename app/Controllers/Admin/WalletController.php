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
        $query   = trim((string) ($this->request->getGet('q') ?: $this->request->getGet('member_id')));
        $results = [];
        $error   = null;

        if ($query !== '') {
            $results = service('memberWalletService')->search($query, 25);
            if ($results === []) {
                $error = 'No members matched that search.';
            }
        }

        return view('admin/wallet/index', [
            'title'   => 'Wallet Management',
            'query'   => $query,
            'results' => $results,
            'error'   => $error,
        ]);
    }

    public function show(string $memberId)
    {
        $result = service('memberWalletService')->lookupByMemberId($memberId);
        if ($result === null) {
            return redirect()->to(site_url('admin/wallets'))
                ->with('error', 'Member wallet not found.');
        }

        return view('admin/wallet/show', [
            'title'  => 'Member Wallet',
            'result' => $result,
        ]);
    }

    public function transactions(string $memberId)
    {
        $result = service('memberWalletService')->lookupByMemberId($memberId);
        if ($result === null) {
            return redirect()->to(site_url('admin/wallets'))
                ->with('error', 'Member wallet not found.');
        }

        try {
            $history = service('memberWalletService')->history((int) $result['profile']->user_id, [
                'type'      => $this->request->getGet('type'),
                'date_from' => $this->request->getGet('date_from'),
                'date_to'   => $this->request->getGet('date_to'),
                'ref'       => $this->request->getGet('ref'),
                'sort'      => $this->request->getGet('sort'),
                'dir'       => $this->request->getGet('dir'),
                'per_page'  => $this->request->getGet('per_page') ?: 25,
            ]);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/wallets/' . rawurlencode($memberId) . '/transactions'))
                ->with('error', $e->getMessage());
        }

        return view('admin/wallet/transactions', [
            'title'   => 'Member Wallet Transactions',
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
            return redirect()->to(site_url('admin/wallets/' . rawurlencode($memberId) . '/transactions'))
                ->with('error', $e->getMessage());
        }

        return view('admin/wallet/transaction', [
            'title'  => 'Wallet Audit Detail',
            'result' => $result,
            'txn'    => $txn,
        ]);
    }

    public function export(string $memberId)
    {
        $result = service('memberWalletService')->lookupByMemberId($memberId);
        if ($result === null) {
            return redirect()->to(site_url('admin/wallets'))
                ->with('error', 'Member wallet not found.');
        }

        try {
            $filters = [
                'type'      => $this->request->getGet('type'),
                'date_from' => $this->request->getGet('date_from'),
                'date_to'   => $this->request->getGet('date_to'),
                'ref'       => $this->request->getGet('ref'),
            ];
            $rows = service('memberWalletService')->exportRows((int) $result['profile']->user_id, $filters);
            $csv  = service('memberWalletService')->buildCsv($rows);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/wallets/' . rawurlencode($memberId) . '/transactions'))
                ->with('error', $e->getMessage());
        }

        $filename = 'wallet-' . preg_replace('/[^A-Za-z0-9_-]/', '', $memberId) . '-' . date('Ymd-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($csv);
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
