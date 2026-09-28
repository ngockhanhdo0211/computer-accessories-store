@extends('layouts.workspace')
@section('title', 'Tạo thương hiệu')
@section('content')
<div class="shell admin-page resource-form-page">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Nhận diện catalog</p>
            <h1>Tạo thương hiệu</h1>
            <p>Thêm một thương hiệu và chọn trạng thái hiển thị phù hợp cho catalog.</p>
        </div>
    </header>
    <div class="resource-form-layout">
        <form class="form-panel resource-form" method="POST" action="{{ route('admin.brands.store') }}" novalidate>
            <header class="resource-form__heading">
                <p class="section-label">Thương hiệu mới</p>
                <h2>Thông tin thương hiệu</h2>
                <p>Các trường có ghi “bắt buộc” cần được hoàn thành trước khi lưu.</p>
            </header>
            @include('admin.brands._form')
        </form>
        <aside class="resource-form-guide" aria-labelledby="brand-guide-title">
            <p class="section-label">Quy ước catalog</p>
            <h2 id="brand-guide-title">Tên và đường dẫn</h2>
            <ul>
                <li>Dùng tên thương hiệu mà khách hàng dễ nhận biết.</li>
                <li>Slug được sinh từ tên khi bạn không nhập.</li>
                <li>Thương hiệu ẩn làm sản phẩm liên quan không hiển thị công khai.</li>
            </ul>
        </aside>
    </div>
</div>
@endsection
