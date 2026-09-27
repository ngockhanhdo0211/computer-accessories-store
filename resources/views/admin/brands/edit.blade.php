@extends('layouts.workspace')
@section('title', 'Sửa thương hiệu')
@section('content')
<div class="shell admin-page admin-form-page">
    <header class="page-intro">
        <p class="eyebrow">Quản trị / Thương hiệu</p>
        <h1>Sửa {{ $brand->name }}</h1>
        <p>Slug chỉ thay đổi khi bạn chủ động nhập giá trị mới.</p>
    </header>
    <form class="form-panel" method="POST" action="{{ route('admin.brands.update', $brand) }}" novalidate>
        <h2>Thông tin thương hiệu</h2>
        @include('admin.brands._form')
    </form>
</div>
@endsection
