@extends('layouts.workspace')
@section('title', 'Sửa mã '.$coupon->code)
@section('content')
<div class="shell admin-page admin-form-page coupon-page">
    <div class="page-heading"><div><p class="eyebrow">Khuyến mãi</p><h1>Sửa {{ $coupon->code }}</h1><p>Cập nhật định nghĩa và mục tiêu trong cùng một transaction có audit.</p></div><a class="text-link" href="{{ route('admin.coupons.index') }}">Quay lại danh sách</a></div>
    <form class="form-panel" method="POST" action="{{ route('admin.coupons.update', $coupon) }}" novalidate>
        @method('PUT')
        @include('admin.coupons._form')
        <div class="form-actions"><button class="button" type="submit">Lưu thay đổi</button></div>
    </form>
</div>
@endsection