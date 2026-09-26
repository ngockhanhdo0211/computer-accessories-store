@extends('layouts.storefront')
@section('title', 'Sửa sản phẩm')
@section('content')
<div class="shell admin-page">
    <header class="page-intro">
        <p class="eyebrow">Quản trị / Sản phẩm</p>
        <h1>Sửa {{ $product->name }}</h1>
        <p>SKU và slug chỉ thay đổi khi bạn chủ động nhập giá trị mới. Tồn kho không chỉnh trực tiếp tại đây.</p>
    </header>

    <form method="POST" action="{{ route('admin.products.update', $product) }}" enctype="multipart/form-data" novalidate>
        @include('admin.products._form')
    </form>

    @if ($product->images->isNotEmpty())
        <section class="product-image-manager" aria-labelledby="image-manager-title">
            <header>
                <p class="eyebrow">Thư viện ảnh</p>
                <h2 id="image-manager-title">Sắp xếp và chọn ảnh đại diện</h2>
                <p>Dùng nút lên/xuống để đổi thứ tự. Chỉ một ảnh được chọn làm đại diện.</p>
            </header>
            <form method="POST" action="{{ route('admin.products.images.update', $product) }}">
                @csrf @method('PATCH')
                <ol class="product-image-manager__list" data-image-sort-list>
                    @foreach ($product->images as $image)
                        <li data-image-sort-item>
                            <input type="hidden" name="ordered_image_ids[]" value="{{ $image->id }}">
                            <div class="product-image-frame">
                                <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?: $product->name }}" data-image-fallback>
                                <span class="product-image-placeholder" hidden>Không tải được ảnh</span>
                            </div>
                            <div class="product-image-manager__meta">
                                <strong>{{ $image->alt_text ?: 'Chưa có mô tả ảnh' }}</strong>
                                <label>
                                    <input type="radio" name="primary_image_id" value="{{ $image->id }}" @checked($image->is_primary)>
                                    Ảnh đại diện
                                </label>
                            </div>
                            <div class="product-image-manager__actions">
                                <button class="button button--quiet" type="button" data-image-move="up" aria-label="Đưa ảnh lên trước">Lên</button>
                                <button class="button button--quiet" type="button" data-image-move="down" aria-label="Đưa ảnh xuống sau">Xuống</button>
                                <button class="button button--danger" type="submit" form="delete-image-{{ $image->id }}">Xóa</button>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <button class="button" type="submit">Lưu thứ tự ảnh</button>
            </form>
            @foreach ($product->images as $image)
                <form id="delete-image-{{ $image->id }}" method="POST" action="{{ route('admin.products.images.destroy', [$product, $image]) }}" data-confirm-delete data-confirm-delete-message="Xóa ảnh sản phẩm này?">
                    @csrf @method('DELETE')
                </form>
            @endforeach
        </section>
    @endif

    <section class="product-danger-zone" aria-labelledby="delete-product-title">
        <h2 id="delete-product-title">Xóa sản phẩm chưa phát sinh nghiệp vụ</h2>
        <p>Sản phẩm đã được đơn hàng hoặc giao dịch kho tham chiếu sẽ không thể xóa; hãy chuyển sang trạng thái ẩn.</p>
        <form method="POST" action="{{ route('admin.products.destroy', $product) }}" data-confirm-delete data-confirm-delete-message="Xóa sản phẩm và toàn bộ ảnh của sản phẩm này?">
            @csrf @method('DELETE')
            <button class="button button--danger" type="submit">Xóa sản phẩm</button>
        </form>
    </section>
</div>
@endsection