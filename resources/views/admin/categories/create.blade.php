@extends('layouts.workspace')
@section('title', 'Tạo danh mục')
@section('content')
<div class="shell admin-page category-form-page">
    <header class="category-form-masthead">
        <div>
            <p class="category-kicker">Catalog / Danh mục mới</p>
            <h1>Tạo danh mục</h1>
            <p>Định danh nhóm sản phẩm, đặt vị trí trong cấu trúc và chọn trạng thái hiển thị.</p>
        </div>
        <div class="category-form-masthead__meta">
            <span>Loại bản ghi</span>
            <strong>Danh mục sản phẩm</strong>
        </div>
    </header>

    <div class="category-form-layout">
        <form class="category-editor" method="POST" action="{{ route('admin.categories.store') }}" novalidate>
            @include('admin.categories._form')
        </form>
        <aside class="category-form-guide" aria-labelledby="category-guide-title">
            <p class="category-kicker">Trước khi lưu</p>
            <h2 id="category-guide-title">Đặt đúng vị trí</h2>
            <ul>
                <li><strong>Danh mục gốc</strong><span>Dùng cho một nhóm phụ kiện cấp cao nhất.</span></li>
                <li><strong>Danh mục con</strong><span>Chọn cấp cha để thể hiện đúng cấu trúc catalog.</span></li>
                <li><strong>Trạng thái ẩn</strong><span>Sản phẩm liên quan sẽ không xuất hiện công khai.</span></li>
            </ul>
        </aside>
    </div>
</div>
@endsection
