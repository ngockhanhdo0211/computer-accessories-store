@extends('layouts.workspace')
@section('title', 'Tạo sản phẩm')
@section('content')
<div class="shell admin-page">
    <header class="page-intro">
        <p class="eyebrow">Hàng hóa / Sản phẩm</p>
        <h1>Tạo sản phẩm</h1>
        <p>Thêm thông tin bán hàng và hình ảnh sản phẩm. Số lượng được quản lý riêng trong khu vực Tồn kho.</p>
    </header>
    @if ($categories->isEmpty() || $brands->isEmpty())
        <div class="alert alert--error" role="alert">Cần có ít nhất một danh mục và một thương hiệu trước khi tạo sản phẩm.</div>
    @endif
    <form method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data" novalidate>
        @include('admin.products._form')
    </form>
</div>
@endsection
