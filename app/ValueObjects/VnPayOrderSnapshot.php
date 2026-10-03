<?php

namespace App\ValueObjects;

final readonly class VnPayOrderSnapshot
{
    /**
     * @param  list<array<string, int|string|null>>  $lines
     * @param  array<string, string>  $recipient
     * @param  array<string, mixed>  $pricing
     * @param  array<string, mixed>|null  $coupon
     */
    public function __construct(
        public array $lines,
        public array $recipient,
        public array $pricing,
        public ?array $coupon,
        public bool $historical,
        public bool $incompleteDiscountAllocation,
    ) {}

    /** @return list<int> */
    public function productIds(): array
    {
        $ids = array_map(fn (array $line): int => $line['product_id'], $this->lines);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
