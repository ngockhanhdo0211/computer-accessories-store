@extends('layouts.storefront')
@section('title', 'Sửa danh mục')
@section('content')
<div class="shell admin-page admin-form-page">
    <header class="page-intro"><p class="eyebrow">Quản trị / Danh mục</p><h1>Sửa {{ $category->name }}</h1><p>Slug chỉ thay đổi khi bạn sửa trực tiếp để tránh đổi URL ngoài ý muốn.</p></header>
    <form class="form-panel" method="POST" action="{{ route('admin.categories.update', $category) }}" novalidate>
        <h2>Thông tin danh mục</h2>
        @include('admin.categories._form')
    </form>
</div>
@endsection