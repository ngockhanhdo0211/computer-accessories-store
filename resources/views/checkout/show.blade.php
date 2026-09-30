@extends('layouts.storefront')
@section('title', 'Báo giá checkout')
@section('content')
<div class="checkout-page shell">
    <header class="checkout-header">
        <div>
            <p class="eyebrow">Checkout Quote Foundation</p>
            <h1>Thông tin nhận hàng và bảng tính tạm thời.</h1>
        </div>
        <p>Server đọc lại giỏ hàng, giá, tồn khả dụng, phí vận chuyển và mã giảm giá mỗi lần bạn yêu cầu báo giá.</p>
    </header>

    @if ($errors->any())
        @php
            $errorTargets = [
                'recipient_name' => 'recipient_name',
                'recipient_email' => 'recipient_email',
                'recipient_phone' => 'recipient_phone',
                'province' => 'province',
                'district' => 'district',
                'ward' => 'ward',
                'address_line' => 'address_line',
                'coupon_code' => 'coupon_code',
                'request_key' => 'checkout-summary-title',
                'cart' => 'checkout-summary-title',
            ];
        @endphp
        <section class="alert alert--error checkout-validation-summary" role="alert" tabindex="-1" aria-labelledby="checkout-errors-title">
            <h2 id="checkout-errors-title">Vui lòng kiểm tra lại thông tin</h2>
            <ul>
                @foreach ($errors->getMessages() as $field => $messages)
                    @foreach ($messages as $message)
                        <li>
                            @if (isset($errorTargets[$field]))
                                <a href="#{{ $errorTargets[$field] }}">{{ $message }}</a>
                            @else
                                {{ $message }}
                            @endif
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </section>
    @endif

    @if ($quote)
        <section class="checkout-quote-status" aria-labelledby="quote-status-title">
            <p class="section-label">Đã tính lúc {{ $quote->quotedAt->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</p>
            <h2 id="quote-status-title">Bảng tính tạm thời</h2>
            <p>Đây chưa phải đơn hàng và chưa giữ tồn kho hoặc lượt dùng mã. Hệ thống sẽ xác minh lại toàn bộ dữ liệu khi bạn đặt hàng COD.</p>
        </section>
    @endif

    <div class="checkout-layout">
        <form class="checkout-form form-panel" method="POST" action="{{ route('checkout.quote') }}" novalidate>
            @csrf
            @include('checkout._request-key')
            <header class="checkout-section-heading">
                <p class="section-label">Người nhận</p>
                <h2>Thông tin giao hàng</h2>
                <p>Thông tin này chỉ dùng để tạo báo giá hiện tại và không ghi đè hồ sơ tài khoản.</p>
            </header>

            <div class="form-grid checkout-form-grid">
                <div class="field">
                    <label for="recipient_name">Tên người nhận <span class="required-hint">(bắt buộc)</span></label>
                    <input id="recipient_name" name="recipient_name" type="text" maxlength="255" autocomplete="name" value="{{ old('recipient_name', $form['recipient_name'] ?? '') }}" aria-describedby="recipient-name-message" @error('recipient_name') aria-invalid="true" @enderror required>
                    <p id="recipient-name-message" class="field-message @error('recipient_name') field-error @enderror">@error('recipient_name'){{ $message }}@else Có thể khác tên chủ tài khoản. @enderror</p>
                </div>

                <div class="field">
                    <label for="recipient_phone">Số điện thoại <span class="required-hint">(bắt buộc)</span></label>
                    <input id="recipient_phone" name="recipient_phone" type="tel" maxlength="20" autocomplete="tel" value="{{ old('recipient_phone', $form['recipient_phone'] ?? '') }}" aria-describedby="recipient-phone-message" @error('recipient_phone') aria-invalid="true" @enderror required>
                    <p id="recipient-phone-message" class="field-message @error('recipient_phone') field-error @enderror">@error('recipient_phone'){{ $message }}@else Số di động Việt Nam, có thể nhập +84. @enderror</p>
                </div>

                <div class="field field--full">
                    <label for="recipient_email">Email người nhận <span class="required-hint">(bắt buộc)</span></label>
                    <input id="recipient_email" name="recipient_email" type="email" maxlength="255" autocomplete="email" value="{{ old('recipient_email', $form['recipient_email'] ?? '') }}" aria-describedby="recipient-email-message" @error('recipient_email') aria-invalid="true" @enderror required>
                    <p id="recipient-email-message" class="field-message @error('recipient_email') field-error @enderror">@error('recipient_email'){{ $message }}@else Dùng cho thông tin giao dịch ở các slice sau. @enderror</p>
                </div>

                <div class="field">
                    <label for="province">Tỉnh/thành phố <span class="required-hint">(bắt buộc)</span></label>
                    <input id="province" name="province" type="text" maxlength="100" autocomplete="address-level1" value="{{ old('province', $form['province'] ?? '') }}" aria-describedby="province-message" @error('province') aria-invalid="true" @enderror required>
                    <p id="province-message" class="field-message @error('province') field-error @enderror">@error('province'){{ $message }}@else “Hà Nội” dùng mức phí nội thành; nơi khác dùng mức phí tỉnh/thành khác. @enderror</p>
                </div>

                <div class="field">
                    <label for="district">Quận/huyện <span class="required-hint">(bắt buộc)</span></label>
                    <input id="district" name="district" type="text" maxlength="100" autocomplete="address-level2" value="{{ old('district', $form['district'] ?? '') }}" aria-describedby="district-message" @error('district') aria-invalid="true" @enderror required>
                    <p id="district-message" class="field-message @error('district') field-error @enderror">@error('district'){{ $message }}@else Nhập đúng theo địa chỉ nhận hàng. @enderror</p>
                </div>

                <div class="field">
                    <label for="ward">Phường/xã <span class="required-hint">(bắt buộc)</span></label>
                    <input id="ward" name="ward" type="text" maxlength="100" autocomplete="address-level3" value="{{ old('ward', $form['ward'] ?? '') }}" aria-describedby="ward-message" @error('ward') aria-invalid="true" @enderror required>
                    <p id="ward-message" class="field-message @error('ward') field-error @enderror">@error('ward'){{ $message }}@else Nhập đúng theo địa chỉ nhận hàng. @enderror</p>
                </div>

                <div class="field">
                    <label for="address_line">Địa chỉ chi tiết <span class="required-hint">(bắt buộc)</span></label>
                    <input id="address_line" name="address_line" type="text" maxlength="500" autocomplete="street-address" value="{{ old('address_line', $form['address_line'] ?? '') }}" aria-describedby="address-line-message" @error('address_line') aria-invalid="true" @enderror required>
                    <p id="address-line-message" class="field-message @error('address_line') field-error @enderror">@error('address_line'){{ $message }}@else Số nhà, tên đường hoặc thông tin tòa nhà. @enderror</p>
                </div>

                <div class="field field--full">
                    <label for="coupon_code">Mã giảm giá <span class="required-hint">(không bắt buộc)</span></label>
                    <input id="coupon_code" name="coupon_code" type="text" maxlength="80" autocomplete="off" value="{{ old('coupon_code', $form['coupon_code'] ?? '') }}" aria-describedby="coupon-code-message" @error('coupon_code') aria-invalid="true" @enderror>
                    <p id="coupon-code-message" class="field-message @error('coupon_code') field-error @enderror">@error('coupon_code'){{ $message }}@else Báo giá đánh giá định nghĩa mã hiện tại nhưng chưa giữ hoặc tiêu thụ lượt dùng. @enderror</p>
                </div>
            </div>

            <div class="checkout-form-actions">
                <button class="button" type="submit">{{ $quote ? 'Tính lại báo giá' : 'Tạo bảng tính tạm thời' }}</button>
                <a class="button button--quiet" href="{{ route('cart.index') }}">Quay lại giỏ hàng</a>
            </div>
        </form>

        <aside class="checkout-summary" aria-labelledby="checkout-summary-title">
            <header class="checkout-section-heading">
                <p class="section-label">Giỏ hàng hiện tại</p>
                <h2 id="checkout-summary-title">Sản phẩm và thành tiền</h2>
            </header>

            <div class="checkout-summary-lines">
                @if ($quote)
                    @foreach ($quote->lines as $line)
                        <article class="checkout-summary-line">
                            <div>
                                <h3>{{ $line->productName }}</h3>
                                <p>SKU {{ $line->sku }} · {{ $line->quantity }} × {{ number_format($line->unitPriceVnd, 0, ',', '.') }} ₫</p>
                            </div>
                            <strong>{{ number_format($line->lineSubtotalVnd, 0, ',', '.') }} ₫</strong>
                        </article>
                    @endforeach
                @else
                    @foreach ($cart['items'] as $row)
                        <article class="checkout-summary-line">
                            <div>
                                <h3>{{ $row['product']->name }}</h3>
                                <p>SKU {{ $row['product']->sku }} · {{ $row['cartItem']->quantity }} × {{ number_format($row['unitPrice'], 0, ',', '.') }} ₫</p>
                                @unless ($row['isPurchasable'])
                                    <p class="checkout-summary-line__error">Dòng này cần được sửa trong giỏ hàng trước khi tạo báo giá.</p>
                                @endunless
                            </div>
                            <strong>{{ $row['subtotal'] === null ? 'Không thể tính' : number_format($row['subtotal'], 0, ',', '.').' ₫' }}</strong>
                        </article>
                    @endforeach
                @endif
            </div>

            <dl class="checkout-totals">
                <div>
                    <dt>Tạm tính</dt>
                    <dd>{{ $quote ? number_format($quote->cartSubtotalVnd, 0, ',', '.').' ₫' : ($cart['total_overflow'] ? 'Vượt giới hạn' : number_format($cart['total_vnd'], 0, ',', '.').' ₫') }}</dd>
                </div>
                <div>
                    <dt>Giảm sản phẩm</dt>
                    <dd>{{ $quote ? '− '.number_format($quote->productDiscountVnd, 0, ',', '.').' ₫' : 'Chưa tính' }}</dd>
                </div>
                <div>
                    <dt>Phí vận chuyển</dt>
                    <dd>{{ $quote ? number_format($quote->shippingFeeVnd, 0, ',', '.').' ₫' : 'Chưa tính' }}</dd>
                </div>
                <div>
                    <dt>Giảm vận chuyển</dt>
                    <dd>{{ $quote ? '− '.number_format($quote->shippingDiscountVnd, 0, ',', '.').' ₫' : 'Chưa tính' }}</dd>
                </div>
                @if ($quote?->coupon)
                    <div>
                        <dt>Mã {{ $quote->coupon->code }}</dt>
                        <dd>{{ $quote->coupon->type }} · {{ $quote->coupon->scope }}</dd>
                    </div>
                @endif
                <div class="checkout-totals__grand">
                    <dt>Tổng thanh toán</dt>
                    <dd>{{ $quote ? number_format($quote->grandTotalVnd, 0, ',', '.').' ₫' : 'Chờ báo giá' }}</dd>
                </div>
            </dl>

            @if ($quote)
                <p class="checkout-summary__note">{{ $quote->shipping->regionLabel }} · Phí sau ưu đãi {{ number_format($quote->shippingFeeAfterDiscountVnd, 0, ',', '.') }} ₫.</p>
                @include('checkout._cod-form')
            @else
                <p class="checkout-summary__note">Nhập thông tin nhận hàng để server xác định vùng phí và tạo bảng tính.</p>
            @endif
        </aside>
    </div>
</div>
@endsection
