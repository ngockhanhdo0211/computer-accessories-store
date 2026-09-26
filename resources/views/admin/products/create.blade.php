@extends('layouts.storefront')
@section('title', 'Tạo sản phẩm')
@section('content')
<div class="shell admin-page">
    <header class="page-intro">
        <p class="eyebrow">Quản trị / Sản phẩm</p>
        <h1>Tạo sản phẩm</h1>
        <p>Thêm thông tin catalog và ảnh. Số lượng kho được quản lý bởi quy trình kho ở giai đoạn sau.</p>
    </header>
    @if ($categories->isEmpty() || $brands->isEmpty())
        <div class="alert alert--error" role="alert">Cần có ít nhất một danh mục và một thương hiệu trước khi tạo sản phẩm.</div>
    @endif
    <form method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data" novalidate>
        @include('admin.products._form')
    </form>
</div>
@endsection