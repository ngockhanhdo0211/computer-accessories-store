@extends('layouts.workspace')
@section('title', 'Thương hiệu')
@section('content')
<div class="shell admin-page resource-page">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Nhận diện catalog</p>
            <h1>Thương hiệu</h1>
            <p>Quản lý tên, slug và khả năng hiển thị của các thương hiệu trong catalog.</p>
        </div>
        <a class="button" href="{{ route('admin.brands.create') }}">Tạo thương hiệu</a>
    </header>

    @if ($errors->has('brand'))
        <div class="alert alert--error" role="alert">{{ $errors->first('brand') }}</div>
    @endif

    @if ($brands->isEmpty())
        <section class="empty-state resource-empty-state" aria-labelledby="empty-title">
            <p class="eyebrow">0 thương hiệu</p>
            <h2 id="empty-title">Chưa có thương hiệu</h2>
            <p>Tạo thương hiệu đầu tiên để chuẩn bị dữ liệu catalog.</p>
            <a class="button button--outline" href="{{ route('admin.brands.create') }}">Tạo thương hiệu đầu tiên</a>
        </section>
    @else
        <section class="resource-ledger" aria-labelledby="brand-list-title">
            <header class="resource-ledger__heading">
                <div>
                    <p class="section-label">Dữ liệu hiện tại</p>
                    <h2 id="brand-list-title">Danh sách thương hiệu</h2>
                </div>
                <p><strong>{{ $brands->total() }}</strong> thương hiệu</p>
            </header>
            <div class="resource-list resource-list--brand" role="list">
                <div class="resource-list__columns" aria-hidden="true">
                    <span>Thương hiệu</span>
                    <span>Trạng thái</span>
                    <span>Thao tác</span>
                </div>
                @foreach ($brands as $brand)
                    <article class="resource-row" role="listitem">
                        <div class="resource-row__primary">
                            <h3>{{ $brand->name }}</h3>
                            <code>{{ $brand->slug }}</code>
                        </div>
                        <div class="resource-cell">
                            <span class="resource-cell__label">Trạng thái</span>
                            <span class="status-badge {{ $brand->is_visible ? 'status-badge--success' : 'status-badge--neutral' }}">{{ $brand->is_visible ? 'Đang hiển thị' : 'Đang ẩn' }}</span>
                        </div>
                        <div class="action-group resource-row__actions">
                            <a class="button button--quiet" href="{{ route('admin.brands.edit', $brand) }}">Sửa</a>
                            <form method="POST" action="{{ route('admin.brands.destroy', $brand) }}" data-confirm-delete data-confirm-delete-message="Xóa thương hiệu này? Thao tác chỉ thành công khi thương hiệu chưa được sử dụng.">
                                @csrf
                                @method('DELETE')
                                <button class="button button--danger" type="submit">Xóa</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>
            {{ $brands->links() }}
        </section>
    @endif
</div>
@endsection
