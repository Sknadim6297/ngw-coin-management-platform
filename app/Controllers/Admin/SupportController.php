<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use App\Services\SupportTicketService;
use InvalidArgumentException;

class SupportController extends BaseController
{
    public function index()
    {
        if (! $this->canManageSupport()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage support tickets.');
        }

        $result = service('supportTicketService')->adminSearch([
            'status'    => $this->request->getGet('status'),
            'category'  => $this->request->getGet('category'),
            'q'         => $this->request->getGet('q'),
            'member_id' => $this->request->getGet('member_id'),
            'page'      => $this->request->getGet('page'),
        ]);

        return view('admin/support/index', [
            'title'      => 'Support Tickets',
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'filters'    => $result['filters'],
            'counts'     => service('supportTicketService')->statusCounts(),
            'categories' => SupportTicketService::CATEGORIES,
            'statuses'   => SupportTicketService::STATUSES,
        ]);
    }

    public function show(string $ticketRef)
    {
        if (! $this->canManageSupport()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage support tickets.');
        }

        try {
            $thread = service('supportTicketService')->adminThread($ticketRef);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/support'))->with('error', $e->getMessage());
        }

        $member = model(MemberProfileModel::class)->findByUserId((int) $thread['ticket']['member_user_id']);

        return view('admin/support/show', [
            'title'      => 'Ticket ' . $thread['ticket']['ticket_ref'],
            'ticket'     => $thread['ticket'],
            'messages'   => $thread['messages'],
            'member'     => $member,
            'categories' => SupportTicketService::CATEGORIES,
            'statuses'   => SupportTicketService::STATUSES,
        ]);
    }

    public function reply(string $ticketRef)
    {
        if (! $this->canManageSupport()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage support tickets.');
        }

        try {
            service('supportTicketService')->adminReply(
                (int) auth()->id(),
                $ticketRef,
                (string) $this->request->getPost('message'),
                (string) $this->request->getPost('status')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Admin support reply failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to send reply.');
        }

        return redirect()->to(site_url('admin/support/' . rawurlencode($ticketRef)))
            ->with('success', 'Reply sent.');
    }

    public function status(string $ticketRef)
    {
        if (! $this->canManageSupport()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage support tickets.');
        }

        try {
            service('supportTicketService')->updateStatus(
                (int) auth()->id(),
                $ticketRef,
                (string) $this->request->getPost('status')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/support/' . rawurlencode($ticketRef)))
            ->with('success', 'Ticket status updated.');
    }

    private function canManageSupport(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->inGroup('admin')
            || $user->can('admin.support.manage')
            || $user->can('admin.members.manage');
    }
}
