<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;
use RuntimeException;

class CommissionPostingController extends BaseController
{
    public function index()
    {
        if (! $this->canView()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $rows = db_connect()->table('commission_posting_proposals')
            ->orderBy('id', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();

        return view('admin/commission_postings/index', [
            'title'      => 'Commission Posting Proposals',
            'rows'       => $rows,
            'canPropose' => $this->can('commission.posting.propose'),
            'canApprove' => $this->can('commission.posting.approve'),
            'postingOn'  => service('commissionWalletService')->postingEnabled(),
        ]);
    }

    public function show(string $ref)
    {
        if (! $this->canView()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $row = service('commissionPostingProposalService')->findByRef($ref);
        if ($row === null) {
            return redirect()->to(site_url('admin/commission-postings'))->with('error', 'Proposal not found.');
        }

        return view('admin/commission_postings/show', [
            'title'      => 'Posting Proposal',
            'row'        => $row,
            'canPropose' => $this->can('commission.posting.propose'),
            'canApprove' => $this->can('commission.posting.approve'),
            'postingOn'  => service('commissionWalletService')->postingEnabled(),
        ]);
    }

    public function store()
    {
        if (! $this->can('commission.posting.propose')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            $row = service('commissionPostingProposalService')->createDraft([
                'purchase_ref' => (string) $this->request->getPost('purchase_ref'),
                'level'        => (int) $this->request->getPost('level'),
                'plan_ref'     => (string) $this->request->getPost('plan_ref'),
            ], (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/commission-postings/' . rawurlencode($row['proposal_ref'])))
            ->with('success', 'Draft proposal created. Approval will not post while kill-switch is OFF.');
    }

    public function submit(string $ref)
    {
        if (! $this->can('commission.posting.propose')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            service('commissionPostingProposalService')->submit($ref, (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/commission-postings/' . rawurlencode($ref)))
            ->with('success', 'Proposal submitted for dual-control approval.');
    }

    public function approve(string $ref)
    {
        if (! $this->can('commission.posting.approve')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            service('commissionPostingProposalService')->approve($ref, (int) auth()->id());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/commission-postings/' . rawurlencode($ref)))
            ->with('success', 'Proposal approved as non-executable. No wallet credit posted.');
    }

    public function reject(string $ref)
    {
        if (! $this->can('commission.posting.approve')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            service('commissionPostingProposalService')->reject(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('reason')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/commission-postings/' . rawurlencode($ref)))
            ->with('success', 'Proposal rejected.');
    }

    private function canView(): bool
    {
        return $this->can('commission.wallet.view')
            || $this->can('commission.posting.propose')
            || $this->can('commission.posting.approve');
    }

    private function can(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }
}
