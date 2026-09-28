@extends('layouts.workspace')
@section('title', 'Danh mục')
@section('content')
<div class="shell admin-page category-workbench">
    <header class="category-index-header">
        <div class="category-index-header__copy">
            <h1>Danh mục sản phẩm</h1>
            <p>Quản lý tên, đường dẫn, quan hệ cha–con và trạng thái hiển thị trong catalog.</p>
        </div>
        <div class="category-index-header__tools">
            <p class="category-index-count" aria-label="Tổng số danh mục">
                <strong>{{ $categories->total() }}</strong>
                <span>danh mục</span>
            </p>
            <a class="button category-index-header__action" href="{{ route('admin.categories.create') }}">Tạo danh mục</a>
        </div>
    </header>

    @if ($errors->has('category'))
        <div class="alert alert--error" role="alert">{{ $errors->first('category') }}</div>
    @endif

    @if ($categories->isEmpty())
        <section class="empty-state category-empty-state" aria-labelledby="empty-title">
            <p class="category-kicker">Catalog trống</p>
            <h2 id="empty-title">Chưa có danh mục</h2>
            <p>Tạo danh mục gốc đầu tiên để bắt đầu tổ chức catalog.</p>
            <a class="button" href="{{ route('admin.categories.create') }}">Tạo danh mục đầu tiên</a>
        </section>
    @else
        <section class="category-catalog" aria-labelledby="category-list-title">
            <header class="category-catalog__heading">
                <h2 id="category-list-title">Danh sách danh mục</h2>
                <span>Trang {{ $categories->currentPage() }} / {{ $categories->lastPage() }}</span>
            </header>

            <div class="category-catalog__columns" aria-hidden="true">
                <span>Danh mục</span>
                <span>Danh mục cha</span>
                <span>Nhánh con</span>
                <span>Trạng thái</span>
                <span>Thao tác</span>
            </div>

            <div class="category-catalog__rows" role="list">
                @foreach ($categories as $category)
                    <article class="category-catalog__row" role="listitem">
                        <div class="category-catalog__identity">
                            <h3>{{ $category->name }}</h3>
                            <code>{{ $category->slug }}</code>
                        </div>
                        <div class="category-catalog__cell">
                            <span class="category-catalog__cell-label">Danh mục cha</span>
                            <strong>{{ $category->parent?->name ?? 'Danh mục gốc' }}</strong>
                        </div>
                        <div class="category-catalog__cell category-catalog__children">
                            <span class="category-catalog__cell-label">Nhánh con</span>
                            <strong>{{ $category->children_count }}</strong>
                            <span>danh mục</span>
                        </div>
                        <div class="category-catalog__cell">
                            <span class="category-catalog__cell-label">Hiển thị</span>
                            <span class="status-badge {{ $category->is_visible ? 'status-badge--success' : 'status-badge--neutral' }}">{{ $category->is_visible ? 'Đang hiển thị' : 'Đang ẩn' }}</span>
                        </div>
                        <div class="action-group category-catalog__actions">
                            <a class="button button--quiet" href="{{ route('admin.categories.edit', $category) }}">Sửa</a>
                            <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" data-confirm-delete>
                                @csrf
                                @method('DELETE')
                                <button class="button button--danger" type="submit">Xóa</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>

            {{ $categories->links() }}
        </section>
    @endif
</div>
@endsection
