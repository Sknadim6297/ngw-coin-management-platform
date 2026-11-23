<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use InvalidArgumentException;

/**
 * Member KYC submission and status. Members cannot approve their own KYC.
 */
class KycController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        return view('member/kyc/index', [
            'title'   => 'KYC Verification',
            'profile' => $profile,
            'kyc'     => service('memberKycService')->statusForMember($userId),
            'types'   => \App\Services\MemberKycService::DOCUMENT_TYPES,
        ]);
    }

    public function submit()
    {
        $userId = (int) auth()->id();
        try {
            service('memberKycService')->submit($userId, [
                'full_name'       => (string) $this->request->getPost('full_name'),
                'document_type'   => (string) $this->request->getPost('document_type'),
                'document_number' => (string) $this->request->getPost('document_number'),
                'member_notes'    => (string) $this->request->getPost('member_notes'),
            ]);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'KYC submit failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to submit KYC.');
        }

        return redirect()->to(site_url('member/kyc'))
            ->with('success', 'KYC submitted for Admin review.');
    }
}
