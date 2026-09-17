@extends('layouts.storefront')
@section('title', 'Tạo danh mục')
@section('content')
<div class="shell admin-page admin-form-page">
    <header class="page-intro"><p class="eyebrow">Catalog · Category</p><h1>Tạo danh mục</h1><p>Tạo danh mục gốc hoặc đặt dưới một danh mục cha hiện có.</p></header>
    <form class="form-panel" method="POST" action="{{ route('admin.categories.store') }}" novalidate>
        <h2>Thông tin danh mục</h2>
        @include('admin.categories._form')
    </form>
</div>
@endsection