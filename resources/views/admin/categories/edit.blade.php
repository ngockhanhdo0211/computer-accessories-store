@extends('layouts.workspace')
@section('title', 'Sửa danh mục')
@section('content')
<div class="shell admin-page category-form-page">
    <header class="category-form-masthead">
        <div>
            <p class="category-kicker">Catalog / Chỉnh sửa</p>
            <h1>{{ $category->name }}</h1>
            <p>Cập nhật định danh, vị trí trong cây danh mục và trạng thái hiển thị.</p>
        </div>
        <dl class="category-form-masthead__details">
            <div><dt>Slug hiện tại</dt><dd>{{ $category->slug }}</dd></div>
            <div><dt>Thuộc danh mục</dt><dd>{{ $category->parent?->name ?? 'Danh mục gốc' }}</dd></div>
        </dl>
    </header>

    <div class="category-form-layout">
        <form class="category-editor" method="POST" action="{{ route('admin.categories.update', $category) }}" novalidate>
            @include('admin.categories._form')
        </form>
        <aside class="category-form-guide" aria-labelledby="category-guide-title">
            <p class="category-kicker">Phạm vi thay đổi</p>
            <h2 id="category-guide-title">Giữ cấu trúc ổn định</h2>
            <ul>
                <li><strong>Đổi tên</strong><span>Không tự động đổi slug hiện tại.</span></li>
                <li><strong>Đổi cấp cha</strong><span>Không thể chọn chính nó hoặc một nhánh con.</span></li>
                <li><strong>Chuyển sang ẩn</strong><span>Sản phẩm liên quan sẽ không xuất hiện công khai.</span></li>
            </ul>
        </aside>
    </div>
</div>
@endsection
