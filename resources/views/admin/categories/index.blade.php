@extends('layouts.storefront')
@section('title', 'Danh mục')
@section('content')
<div class="shell admin-page">
    <header class="admin-heading">
        <div><p class="eyebrow">Catalog · Category</p><h1>Quản lý danh mục</h1><p>Danh mục động theo cấu trúc cha–con. Brand và Product sẽ được triển khai ở giai đoạn sau.</p></div>
        <a class="button" href="{{ route('admin.categories.create') }}">Tạo danh mục</a>
    </header>
    @if ($errors->has('category'))
        <div class="alert alert--error" role="alert">{{ $errors->first('category') }}</div>
    @endif
    @if ($categories->isEmpty())
        <section class="admin-empty" aria-labelledby="empty-title">
            <h2 id="empty-title">Chưa có danh mục</h2>
            <p>Tạo danh mục gốc đầu tiên để bắt đầu tổ chức catalog.</p>
            <a class="button button--outline" href="{{ route('admin.categories.create') }}">Tạo danh mục đầu tiên</a>
        </section>
    @else
        <div class="category-list" role="list">
            @foreach ($categories as $category)
                <article class="category-row" role="listitem">
                    <div class="category-main">
                        <div class="category-title-line">
                            <h2>{{ $category->name }}</h2>
                            <span class="status-tag {{ $category->is_visible ? 'status-tag--visible' : '' }}">{{ $category->is_visible ? 'Đang hiển thị' : 'Đang ẩn' }}</span>
                        </div>
                        <p><code>{{ $category->slug }}</code> · Cha: {{ $category->parent?->name ?? 'Danh mục gốc' }} · {{ $category->children_count }} danh mục con</p>
                    </div>
                    <div class="row-actions">
                        <a class="button button--quiet" href="{{ route('admin.categories.edit', $category) }}">Sửa</a>
                        <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" data-confirm-delete>
                            @csrf @method('DELETE')
                            <button class="button button--danger" type="submit">Xóa</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
        {{ $categories->links() }}
    @endif
</div>
@endsection