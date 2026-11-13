<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

/**
 * Phase 4C admin ops: health, reconciliation, audit, preflight.
 */
class PaymentOpsController extends BaseController
{
    public function health()
    {
        $probe = $this->request->getGet('probe') === '1';
        $data  = service('paymentHealthService')->dashboard(
            (int) auth()->id(),
            $probe
        );

        return view('admin/payment_ops/health', [
            'title' => 'Payment Health',
            'data'  => $data,
        ]);
    }

    public function healthProbe()
    {
        service('paymentHealthService')->probeAndStore((int) auth()->id());

        return redirect()->to(site_url('admin/payment-health'))
            ->with('success', 'RPC health probe recorded. Credentials were not stored.');
    }

    public function reconciliation()
    {
        $report = service('paymentReconciliationService')->run();

        return view('admin/payment_ops/reconciliation', [
            'title'  => 'Payment Reconciliation',
            'report' => $report,
        ]);
    }

    public function audit()
    {
        $status   = trim((string) $this->request->getGet('status'));
        $vStatus  = trim((string) $this->request->getGet('verification_status'));
        $consumption = trim((string) $this->request->getGet('consumption'));
        $integrity = trim((string) $this->request->getGet('integrity'));
        $q        = trim((string) $this->request->getGet('q'));

        $result = service('paymentAuditService')->search(
            $status !== '' ? $status : null,
            $vStatus !== '' ? $vStatus : null,
            $consumption !== '' ? $consumption : null,
            $integrity !== '' ? $integrity : null,
            $q !== '' ? $q : null
        );

        return view('admin/payment_ops/audit', [
            'title'        => 'Payment Audit Center',
            'rows'         => $result['rows'],
            'pager'        => $result['pager'],
            'status'       => $status,
            'verification_status' => $vStatus,
            'consumption'  => $consumption,
            'integrity'    => $integrity,
            'search'       => $q,
        ]);
    }

    public function auditShow(string $depositRef)
    {
        $detail = service('paymentAuditService')->detail($depositRef);
        if ($detail === null) {
            return redirect()->to(site_url('admin/payment-audit'))->with('error', 'Deposit not found.');
        }

        $network = null;
        if (! empty($detail['deposit']['network_id'])) {
            $network = service('usdtPaymentNetworkService')->find((int) $detail['deposit']['network_id']);
        }

        $retry = service('verificationRetryService')->canRetry($depositRef);

        return view('admin/payment_ops/audit_show', [
            'title'   => 'Payment Audit Detail',
            'detail'  => $detail,
            'network' => $network,
            'retry'   => $retry,
        ]);
    }

    public function retryVerification(string $depositRef)
    {
        try {
            $result = service('verificationRetryService')->retry($depositRef, (int) auth()->id());
            $msg = $result['result']->isVerified()
                ? 'Deposit verified by trusted provider.'
                : ('Verification result: ' . $result['result']->status . ' — ' . $result['result']->message);

            return redirect()->to(site_url('admin/payment-audit/' . $depositRef))
                ->with($result['result']->isVerified() ? 'success' : 'error', $msg);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function integrityRecheck(string $depositRef)
    {
        try {
            $result = service('usdtDepositService')->recheckVerifiedDepositIntegrity(
                $depositRef,
                (int) auth()->id()
            );
            $msg = $result['flagged']
                ? 'Integrity issue flagged for investigation. Ownership/inventory were NOT reversed.'
                : 'Integrity re-check passed. Deposit remains verified.';

            return redirect()->to(site_url('admin/payment-audit/' . $depositRef))
                ->with($result['flagged'] ? 'error' : 'success', $msg);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function preflight()
    {
        $report = service('deploymentPreflightService')->run();

        return view('admin/payment_ops/preflight', [
            'title'  => 'Deployment Preflight',
            'report' => $report,
        ]);
    }
}
