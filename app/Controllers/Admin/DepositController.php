<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use App\Models\UsdtDepositRequestModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;

class DepositController extends BaseController
{
    public function index()
    {
        $status  = trim((string) $this->request->getGet('status'));
        $search  = trim((string) $this->request->getGet('q'));
        $allowed = ['draft', 'submitted', 'under_review', 'verified', 'rejected', 'cancelled', ''];
        if (! in_array($status, $allowed, true)) {
            $status = '';
        }

        $model = model(UsdtDepositRequestModel::class);
        $rows  = $model->adminList(
            $status !== '' ? $status : null,
            $search !== '' ? $search : null,
            20
        );

        return view('admin/deposits/index', [
            'title'            => 'USDT Deposits',
            'rows'             => $rows,
            'pager'            => $model->pager,
            'status'           => $status,
            'search'           => $search,
            'submittedCount'   => $model->countByStatus('submitted'),
            'underReviewCount' => $model->countByStatus('under_review'),
            'verifiedCount'    => $model->countByStatus('verified'),
            'rejectedCount'    => $model->countByStatus('rejected'),
        ]);
    }

    public function show(string $depositRef)
    {
        $row = service('usdtDepositService')->findByRef($depositRef);
        if ($row === null) {
            return redirect()->to(site_url('admin/deposits'))->with('error', 'Deposit request not found.');
        }

        $profile  = model(MemberProfileModel::class)->findByUserId((int) $row['member_user_id']);
        $network  = service('usdtPaymentNetworkService')->find((int) $row['network_id']);
        $events   = service('usdtDepositService')->eventsFor($depositRef);
        $reviewer = null;
        if (! empty($row['reviewed_by'])) {
            $user = model(UserModel::class)->find((int) $row['reviewed_by']);
            $reviewer = $user?->getEmail();
        }

        return view('admin/deposits/show', [
            'title'    => 'Deposit Review',
            'deposit'  => $row,
            'profile'  => $profile,
            'network'  => $network,
            'events'   => $events,
            'reviewer' => $reviewer,
        ]);
    }

    public function underReview(string $depositRef)
    {
        try {
            service('usdtDepositService')->markUnderReview(
                $depositRef,
                (int) auth()->id(),
                $this->nullablePost('review_notes')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Deposit under-review failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Unable to update deposit status.');
        }

        return redirect()->to(site_url('admin/deposits/' . $depositRef))
            ->with('success', 'Deposit marked under review. This is not blockchain verification.');
    }

    public function reject(string $depositRef)
    {
        $rules = config('Validation')->usdtDepositReject;
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('usdtDepositService')->reject(
                $depositRef,
                (int) auth()->id(),
                (string) $this->request->getPost('rejection_reason')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Deposit reject failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Unable to reject deposit.');
        }

        return redirect()->to(site_url('admin/deposits/' . $depositRef))
            ->with('success', 'Deposit rejected. No coins or inventory were changed.');
    }

    public function notes(string $depositRef)
    {
        $rules = config('Validation')->usdtDepositNotes;
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('usdtDepositService')->recordReviewNotes(
                $depositRef,
                (int) auth()->id(),
                (string) $this->request->getPost('review_notes')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Deposit notes failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Unable to save review notes.');
        }

        return redirect()->to(site_url('admin/deposits/' . $depositRef))
            ->with('success', 'Review notes saved. Deposit was not marked verified.');
    }

    public function attemptVerify(string $depositRef)
    {
        try {
            // Phase 4C: enforce retry limits / backoff before provider call.
            $result = service('verificationRetryService')->retry(
                $depositRef,
                (int) auth()->id()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Deposit verify attempt failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Unable to run verification provider.');
        }

        $msg = $result['result']->isVerified()
            ? 'Deposit verified by trusted provider.'
            : ('Verification unavailable: ' . $result['result']->message);

        return redirect()->to(site_url('admin/deposits/' . $depositRef))
            ->with($result['result']->isVerified() ? 'success' : 'error', $msg);
    }

    public function manualVerifyBlocked(string $depositRef)
    {
        try {
            service('usdtDepositService')->rejectManualVerificationClaim(
                $depositRef,
                (int) auth()->id()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back();
    }

    public function evidence(string $depositRef): ResponseInterface
    {
        $row = service('usdtDepositService')->findByRef($depositRef);
        if ($row === null || empty($row['evidence_stored_name'])) {
            return redirect()->to(site_url('admin/deposits'))->with('error', 'Evidence not found.');
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

    private function nullablePost(string $key): ?string
    {
        $value = trim((string) $this->request->getPost($key));

        return $value === '' ? null : $value;
    }
}
