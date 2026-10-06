<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesReturnInspection;
use App\Models\Order;
use App\Models\User;

class GetReturnInspectionOperations
{
    use AuthorizesReturnInspection;

    public function handle(string $orderCode, User $actor): Order
    {
        $order = Order::query()->where('order_code', $orderCode)->with([
            'items.returnInspection.receiver', 'items.returnInspection.inspector',
        ])->firstOrFail();
        abort_unless($this->returnInspectionAccessAllowed($actor, $order), 403);

        return $order;
    }

    public function allows(User $actor, Order $order): bool
    {
        return $this->returnInspectionAccessAllowed($actor, $order);
    }
}
