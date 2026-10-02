<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\VnPayGateway;
use App\ValueObjects\CheckoutRecipient;
use App\ValueObjects\VnPayInitiation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InitiateVnPayPayment
{
    public function __construct(
        private readonly CreatePaymentAttempt $attempts,
        private readonly VnPayGateway $gateway,
    ) {}

    public function handle(
        User $user,
        CheckoutRecipient $recipient,
        string $requestKey,
        ?string $couponCode,
        string $ipAddress,
    ): VnPayInitiation {
        $this->gateway->validatedConfiguration();
        $ipAddress = trim($ipAddress);
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false || strlen($ipAddress) > 45) {
            throw ValidationException::withMessages([
                'payment_method' => 'Không thể xác định địa chỉ mạng hợp lệ để khởi tạo VNPay.',
            ]);
        }

        return DB::transaction(function () use ($user, $recipient, $requestKey, $couponCode, $ipAddress): VnPayInitiation {
            $attempt = $this->attempts->handle($user, $recipient, $requestKey, $couponCode, null, $ipAddress);
            $this->snapshotInitiationIpOnce($attempt, $ipAddress);
            $attempt->setRelation(
                'stockReservations',
                $attempt->stockReservations()->orderBy('product_id')->lockForUpdate()->get(),
            );
            $attempt->setRelation('couponUsage', $attempt->couponUsage()->lockForUpdate()->first());
            $this->assertActiveAttempt($attempt);

            return new VnPayInitiation($attempt, $this->gateway->buildPaymentUrl($attempt));
        }, 3);
    }

    private function snapshotInitiationIpOnce(PaymentAttempt $attempt, string $ipAddress): void
    {
        if ($attempt->initiated_ip_address !== null) {
            return;
        }

        $isPristine = $attempt->status === PaymentStatus::Unpaid
            && $attempt->gateway_transaction_id === null
            && $attempt->gateway_result_code === null
            && $attempt->verified_at === null
            && ! $attempt->late_callback_exception
            && preg_match('/^PA[A-F0-9]{32}$/D', $attempt->gateway_reference) === 1
            && ! $attempt->order()->exists();

        if (! $isPristine) {
            throw ValidationException::withMessages([
                'request_key' => 'Giao dịch cũ thiếu bằng chứng khởi tạo VNPay hợp lệ. Vui lòng tạo giao dịch mới.',
            ]);
        }

        $updated = DB::table('payment_attempts')
            ->where('id', $attempt->id)
            ->whereNull('initiated_ip_address')
            ->update([
                'initiated_ip_address' => $ipAddress,
                'updated_at' => now()->utc()->format('Y-m-d H:i:s.u'),
            ]);

        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'request_key' => 'Không thể ghi nhận an toàn lần khởi tạo VNPay. Vui lòng thử lại.',
            ]);
        }

        $attempt->refresh();
    }

    private function assertActiveAttempt(PaymentAttempt $attempt): void
    {
        if ($attempt->status !== PaymentStatus::Unpaid) {
            throw ValidationException::withMessages([
                'payment_method' => 'Giao dịch này không còn ở trạng thái chờ thanh toán.',
            ]);
        }
        if ($attempt->expires_at->lessThanOrEqualTo(now()->utc())) {
            throw ValidationException::withMessages([
                'request_key' => 'Giao dịch VNPay đã hết hạn. Vui lòng tạo lại với một khóa giao dịch mới.',
            ]);
        }
        if (! is_string($attempt->initiated_ip_address)
            || filter_var($attempt->initiated_ip_address, FILTER_VALIDATE_IP) === false) {
            throw ValidationException::withMessages([
                'request_key' => 'Giao dịch cũ chưa có dữ liệu khởi tạo VNPay hợp lệ. Vui lòng tạo giao dịch mới.',
            ]);
        }
        if ($attempt->stockReservations->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Giao dịch VNPay không có giữ tồn kho hợp lệ.']);
        }

        $snapshotQuantities = $this->snapshotQuantities($attempt);
        $reservationQuantities = [];
        foreach ($attempt->stockReservations as $reservation) {
            if (! $reservation instanceof StockReservation
                || (int) $reservation->payment_attempt_id !== (int) $attempt->id
                || $reservation->quantity <= 0
                || isset($reservationQuantities[$reservation->product_id])
                || $reservation->released_at !== null
                || $reservation->consumed_at !== null
                || ! $reservation->expires_at->equalTo($attempt->expires_at)) {
                throw ValidationException::withMessages(['cart' => 'Trạng thái giữ tồn kho của giao dịch VNPay không nhất quán.']);
            }
            $reservationQuantities[(int) $reservation->product_id] = (int) $reservation->quantity;
        }
        ksort($reservationQuantities);
        if ($snapshotQuantities !== $reservationQuantities) {
            throw ValidationException::withMessages(['cart' => 'Dữ liệu giữ tồn kho không khớp giỏ hàng đã chốt.']);
        }

        $usage = $attempt->couponUsage;
        if (($attempt->coupon_id === null && $usage !== null)
            || ($attempt->coupon_id !== null && $usage === null)
            || ($usage !== null && (
                (int) $usage->coupon_id !== (int) $attempt->coupon_id
                || (int) $usage->customer_id !== (int) $attempt->user_id
                || (int) $usage->payment_attempt_id !== (int) $attempt->id
                || $usage->status !== CouponUsageStatus::Reserved
                || $usage->order_id !== null
                || $usage->consumed_at !== null
                || $usage->released_at !== null
                || $usage->expires_at === null
                || ! $usage->expires_at->equalTo($attempt->expires_at)
            ))) {
            throw ValidationException::withMessages(['coupon_code' => 'Trạng thái giữ lượt Coupon của giao dịch VNPay không nhất quán.']);
        }
    }

    /** @return array<int, int> */
    private function snapshotQuantities(PaymentAttempt $attempt): array
    {
        if (! is_array($attempt->items_snapshot_json)) {
            throw ValidationException::withMessages(['cart' => 'Dữ liệu giỏ hàng đã chốt không hợp lệ.']);
        }

        $quantities = [];
        foreach ($attempt->items_snapshot_json as $line) {
            if (! is_array($line)
                || ! isset($line['product_id'], $line['quantity'])
                || ! is_int($line['product_id'])
                || ! is_int($line['quantity'])
                || $line['product_id'] <= 0
                || $line['quantity'] <= 0
                || isset($quantities[$line['product_id']])) {
                throw ValidationException::withMessages(['cart' => 'Dữ liệu giỏ hàng đã chốt không hợp lệ.']);
            }
            $quantities[$line['product_id']] = $line['quantity'];
        }

        ksort($quantities);

        return $quantities;
    }
}
