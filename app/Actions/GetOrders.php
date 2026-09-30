<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class GetOrders
{
    private const PER_PAGE = 20;

    /** @param array<string, mixed> $filters */
    public function forCustomer(User $customer, array $filters): LengthAwarePaginator
    {
        $orders = $this->filteredQuery($filters)
            ->where('orders.user_id', $customer->id)
            ->paginate(self::PER_PAGE);

        return $orders->appends($this->queryString($filters));
    }

    /** @param array<string, mixed> $filters */
    public function forManagement(array $filters): LengthAwarePaginator
    {
        $orders = $this->filteredQuery($filters, true)
            ->with('customer:id,name,email')
            ->paginate(self::PER_PAGE);

        return $orders->appends($this->queryString($filters));
    }

    public function customerDetail(User $customer, string $orderCode): Order
    {
        return $this->detailQuery()
            ->where('orders.user_id', $customer->id)
            ->where('orders.order_code', $orderCode)
            ->firstOrFail();
    }

    public function managedDetail(string $orderCode): Order
    {
        return $this->detailQuery(true)
            ->where('orders.order_code', $orderCode)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $filters */
    private function filteredQuery(array $filters, bool $managed = false): Builder
    {
        $query = Order::query()->select([
            'orders.id', 'orders.user_id', 'orders.order_code', 'orders.status',
            'orders.payment_method', 'orders.payment_status', 'orders.recipient_name',
            'orders.recipient_phone', 'orders.total_vnd', 'orders.created_at',
        ]);

        if (($filters['search'] ?? null) !== null) {
            $this->applySearch($query, $filters['search'], $managed);
        }

        $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('orders.status', $status))
            ->when($filters['payment_status'] ?? null, fn (Builder $query, string $status) => $query->where('orders.payment_status', $status))
            ->when($filters['payment_method'] ?? null, fn (Builder $query, string $method) => $query->where('orders.payment_method', $method))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('orders.created_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('orders.created_at', '<', CarbonImmutable::parse($date)->addDay()->startOfDay()));

        match ($filters['sort']) {
            'oldest' => $query->orderBy('orders.created_at')->orderBy('orders.id'),
            'total_asc' => $query->orderBy('orders.total_vnd')->orderBy('orders.id'),
            'total_desc' => $query->orderByDesc('orders.total_vnd')->orderByDesc('orders.id'),
            default => $query->orderByDesc('orders.created_at')->orderByDesc('orders.id'),
        };

        return $query;
    }

    private function applySearch(Builder $query, string $search, bool $managed): void
    {
        $like = '%'.$this->escapeLike(mb_strtolower($search)).'%';

        $query->where(function (Builder $query) use ($like, $managed, $search): void {
            $query->whereRaw("LOWER(orders.order_code) LIKE ? ESCAPE '!'", [$like]);

            if (! $managed) {
                return;
            }

            $query
                ->orWhereRaw("LOWER(orders.recipient_name) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(orders.recipient_email) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(orders.recipient_address) LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('customer', function (Builder $customer) use ($like): void {
                    $customer
                        ->whereRaw("LOWER(users.name) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(users.email) LIKE ? ESCAPE '!'", [$like]);
                });

            $phone = preg_replace('/\D+/', '', $search);
            if ($phone !== '') {
                $query->orWhereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(orders.recipient_phone, ' ', ''), '-', ''), '.', ''), '(', ''), ')', ''), '+', '') LIKE ?",
                    ['%'.$phone.'%'],
                );
            }
        });
    }

    private function detailQuery(bool $managed = false): Builder
    {
        $query = Order::query()
            ->select([
                'orders.id', 'orders.user_id', 'orders.payment_attempt_id', 'orders.order_code',
                'orders.status', 'orders.payment_method', 'orders.payment_status',
                'orders.recipient_name', 'orders.recipient_email', 'orders.recipient_phone',
                'orders.recipient_address', 'orders.recipient_region', 'orders.coupon_snapshot_json',
                'orders.items_subtotal_vnd', 'orders.item_discount_vnd', 'orders.shipping_fee_vnd',
                'orders.shipping_discount_vnd', 'orders.total_vnd', 'orders.delivered_at',
                'orders.created_at',
            ])
            ->with([
                'items:id,order_id,product_id,product_name,sku,quantity,unit_price_vnd,line_subtotal_vnd,discount_vnd,line_total_vnd',
                'statusHistories:id,order_id,from_status,to_status,actor_id,reason,created_at',
            ]);

        if ($managed) {
            $query->with([
                'customer:id,name,email,phone,status',
                'statusHistories.actor:id,name',
                'paymentAttempt:id,status,amount_vnd,gateway_reference,verified_at',
            ]);
        }

        return $query;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Keep pagination URLs limited to validated filters instead of reflecting
     * arbitrary query-string parameters back into generated links.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, scalar>
     */
    private function queryString(array $filters): array
    {
        return array_filter(
            $filters,
            static fn (mixed $value): bool => is_scalar($value) && $value !== '',
        );
    }
}
