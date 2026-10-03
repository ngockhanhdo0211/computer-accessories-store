@extends('layouts.workspace')

@section('title', 'Hoàn tiền VNPay')

@section('content')
<div class="shell admin-page managed-order-page">
    <header class="page-heading managed-order-heading">
        <div>
            <p class="eyebrow">Thanh toán</p>
            <h1>Hoàn tiền VNPay</h1>
            <p>Theo dõi Refund pending, kết quả gateway và các trường hợp cần đối soát thủ công.</p>
        </div>
    </header>

    @if($refunds->isEmpty())
        <section class="empty-state" aria-labelledby="refund-empty-title">
            <p class="eyebrow">Không có công việc chờ</p>
            <h2 id="refund-empty-title">Chưa có Refund</h2>
            <p>Refund chỉ được tạo từ các nhánh VNPay hợp lệ của hệ thống.</p>
        </section>
    @else
        <div class="managed-order-table-wrap">
            <table class="managed-order-table">
                <thead>
                    <tr>
                        <th scope="col">Refund</th>
                        <th scope="col">Giao dịch gốc</th>
                        <th scope="col">Số tiền</th>
                        <th scope="col">Refund</th>
                        <th scope="col">Gateway</th>
                        <th scope="col">Tạo lúc</th>
                        <th scope="col">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($refunds as $refund)
                        @php
                            $gateway = $refund->gatewayAttempt;
                            [$refundLabel, $refundClass] = match($refund->status) {
                                App\Enums\RefundStatus::Succeeded => ['Thành công', 'status-badge--success'],
                                App\Enums\RefundStatus::Failed => ['Thất bại', 'status-badge--danger'],
                                default => ['Đang chờ', 'status-badge--warning'],
                            };
                            [$gatewayLabel, $gatewayClass] = match($gateway?->status) {
                                App\Enums\RefundGatewayAttemptStatus::Succeeded => ['Thành công', 'status-badge--success'],
                                App\Enums\RefundGatewayAttemptStatus::Failed => ['Thất bại', 'status-badge--danger'],
                                App\Enums\RefundGatewayAttemptStatus::Ambiguous => ['Cần đối soát', 'status-badge--warning'],
                                App\Enums\RefundGatewayAttemptStatus::Submitted => ['Đã ghi nhận', 'status-badge--info'],
                                default => ['Chưa gửi', 'status-badge--neutral'],
                            };
                        @endphp
                        <tr>
                            <td data-label="Refund"><strong>#{{ $refund->id }}</strong><small>{{ $refund->reason->value }}</small></td>
                            <td data-label="Giao dịch gốc"><strong>{{ $refund->paymentAttempt->gateway_reference }}</strong><small>{{ $refund->paymentAttempt->gateway_transaction_id }}</small></td>
                            <td data-label="Số tiền"><strong>{{ number_format($refund->amount_vnd, 0, ',', '.') }} VND</strong></td>
                            <td data-label="Refund"><span class="status-badge {{ $refundClass }}">{{ $refundLabel }}</span></td>
                            <td data-label="Gateway"><span class="status-badge {{ $gatewayClass }}">{{ $gatewayLabel }}</span></td>
                            <td data-label="Tạo lúc"><time datetime="{{ $refund->created_at->toIso8601String() }}">{{ $refund->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</time></td>
                            <td data-label="Thao tác"><a class="text-link managed-order-table__action" href="{{ route('admin.refunds.show', $refund) }}">Xem evidence</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $refunds->links() }}
    @endif
</div>
@endsection
