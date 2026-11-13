<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CoinInventoryTransactionModel;
use App\Models\CoinPriceHistoryModel;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;

class CoinController extends BaseController
{
    public function index()
    {
        $inventory = service('coinInventoryService')->summary();
        $price     = service('coinPriceService')->settingsSummary();

        $recentInventory = model(CoinInventoryTransactionModel::class)->recent(8);
        $recentPrices    = model(CoinPriceHistoryModel::class)->recent(8);

        return view('admin/coins/overview', [
            'title'           => 'Coin Management',
            'inventory'       => $inventory,
            'price'           => $price,
            'recentInventory' => $recentInventory,
            'recentPrices'    => $recentPrices,
            'actorNames'      => $this->actorNameMap(array_merge($recentInventory, $recentPrices)),
        ]);
    }

    public function inventory()
    {
        $inventory = service('coinInventoryService')->summary();

        return view('admin/coins/inventory', [
            'title'     => 'Coin Inventory',
            'inventory' => $inventory,
        ]);
    }

    public function addForm()
    {
        $inventory = service('coinInventoryService')->summary();
        $token     = bin2hex(random_bytes(16));
        session()->setFlashdata('coin_inventory_idempotency', $token);

        return view('admin/coins/add', [
            'title'           => 'Add Coins',
            'inventory'       => $inventory,
            'idempotencyKey'  => $token,
        ]);
    }

    public function addStore()
    {
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
        $model = model(CoinInventoryTransactionModel::class);
        $rows  = $model->orderBy('id', 'DESC')->paginate(20);

        return view('admin/coins/inventory_history', [
            'title'      => 'Inventory History',
            'rows'       => $rows,
            'pager'      => $model->pager,
            'actorNames' => $this->actorNameMap($rows),
            'inventory'  => service('coinInventoryService')->summary(),
        ]);
    }

    public function price()
    {
        return view('admin/coins/price', [
            'title'    => 'Coin Price Settings',
            'settings' => service('coinPriceService')->settingsSummary(),
        ]);
    }

    public function priceUpdate()
    {
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
        $model = model(CoinPriceHistoryModel::class);
        $rows  = $model->orderBy('id', 'DESC')->paginate(20);

        return view('admin/coins/price_history', [
            'title'      => 'Price Change History',
            'rows'       => $rows,
            'pager'      => $model->pager,
            'actorNames' => $this->actorNameMap($rows),
            'settings'   => service('coinPriceService')->settingsSummary(),
        ]);
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
