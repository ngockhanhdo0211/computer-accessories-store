@extends('layouts.workspace')
@section('title', 'Sửa thương hiệu')
@section('content')
<div class="shell admin-page resource-form-page">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Nhận diện catalog</p>
            <h1>Sửa {{ $brand->name }}</h1>
            <p>Cập nhật tên, slug và trạng thái hiển thị của thương hiệu.</p>
        </div>
    </header>
    <div class="resource-form-layout">
        <form class="form-panel resource-form" method="POST" action="{{ route('admin.brands.update', $brand) }}" novalidate>
            <header class="resource-form__heading">
                <p class="section-label">Thương hiệu hiện có</p>
                <h2>Thông tin thương hiệu</h2>
                <p>Slug chỉ thay đổi khi bạn chủ động nhập một giá trị mới.</p>
            </header>
            @include('admin.brands._form')
        </form>
        <aside class="resource-form-guide" aria-labelledby="brand-guide-title">
            <p class="section-label">Dữ liệu hiện tại</p>
            <h2 id="brand-guide-title">{{ $brand->name }}</h2>
            <dl class="resource-summary">
                <div><dt>Slug</dt><dd>{{ $brand->slug }}</dd></div>
                <div><dt>Trạng thái</dt><dd>{{ $brand->is_visible ? 'Đang hiển thị' : 'Đang ẩn' }}</dd></div>
            </dl>
            <p>Để trống trường slug nếu bạn muốn giữ nguyên đường dẫn hiện tại.</p>
        </aside>
    </div>
</div>
@endsection
