<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\UsdtDepositRequestModel;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

class DepositController extends BaseController
{
    public function create()
    {
        $networks = service('usdtPaymentNetworkService')->listEnabledForMembers();
        $token    = bin2hex(random_bytes(16));
        session()->setFlashdata('usdt_deposit_idempotency', $token);

        return view('member/deposits/create', [
            'title'          => 'USDT Deposit',
            'networks'       => $networks,
            'idempotencyKey' => $token,
        ]);
    }

    public function store()
    {
        $rules = config('Validation')->usdtDepositSubmit;
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $file = $this->request->getFile('evidence');

        try {
            $result = service('usdtDepositService')->submitRequest(
                (int) auth()->id(),
                (int) $this->request->getPost('network_id'),
                (string) $this->request->getPost('expected_amount_usdt'),
                (string) $this->request->getPost('tx_hash'),
                (string) $this->request->getPost('idempotency_key'),
                $file
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Deposit submit failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to submit deposit request. Please try again.');
        }

        $ref = $result['deposit']['deposit_ref'];
        $msg = ! empty($result['duplicate'])
            ? 'This submission was already received. No duplicate deposit was created.'
            : 'Deposit request submitted for review. This is not payment confirmation.';

        return redirect()->to(site_url('member/deposits/' . $ref))->with('success', $msg);
    }

    public function index()
    {
        $status  = trim((string) $this->request->getGet('status'));
        $allowed = ['draft', 'submitted', 'under_review', 'verified', 'rejected', 'cancelled', ''];
        if (! in_array($status, $allowed, true)) {
            $status = '';
        }

        $model = model(UsdtDepositRequestModel::class);
        $rows  = $model->forMember((int) auth()->id(), $status !== '' ? $status : null, 20);

        return view('member/deposits/index', [
            'title'  => 'Deposit History',
            'rows'   => $rows,
            'pager'  => $model->pager,
            'status' => $status,
        ]);
    }

    public function show(string $depositRef)
    {
        $row = service('usdtDepositService')->findOwnedByMember((int) auth()->id(), $depositRef);
        if ($row === null) {
            return redirect()->to(site_url('member/deposits'))->with('error', 'Deposit request not found.');
        }

        $network = service('usdtPaymentNetworkService')->find((int) $row['network_id']);
        $events  = service('usdtDepositService')->eventsFor($depositRef);

        return view('member/deposits/show', [
            'title'   => 'Deposit Details',
            'deposit' => $row,
            'network' => $network,
            'events'  => $events,
        ]);
    }

    public function cancel(string $depositRef)
    {
        try {
            service('usdtDepositService')->cancel((int) auth()->id(), $depositRef);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Deposit cancel failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Unable to cancel deposit request.');
        }

        return redirect()->to(site_url('member/deposits/' . $depositRef))
            ->with('success', 'Deposit request cancelled.');
    }

    public function evidence(string $depositRef): ResponseInterface
    {
        $row = service('usdtDepositService')->findOwnedByMember((int) auth()->id(), $depositRef);
        if ($row === null || empty($row['evidence_stored_name'])) {
            return redirect()->to(site_url('member/deposits'))->with('error', 'Evidence not found.');
        }

        try {
            $path = service('evidenceUploadService')->absolutePath((string) $row['evidence_stored_name']);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return $this->response
            ->setHeader('Content-Type', (string) $row['evidence_mime'])
            ->setHeader('Content-Disposition', 'inline; filename="' . basename((string) $row['evidence_original_name']) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody((string) file_get_contents($path));
    }
}
