@extends('layouts.workspace')

@section('title', 'Refund #'.$refund->id)

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
@endphp
<div class="shell admin-page managed-order-page">
    <header class="order-detail-header">
        <div>
            <a class="text-link" href="{{ route('admin.refunds.index') }}">← Danh sách hoàn tiền</a>
            <p class="eyebrow">VNPay Refund</p>
            <h1>Refund #{{ $refund->id }}</h1>
            <p>{{ number_format($refund->amount_vnd, 0, ',', '.') }} VND · {{ $refund->paymentAttempt->gateway_reference }}</p>
        </div>
        <div class="order-detail-header__status">
            <span class="status-badge {{ $refundClass }}">{{ $refundLabel }}</span>
        </div>
    </header>

    <div class="order-detail-layout">
        <main class="order-detail-main">
            <section class="order-panel">
                <p class="section-label">Evidence nghiệp vụ</p>
                <h2>Refund và giao dịch gốc</h2>
                <dl class="order-summary-list">
                    <div><dt>Lý do</dt><dd>{{ $refund->reason->value }}</dd></div>
                    <div><dt>Payment Attempt</dt><dd>#{{ $refund->payment_attempt_id }}</dd></div>
                    <div><dt>VNPay TxnRef</dt><dd>{{ $refund->paymentAttempt->gateway_reference }}</dd></div>
                    <div><dt>VNPay Transaction No</dt><dd>{{ $refund->paymentAttempt->gateway_transaction_id }}</dd></div>
                    <div><dt>Payment status</dt><dd>{{ $refund->paymentAttempt->status->label() }}</dd></div>
                    <div><dt>Order</dt><dd>{{ $refund->order?->order_code ?? 'Không có Order' }}</dd></div>
                </dl>
            </section>

            <section class="order-panel">
                <p class="section-label">Gateway evidence whitelist</p>
                <h2>{{ $gateway ? 'Một yêu cầu duy nhất' : 'Chưa gửi yêu cầu' }}</h2>
                @if($gateway)
                    <dl class="order-summary-list">
                        <div><dt>Request ID</dt><dd>{{ $gateway->request_id }}</dd></div>
                        <div><dt>Request fingerprint</dt><dd class="refund-evidence-value">{{ $gateway->request_fingerprint }}</dd></div>
                        <div><dt>Trạng thái</dt><dd>{{ $gateway->status->value }}</dd></div>
                        <div><dt>Response code</dt><dd>{{ $gateway->response_code ?? '—' }}</dd></div>
                        <div><dt>Transaction status</dt><dd>{{ $gateway->transaction_status ?? '—' }}</dd></div>
                        <div><dt>Gateway reference</dt><dd>{{ $gateway->gateway_reference ?? '—' }}</dd></div>
                        <div><dt>Response fingerprint</dt><dd class="refund-evidence-value">{{ $gateway->response_fingerprint ?? '—' }}</dd></div>
                        <div><dt>Gửi lúc</dt><dd>{{ $gateway->submitted_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</dd></div>
                    </dl>
                @else
                    <p>Hệ thống chưa tạo Request ID và chưa thực hiện HTTP call tới VNPay.</p>
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
                    <p class="section-label">At-most-once</p>
                    <h2>Gửi yêu cầu hoàn tiền</h2>
                    <p>Hệ thống chỉ gửi tối đa một HTTP request. Không tự động retry khi kết quả không rõ.</p>
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
                    <p class="section-label">Manual reconciliation</p>
                    <h2>Xác nhận kết quả</h2>
                    <div class="alert alert--warning" role="alert">Hãy kiểm tra VNPay Merchant Portal theo TxnRef, Request ID, thời gian và số tiền trước khi xác nhận.</div>
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
                    <p class="section-label">Submission bị gián đoạn</p>
                    <h3>Không gửi lại request</h3>
                    <p>Evidence đang ở trạng thái submitted. Operational lease bảo vệ request đang chạy; hệ thống tuyệt đối không gửi lại request này.</p>
                    @if($canMarkSubmissionAmbiguous)
                        <form method="POST" action="{{ route('admin.refunds.mark-ambiguous', $refund) }}" data-submit-once data-confirm-action="Bạn đã xác minh request quá hạn và không có response được ghi nhận?">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="event_key" value="{{ old('event_key', (string) \Illuminate\Support\Str::uuid()) }}">
                            @foreach(['authorization', 'refund', 'event_key', 'request'] as $field)
                                @error($field, 'markRefundAmbiguous')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            @endforeach
                            <div class="alert alert--warning" role="alert">Hãy kiểm tra VNPay Merchant Portal theo Request ID, TxnRef, thời gian và số tiền trước khi chuyển sang đối soát.</div>
                            <div class="field">
                                <label for="interruption-note">Ghi chú sự cố</label>
                                <textarea id="interruption-note" name="note" rows="3" maxlength="500" required>{{ old('note') }}</textarea>
                                @error('note', 'markRefundAmbiguous')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <button class="button button--quiet" type="submit">Chuyển sang cần đối soát</button>
                        </form>
                    @elseif($submissionStaleSeconds !== null)
                        <p class="helper-text">Thao tác đối soát chỉ mở sau {{ $submissionStaleSeconds }} giây tính từ server timestamp. Hãy tải lại trang sau thời điểm này.</p>
                    @else
                        <div class="alert alert--danger" role="alert">Cấu hình operational lease không hợp lệ. Không thể thay đổi trạng thái request.</div>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
@endsection
