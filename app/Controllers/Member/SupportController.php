<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Services\SupportTicketService;
use InvalidArgumentException;

class SupportController extends BaseController
{
    public function index()
    {
        $userId = (int) auth()->id();
        $list   = service('supportTicketService')->memberList($userId);

        return view('member/support/index', [
            'title'      => 'Support',
            'rows'       => $list['rows'],
            'total'      => $list['total'],
            'categories' => SupportTicketService::CATEGORIES,
            'statuses'   => SupportTicketService::STATUSES,
        ]);
    }

    public function create()
    {
        return view('member/support/create', [
            'title'      => 'New Support Ticket',
            'categories' => SupportTicketService::CATEGORIES,
        ]);
    }

    public function store()
    {
        try {
            $result = service('supportTicketService')->createTicket((int) auth()->id(), [
                'category' => (string) $this->request->getPost('category'),
                'subject'  => (string) $this->request->getPost('subject'),
                'message'  => (string) $this->request->getPost('message'),
            ]);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Member support create failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to create ticket.');
        }

        return redirect()->to(site_url('member/support/' . rawurlencode((string) $result['ticket']['ticket_ref'])))
            ->with('success', 'Support ticket created.');
    }

    public function show(string $ticketRef)
    {
        try {
            $thread = service('supportTicketService')->memberThread((int) auth()->id(), $ticketRef);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/support'))->with('error', $e->getMessage());
        }

        return view('member/support/show', [
            'title'      => 'Ticket ' . $thread['ticket']['ticket_ref'],
            'ticket'     => $thread['ticket'],
            'messages'   => $thread['messages'],
            'categories' => SupportTicketService::CATEGORIES,
            'statuses'   => SupportTicketService::STATUSES,
        ]);
    }

    public function reply(string $ticketRef)
    {
        try {
            service('supportTicketService')->memberReply(
                (int) auth()->id(),
                $ticketRef,
                (string) $this->request->getPost('message')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Member support reply failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to send reply.');
        }

        return redirect()->to(site_url('member/support/' . rawurlencode($ticketRef)))
            ->with('success', 'Reply sent.');
    }
}
