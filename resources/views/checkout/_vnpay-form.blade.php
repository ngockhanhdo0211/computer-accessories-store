<form class="checkout-payment-form checkout-payment-form--vnpay" method="POST" action="{{ route('checkout.vnpay.initiate') }}" data-submit-once>
    @csrf
    <input type="hidden" name="request_key" value="{{ $vnpayRequestKey }}">
    <input type="hidden" name="recipient_name" value="{{ $quote->recipient->name }}">
    <input type="hidden" name="recipient_email" value="{{ $quote->recipient->email }}">
    <input type="hidden" name="recipient_phone" value="{{ $quote->recipient->phone }}">
    <input type="hidden" name="province" value="{{ $quote->recipient->province }}">
    <input type="hidden" name="district" value="{{ $quote->recipient->district }}">
    <input type="hidden" name="ward" value="{{ $quote->recipient->ward }}">
    <input type="hidden" name="address_line" value="{{ $quote->recipient->addressLine }}">
    <input type="hidden" name="coupon_code" value="{{ $quote->coupon?->code }}">
    <div>
        <strong>Thanh toán VNPay</strong>
        <p>Bạn sẽ được chuyển sang cổng VNPay Sandbox. Đơn hàng chỉ được tạo sau khi kết quả được xác minh ở bước callback sau.</p>
    </div>
    <button class="button" type="submit" data-submit-label="Đang chuyển sang VNPay…" @disabled(! $vnpayAvailable)>Chuyển sang cổng VNPay</button>
    @unless($vnpayAvailable)
        <p class="checkout-payment-form__unavailable" role="status">Dịch vụ VNPay tạm thời chưa khả dụng. Bạn vẫn có thể chọn COD.</p>
    @endunless
</form>
