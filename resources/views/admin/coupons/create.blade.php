@extends('layouts.workspace')
@section('title', 'Tạo mã giảm giá')
@section('content')
<div class="shell admin-page admin-form-page coupon-page">
    <div class="page-heading"><div><p class="eyebrow">Khuyến mãi</p><h1>Tạo mã giảm giá</h1><p>Định nghĩa quy tắc tĩnh. Lượt sử dụng và áp mã tại checkout chưa thuộc slice này.</p></div><a class="text-link" href="{{ route('admin.coupons.index') }}">Quay lại danh sách</a></div>
    <form class="form-panel" method="POST" action="{{ route('admin.coupons.store') }}" novalidate>
        @include('admin.coupons._form')
        <div class="form-actions"><button class="button" type="submit">Tạo mã giảm giá</button></div>
    </form>
</div>
@endsection