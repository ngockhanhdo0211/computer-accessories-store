@extends('layouts.workspace')

@section('title', 'Mã giảm giá')

@section('content')
<div class="shell admin-page coupon-page">
    <header class="page-heading coupon-page-heading">
        <div>
            <p class="eyebrow">Khuyến mãi</p>
            <h1>Mã giảm giá</h1>
            <p>Quản lý điều kiện, phạm vi và thời gian hiệu lực của các mã giảm giá.</p>
        </div>
    </header>

    <div class="coupon-toolbar" aria-label="Tác vụ danh sách mã giảm giá">
        <p class="coupon-count"><strong>{{ $coupons->total() }}</strong> mã giảm giá</p>
        <a class="button coupon-create-button" href="{{ route('admin.coupons.create') }}">Tạo mã giảm giá</a>
    </div>

    @if($coupons->isEmpty())
        <section class="empty-state coupon-empty-state" aria-labelledby="coupon-empty-title">
            <p class="eyebrow">Chưa có dữ liệu</p>
            <h2 id="coupon-empty-title">Chưa có mã giảm giá</h2>
            <p>Tạo mã đầu tiên khi đã xác định ưu đãi, phạm vi và thời gian áp dụng.</p>
            <a class="button" href="{{ route('admin.coupons.create') }}">Tạo mã giảm giá</a>
        </section>
    @else
        <div class="coupon-table-wrap">
            <table class="coupon-table">
                <thead>
                    <tr>
                        <th scope="col">Mã giảm giá</th>
                        <th scope="col">Ưu đãi</th>
                        <th scope="col">Phạm vi</th>
                        <th scope="col">Điều kiện</th>
                        <th scope="col">Hiệu lực</th>
                        <th scope="col">Trạng thái</th>
                        <th scope="col">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($coupons as $coupon)
                        @php
                            $targetCount = match($coupon->scope) {
                                \App\Enums\CouponScope::Product => $coupon->products_count,
                                \App\Enums\CouponScope::Category => $coupon->categories_count,
                                \App\Enums\CouponScope::Brand => $coupon->brands_count,
                                default => 0,
                            };
                            $targetLabel = match($coupon->scope) {
                                \App\Enums\CouponScope::Product => 'sản phẩm',
                                \App\Enums\CouponScope::Category => 'danh mục',
                                \App\Enums\CouponScope::Brand => 'thương hiệu',
                                default => null,
                            };
                            $now = now('UTC');
                            [$statusLabel, $statusClass] = match(true) {
                                ! $coupon->is_active => ['Đã tắt', 'is-muted'],
                                $now->lt($coupon->starts_at) => ['Chưa bắt đầu', 'is-upcoming'],
                                $now->gt($coupon->ends_at) => ['Đã hết hạn', 'is-expired'],
                                default => ['Đang hoạt động', 'is-active'],
                            };
                        @endphp
                        <tr>
                            <td data-label="Mã giảm giá">
                                <strong class="coupon-code">{{ $coupon->code }}</strong>
                                <small class="coupon-cell-note">{{ $coupon->type->label() }}</small>
                            </td>
                            <td data-label="Ưu đãi">
                                <strong class="coupon-benefit">
                                    @if($coupon->type === \App\Enums\CouponType::Percent)
                                        {{ $coupon->value }}%
                                    @elseif($coupon->type === \App\Enums\CouponType::Fixed)
                                        {{ number_format($coupon->value, 0, ',', '.') }} ₫
                                    @else
                                        Miễn phí vận chuyển
                                    @endif
                                </strong>
                            </td>
                            <td data-label="Phạm vi">
                                <span>{{ $coupon->scope->label() }}</span>
                                @if($targetCount > 0)
                                    <small class="coupon-cell-note">{{ $targetCount }} {{ $targetLabel }} đã liên kết</small>
                                @endif
                            </td>
                            <td data-label="Điều kiện">
                                <dl class="coupon-conditions">
                                    <div>
                                        <dt>Đơn tối thiểu</dt>
                                        <dd>{{ $coupon->min_subtotal_vnd > 0 ? number_format($coupon->min_subtotal_vnd, 0, ',', '.').' ₫' : 'Không giới hạn' }}</dd>
                                    </div>
                                    <div>
                                        <dt>Hạng</dt>
                                        <dd>{{ $coupon->required_tier?->label() ?? 'Mọi hạng' }}</dd>
                                    </div>
                                    <div>
                                        <dt>Giới hạn</dt>
                                        <dd>{{ $coupon->max_uses ? number_format($coupon->max_uses, 0, ',', '.') : 'Không giới hạn' }} tổng · {{ $coupon->max_uses_per_user ? number_format($coupon->max_uses_per_user, 0, ',', '.') : 'Không giới hạn' }} / khách</dd>
                                    </div>
                                </dl>
                            </td>
                            <td data-label="Hiệu lực">
                                <dl class="coupon-validity">
                                    <div>
                                        <dt>Bắt đầu</dt>
                                        <dd><time datetime="{{ $coupon->starts_at->toIso8601String() }}">{{ $coupon->starts_at->timezone('UTC')->format('d/m/Y H:i') }} UTC</time></dd>
                                    </div>
                                    <div>
                                        <dt>Kết thúc</dt>
                                        <dd><time datetime="{{ $coupon->ends_at->toIso8601String() }}">{{ $coupon->ends_at->timezone('UTC')->format('d/m/Y H:i') }} UTC</time></dd>
                                    </div>
                                </dl>
                            </td>
                            <td data-label="Trạng thái">
                                <span class="status-badge coupon-status {{ $statusClass }}">{{ $statusLabel }}</span>
                            </td>
                            <td data-label="Thao tác">
                                <div class="table-actions coupon-actions">
                                    <a class="text-link" href="{{ route('admin.coupons.edit', $coupon) }}">Sửa</a>
                                    <form method="POST" action="{{ route('admin.coupons.destroy', $coupon) }}" data-confirm-delete data-confirm-delete-message="Chỉ xóa mã chưa từng được sử dụng. Tiếp tục?">
                                        @csrf
                                        @method('DELETE')
                                        <button class="link-button coupon-delete-action" type="submit">Xóa</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $coupons->links() }}
    @endif
</div>
@endsection
