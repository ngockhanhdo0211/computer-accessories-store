@extends('layouts.workspace')
@section('title', 'Thương hiệu')
@section('content')
<div class="shell admin-page">
    <header class="admin-heading">
        <div>
            <p class="eyebrow">Quản trị / Thương hiệu</p>
            <h1>Quản lý thương hiệu</h1>
            <p>{{ $brands->total() }} thương hiệu trong catalog. Sản phẩm sẽ được bổ sung ở giai đoạn sau.</p>
        </div>
        <a class="button" href="{{ route('admin.brands.create') }}">Tạo thương hiệu</a>
    </header>
    @if ($errors->has('brand'))
        <div class="alert alert--error" role="alert">{{ $errors->first('brand') }}</div>
    @endif
    @if ($brands->isEmpty())
        <section class="admin-empty" aria-labelledby="empty-title">
            <h2 id="empty-title">Chưa có thương hiệu</h2>
            <p>Tạo thương hiệu đầu tiên để chuẩn bị dữ liệu catalog.</p>
            <a class="button button--outline" href="{{ route('admin.brands.create') }}">Tạo thương hiệu đầu tiên</a>
        </section>
    @else
        <div class="brand-list" role="list">
            @foreach ($brands as $brand)
                <article class="brand-row" role="listitem">
                    <div class="brand-main">
                        <div class="brand-title-line">
                            <h2>{{ $brand->name }}</h2>
                            <span class="status-tag {{ $brand->is_visible ? 'status-tag--visible' : '' }}">{{ $brand->is_visible ? 'Đang hiển thị' : 'Đang ẩn' }}</span>
                        </div>
                        <p><code>{{ $brand->slug }}</code></p>
                    </div>
                    <div class="row-actions">
                        <a class="button button--quiet" href="{{ route('admin.brands.edit', $brand) }}">Sửa</a>
                        <form method="POST" action="{{ route('admin.brands.destroy', $brand) }}" data-confirm-delete data-confirm-delete-message="Xóa thương hiệu này? Thao tác chỉ thành công khi thương hiệu chưa được sử dụng.">
                            @csrf @method('DELETE')
                            <button class="button button--danger" type="submit">Xóa</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
        {{ $brands->links() }}
    @endif
</div>
@endsection
