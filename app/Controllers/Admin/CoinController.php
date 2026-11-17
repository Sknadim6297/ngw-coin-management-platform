<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\Concerns\AdminAuthorization;
use App\Controllers\BaseController;
use App\Models\CoinInventoryTransactionModel;
use App\Models\CoinPriceHistoryModel;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;

class CoinController extends BaseController
{
    use AdminAuthorization;

    public function index()
    {
        return redirect()->to(site_url('admin/coins/inventory'));
    }

    public function inventory()
    {
        if (! $this->userCan('admin.coins.manage', 'admin.members.manage')) {
            return $this->denyPermission();
        }

        $tab = trim((string) $this->request->getGet('tab'));
        if (! in_array($tab, ['overview', 'add', 'history'], true)) {
            $tab = 'overview';
        }

        $inventory = service('coinInventoryService')->summary();
        $payload = [
            'title'     => 'Coin Inventory',
            'inventory' => $inventory,
            'tab'       => $tab,
        ];

        if ($tab === 'add') {
            $token = bin2hex(random_bytes(16));
            session()->setFlashdata('coin_inventory_idempotency', $token);
            $payload['idempotencyKey'] = $token;
        } elseif ($tab === 'history') {
            $model = model(CoinInventoryTransactionModel::class);
            $rows  = $model->orderBy('id', 'DESC')->paginate(20);
            $payload['rows'] = $rows;
            $payload['pager'] = $model->pager;
            $payload['actorNames'] = $this->actorNameMap($rows);
        }

        return view('admin/coins/inventory', $payload);
    }

    public function addForm()
    {
        return redirect()->to(site_url('admin/coins/inventory?tab=add'));
    }

    public function addStore()
    {
        if (! $this->userCan('admin.coins.manage', 'admin.members.manage')) {
            return $this->denyPermission();
        }

        $rules = config('Validation')->coinInventoryAdd;

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $idempotencyKey = (string) $this->request->getPost('idempotency_key');
        // Unique DB constraint on idempotency_key is the authoritative duplicate guard.

        try {
            $result = service('coinInventoryService')->addInventory(
                (string) $this->request->getPost('quantity'),
                (int) auth()->id(),
                $this->nullablePost('reference'),
                $this->nullablePost('remarks'),
                $idempotencyKey
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Inventory add failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to add inventory. Please try again.');
        }

        if (! empty($result['duplicate'])) {
            return redirect()->to(site_url('admin/coins/inventory'))
                ->with('success', 'This submission was already processed. Inventory was not double-credited. Current balance: '
                    . $result['inventory']['available_balance']);
        }

        return redirect()->to(site_url('admin/coins/inventory'))
            ->with('success', 'Internal inventory credited. New available balance: '
                . $result['inventory']['available_balance']
                . ' (unverified internal record).');
    }

    public function inventoryHistory()
    {
        return redirect()->to(site_url('admin/coins/inventory?tab=history'));
    }

    public function price()
    {
        if (! $this->userCan('admin.coins.manage', 'admin.members.manage')) {
            return $this->denyPermission();
        }

        $tab = trim((string) $this->request->getGet('tab'));
        if (! in_array($tab, ['settings', 'history'], true)) {
            $tab = 'settings';
        }

        $payload = [
            'title'    => 'Coin Pricing',
            'settings' => service('coinPriceService')->settingsSummary(),
            'tab'      => $tab,
        ];

        if ($tab === 'history') {
            $model = model(CoinPriceHistoryModel::class);
            $rows  = $model->orderBy('id', 'DESC')->paginate(20);
            $payload['rows'] = $rows;
            $payload['pager'] = $model->pager;
            $payload['actorNames'] = $this->actorNameMap($rows);
        }

        return view('admin/coins/price', $payload);
    }

    public function priceUpdate()
    {
        if (! $this->userCan('admin.coins.manage', 'admin.members.manage')) {
            return $this->denyPermission();
        }

        $rules = config('Validation')->coinPriceUpdate;

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $result = service('coinPriceService')->updatePrice(
                (string) $this->request->getPost('new_price'),
                (int) auth()->id(),
                (string) $this->request->getPost('reason')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Price update failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to update coin price. Please try again.');
        }

        return redirect()->to(site_url('admin/coins/price'))
            ->with('success', 'Coin price updated to '
                . $result['settings']['current_price_usdt']
                . ' USDT per coin.');
    }

    public function priceHistory()
    {
        return redirect()->to(site_url('admin/coins/price?tab=history'));
    }

    private function nullablePost(string $key): ?string
    {
        $value = trim((string) $this->request->getPost($key));

        return $value === '' ? null : $value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function actorNameMap(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (! empty($row['actor_user_id'])) {
                $ids[] = (int) $row['actor_user_id'];
            }
            if (! empty($row['price_updated_by'])) {
                $ids[] = (int) $row['price_updated_by'];
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $users = model(UserModel::class)->whereIn('id', $ids)->findAll();
        $map   = [];
        foreach ($users as $user) {
            $map[(int) $user->id] = $user->getEmail() ?: ('User #' . $user->id);
        }

        return $map;
    }
}
