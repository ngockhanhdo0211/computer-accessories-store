@extends('layouts.workspace')

@section('title', 'Hoàn tiền '.$refund->paymentAttempt->gateway_reference)

@section('content')
@php
    $gateway = $refund->gatewayAttempt;
    $canMarkSubmissionAmbiguous = $gateway?->status === App\Enums\RefundGatewayAttemptStatus::Submitted
        && $submissionStaleSeconds !== null
        && $gateway->submitted_at->addSeconds($submissionStaleSeconds)->isPast();
    [$refundLabel, $refundClass] = match($refund->status) {
        App\Enums\RefundStatus::Succeeded => ['Thành công', 'status-badge--success'],
        App\Enums\RefundStatus::Failed => ['Thất bại', 'status-badge--danger'],
        default => ['Đang chờ', 'status-badge--warning'],
    };
    $gatewayLabel = match($gateway?->status) {
        App\Enums\RefundGatewayAttemptStatus::Succeeded => 'Thành công',
        App\Enums\RefundGatewayAttemptStatus::Failed => 'Thất bại',
        App\Enums\RefundGatewayAttemptStatus::Ambiguous => 'Cần đối soát',
        App\Enums\RefundGatewayAttemptStatus::Submitted => 'Đã ghi nhận',
        default => 'Chưa gửi',
    };
@endphp
<div class="shell admin-page managed-order-page">
    <header class="order-detail-header">
        <div>
            <a class="text-link" href="{{ route('admin.refunds.index') }}">← Danh sách hoàn tiền</a>
            <p class="eyebrow">Hoàn tiền VNPay</p>
            <h1>{{ $refund->paymentAttempt->gateway_reference }}</h1>
            <p>{{ number_format($refund->amount_vnd, 0, ',', '.') }} VND · {{ $refund->paymentAttempt->gateway_reference }}</p>
        </div>
        <div class="order-detail-header__status">
            <span class="status-badge {{ $refundClass }}">{{ $refundLabel }}</span>
        </div>
    </header>

    <div class="order-detail-layout">
        <main class="order-detail-main">
            <section class="order-panel">
                <p class="section-label">Giao dịch</p>
                <h2>Thông tin hoàn tiền</h2>
                <dl class="order-summary-list">
                    <div><dt>Lý do</dt><dd>{{ $refund->reason->value }}</dd></div>
                    <div><dt>Mã tham chiếu VNPay</dt><dd>{{ $refund->paymentAttempt->gateway_reference }}</dd></div>
                    <div><dt>Mã giao dịch VNPay</dt><dd>{{ $refund->paymentAttempt->gateway_transaction_id }}</dd></div>
                    <div><dt>Trạng thái thanh toán</dt><dd>{{ $refund->paymentAttempt->status->label() }}</dd></div>
                    <div><dt>Đơn hàng</dt><dd>{{ $refund->order?->order_code ?? 'Không có đơn hàng' }}</dd></div>
                </dl>
            </section>

            <section class="order-panel">
                <p class="section-label">Kết quả từ VNPay</p>
                <h2>{{ $gateway ? 'Trạng thái xử lý' : 'Chưa gửi yêu cầu' }}</h2>
                @if($gateway)
                    <dl class="order-summary-list">
                        <div><dt>Trạng thái</dt><dd>{{ $gatewayLabel }}</dd></div>
                        <div><dt>Mã phản hồi</dt><dd>{{ $gateway->response_code ?? '—' }}</dd></div>
                        <div><dt>Trạng thái giao dịch</dt><dd>{{ $gateway->transaction_status ?? '—' }}</dd></div>
                        <div><dt>Mã chứng từ</dt><dd>{{ $gateway->gateway_reference ?? '—' }}</dd></div>
                        <div><dt>Gửi lúc</dt><dd>{{ $gateway->submitted_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</dd></div>
                    </dl>
                @else
                    <p>Yêu cầu hoàn tiền chưa được gửi tới VNPay.</p>
                @endif
            </section>

            @if($gateway?->reconciled_at)
                <section class="order-panel">
                    <p class="section-label">Đối soát thủ công</p>
                    <h2>Chứng từ vận hành</h2>
                    <dl class="order-summary-list">
                        <div><dt>Admin</dt><dd>{{ $gateway->reconciliationActor?->name ?? 'Không còn tài khoản' }}</dd></div>
                        <div><dt>Thời gian</dt><dd>{{ $gateway->reconciled_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</dd></div>
                        <div><dt>Ghi chú</dt><dd>{{ $gateway->reconciliation_note }}</dd></div>
                    </dl>
                </section>
            @endif
        </main>

        <aside class="order-detail-sidebar">
            @if(!$gateway && $refund->status === App\Enums\RefundStatus::Pending)
                <section class="order-transition">
                    <p class="section-label">Gửi tới VNPay</p>
                    <h2>Gửi yêu cầu hoàn tiền</h2>
                    <p>Mỗi yêu cầu chỉ được gửi một lần. Nếu kết quả chưa rõ, hãy chuyển sang đối soát.</p>
                    <form method="POST" action="{{ route('admin.refunds.submit', $refund) }}" data-submit-once data-confirm-action="Chỉ gửi một lần tới VNPay Sandbox. Tiếp tục?">
                        @csrf
                        <input type="hidden" name="event_key" value="{{ old('event_key', (string) \Illuminate\Support\Str::uuid()) }}">
                        @foreach(['authorization', 'refund', 'event_key', 'request'] as $field)
                            @error($field, 'submitRefund')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        @endforeach
                        <button class="button" type="submit">Gửi yêu cầu hoàn tiền</button>
                    </form>
                </section>
            @endif

            @if($gateway?->status === App\Enums\RefundGatewayAttemptStatus::Ambiguous)
                <section class="order-transition">
                    <p class="section-label">Đối soát thủ công</p>
                    <h2>Xác nhận kết quả</h2>
                    <div class="alert alert--warning" role="alert">Hãy kiểm tra cổng quản trị VNPay theo mã tham chiếu, thời gian và số tiền trước khi xác nhận.</div>
                    <form method="POST" action="{{ route('admin.refunds.reconcile', $refund) }}" data-submit-once data-confirm-action="Bạn đã đối chiếu chứng từ trên VNPay Merchant Portal?">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="event_key" value="{{ old('event_key', (string) \Illuminate\Support\Str::uuid()) }}">
                        @foreach(['authorization', 'refund', 'event_key', 'request'] as $field)
                            @error($field, 'reconcileRefund')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        @endforeach
                        <div class="field">
                            <label for="outcome">Kết quả đối soát</label>
                            <select id="outcome" name="outcome" required @error('outcome', 'reconcileRefund') aria-invalid="true" @enderror>
                                <option value="">Chọn kết quả</option>
                                <option value="succeeded" @selected(old('outcome') === 'succeeded')>Đã hoàn tiền thành công</option>
                                <option value="failed" @selected(old('outcome') === 'failed')>Thất bại / chưa thực hiện</option>
                            </select>
                            @error('outcome', 'reconcileRefund')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="gateway_reference">Mã chứng từ <span>(không bắt buộc)</span></label>
                            <input id="gateway_reference" name="gateway_reference" maxlength="100" value="{{ old('gateway_reference') }}">
                            @error('gateway_reference', 'reconcileRefund')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="note">Ghi chú đối soát</label>
                            <textarea id="note" name="note" rows="4" maxlength="500" required>{{ old('note') }}</textarea>
                            @error('note', 'reconcileRefund')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <button class="button" type="submit">Lưu kết quả đối soát</button>
                    </form>
                </section>
            @elseif($gateway?->status === App\Enums\RefundGatewayAttemptStatus::Submitted)
                <section class="order-transition">
                    <p class="section-label">Kết quả chưa rõ</p>
                    <h3>Không gửi lại yêu cầu</h3>
                    <p>Yêu cầu đã được ghi nhận là đang xử lý. Hãy chờ đủ thời gian hoặc kiểm tra trên cổng quản trị VNPay.</p>
                    @if($canMarkSubmissionAmbiguous)
                        <form method="POST" action="{{ route('admin.refunds.mark-ambiguous', $refund) }}" data-submit-once data-confirm-action="Bạn đã xác minh request quá hạn và không có response được ghi nhận?">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="event_key" value="{{ old('event_key', (string) \Illuminate\Support\Str::uuid()) }}">
                            @foreach(['authorization', 'refund', 'event_key', 'request'] as $field)
                                @error($field, 'markRefundAmbiguous')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            @endforeach
                            <div class="alert alert--warning" role="alert">Hãy kiểm tra cổng quản trị VNPay theo mã tham chiếu, thời gian và số tiền trước khi chuyển sang đối soát.</div>
                            <div class="field">
                                <label for="interruption-note">Ghi chú sự cố</label>
                                <textarea id="interruption-note" name="note" rows="3" maxlength="500" required>{{ old('note') }}</textarea>
                                @error('note', 'markRefundAmbiguous')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <button class="button button--quiet" type="submit">Chuyển sang cần đối soát</button>
                        </form>
                    @elseif($submissionStaleSeconds !== null)
                        <p class="helper-text">Thao tác đối soát sẽ mở sau {{ $submissionStaleSeconds }} giây. Hãy tải lại trang sau thời điểm này.</p>
                    @else
                        <div class="alert alert--danger" role="alert">Cấu hình thời gian chờ không hợp lệ. Không thể thay đổi trạng thái yêu cầu.</div>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
@endsection
