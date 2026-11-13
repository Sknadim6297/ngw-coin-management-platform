<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

class PaymentNetworkController extends BaseController
{
    public function index()
    {
        return view('admin/networks/index', [
            'title'    => 'Payment Networks',
            'networks' => service('usdtPaymentNetworkService')->listAll(),
        ]);
    }

    public function create()
    {
        return view('admin/networks/form', [
            'title'   => 'Add Payment Network',
            'network' => null,
            'mode'    => 'create',
        ]);
    }

    public function store()
    {
        $rules = config('Validation')->usdtNetworkSave;
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $row = service('usdtPaymentNetworkService')->create(
                $this->networkPayload(),
                (int) auth()->id()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Network create failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to create payment network.');
        }

        return redirect()->to(site_url('admin/networks'))
            ->with('success', 'Network "' . $row['network_name'] . '" saved. It stays disabled until you enable it with a verified destination wallet.');
    }

    public function edit(int $id)
    {
        $network = service('usdtPaymentNetworkService')->find($id);
        if ($network === null) {
            return redirect()->to(site_url('admin/networks'))->with('error', 'Network not found.');
        }

        return view('admin/networks/form', [
            'title'   => 'Edit Payment Network',
            'network' => $network,
            'mode'    => 'edit',
        ]);
    }

    public function update(int $id)
    {
        $rules = config('Validation')->usdtNetworkSave;
        unset($rules['network_key']); // immutable
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('usdtPaymentNetworkService')->update(
                $id,
                $this->networkPayload(),
                (int) auth()->id()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Network update failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to update payment network.');
        }

        return redirect()->to(site_url('admin/networks'))
            ->with('success', 'Payment network updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function networkPayload(): array
    {
        return [
            'network_key'              => (string) $this->request->getPost('network_key'),
            'network_name'             => (string) $this->request->getPost('network_name'),
            'chain_id'                 => $this->request->getPost('chain_id'),
            'verifier_type'            => (string) $this->request->getPost('verifier_type'),
            'token_symbol'             => (string) $this->request->getPost('token_symbol'),
            'token_contract_address'   => (string) $this->request->getPost('token_contract_address'),
            'token_decimals'           => (int) $this->request->getPost('token_decimals'),
            'destination_wallet'       => (string) $this->request->getPost('destination_wallet'),
            'address_pattern'          => (string) $this->request->getPost('address_pattern'),
            'tx_hash_pattern'          => (string) $this->request->getPost('tx_hash_pattern'),
            'required_confirmations'   => (int) $this->request->getPost('required_confirmations'),
            'min_deposit_usdt'         => (string) $this->request->getPost('min_deposit_usdt'),
            'max_deposit_usdt'         => (string) $this->request->getPost('max_deposit_usdt'),
            'instructions'             => (string) $this->request->getPost('instructions'),
            'explorer_tx_url_template' => (string) $this->request->getPost('explorer_tx_url_template'),
            'rpc_url_env_key'          => (string) $this->request->getPost('rpc_url_env_key'),
            'is_enabled'               => $this->request->getPost('is_enabled') ? 1 : 0,
            'sort_order'               => (int) $this->request->getPost('sort_order'),
        ];
    }
}
