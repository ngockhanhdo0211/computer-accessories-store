<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class GetAdminDashboardStats
{
    public function handle(): array
    {
        $roles = ['customer' => 0, 'employee' => 0, 'admin' => 0];
        $statuses = ['active' => 0, 'locked' => 0, 'inactive' => 0];
        $userTotal = 0;

        $userGroups = DB::table('users')
            ->select('role', 'status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('role', 'status')
            ->get();

        foreach ($userGroups as $group) {
            $count = (int) $group->total;
            $userTotal += $count;

            if (array_key_exists($group->role, $roles)) {
                $roles[$group->role] += $count;
            }

            if (array_key_exists($group->status, $statuses)) {
                $statuses[$group->status] += $count;
            }
        }

        $roleShares = [];
        foreach ($roles as $role => $count) {
            $roleShares[$role] = $userTotal === 0 ? 0 : min(100, intdiv($count * 100, $userTotal));
        }

        $categoryAggregate = DB::table('categories')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END), 0) AS roots')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_visible = 1 THEN 1 ELSE 0 END), 0) AS visible')
            ->first();

        $categoryTotal = (int) $categoryAggregate->total;
        $rootCount = (int) $categoryAggregate->roots;
        $visibleCount = (int) $categoryAggregate->visible;

        $productAggregate = DB::table('products')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN sellable_quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock')
            ->selectRaw('COALESCE(SUM(CASE WHEN sellable_quantity > 0 AND sellable_quantity <= low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock')
            ->selectSub(
                DB::table('inventory_adjustment_requests')->selectRaw('COUNT(*)')
                    ->whereNull('approved_at')->whereNull('rejected_at'),
                'pending_adjustments'
            )->first();

        return [
            'users' => [
                'total' => $userTotal,
                'roles' => $roles,
                'role_shares' => $roleShares,
                'statuses' => $statuses,
            ],
            'categories' => [
                'total' => $categoryTotal,
                'roots' => $rootCount,
                'children' => $categoryTotal - $rootCount,
                'visible' => $visibleCount,
                'hidden' => $categoryTotal - $visibleCount,
            ],
            'inventory' => [
                'products' => (int) $productAggregate->total,
                'low_stock' => (int) $productAggregate->low_stock,
                'out_of_stock' => (int) $productAggregate->out_of_stock,
                'pending_adjustments' => (int) $productAggregate->pending_adjustments,
            ],
        ];
    }
}
