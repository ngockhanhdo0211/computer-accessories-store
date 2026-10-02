<form class="checkout-payment-form checkout-payment-form--cod" method="POST" action="{{ route('checkout.cod.store') }}" data-submit-once>
    @csrf
    <input type="hidden" name="request_key" value="{{ $requestKey }}">
    <input type="hidden" name="recipient_name" value="{{ $quote->recipient->name }}">
    <input type="hidden" name="recipient_email" value="{{ $quote->recipient->email }}">
    <input type="hidden" name="recipient_phone" value="{{ $quote->recipient->phone }}">
    <input type="hidden" name="province" value="{{ $quote->recipient->province }}">
    <input type="hidden" name="district" value="{{ $quote->recipient->district }}">
    <input type="hidden" name="ward" value="{{ $quote->recipient->ward }}">
    <input type="hidden" name="address_line" value="{{ $quote->recipient->addressLine }}">
    <input type="hidden" name="coupon_code" value="{{ $quote->coupon?->code }}">
    <div>
        <strong>Thanh toán khi nhận hàng (COD)</strong>
        <p>Giá, tồn kho, phí vận chuyển và mã giảm giá sẽ được kiểm tra lại trước khi tạo đơn.</p>
    </div>
    <button class="button" type="submit" data-submit-label="Đang tạo đơn…">Đặt hàng COD</button>
</form>
