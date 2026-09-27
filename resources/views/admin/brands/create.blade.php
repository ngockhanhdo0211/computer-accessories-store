@extends('layouts.workspace')
@section('title', 'Tạo thương hiệu')
@section('content')
<div class="shell admin-page admin-form-page">
    <header class="page-intro">
        <p class="eyebrow">Quản trị / Thương hiệu</p>
        <h1>Tạo thương hiệu</h1>
        <p>Thêm thương hiệu để chuẩn bị liên kết sản phẩm trong catalog.</p>
    </header>
    <form class="form-panel" method="POST" action="{{ route('admin.brands.store') }}" novalidate>
        <h2>Thông tin thương hiệu</h2>
        @include('admin.brands._form')
    </form>
</div>
@endsection
