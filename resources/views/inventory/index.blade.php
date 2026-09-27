@extends('layouts.storefront')

@section('title', 'Quản lý tồn kho')

@section('content')
<div class="shell inventory-page">
    <header class="inventory-header">
        <div><p class="eyebrow">Vận hành / Một kho</p><h1>Quản lý tồn kho</h1><p>Projection hiện tại và đường dẫn tới sổ giao dịch bất biến của từng sản phẩm.</p></div>
        <a class="button button--outline" href="{{ route('inventory.adjustments.index') }}">Đề nghị điều chỉnh</a>
    </header>

    <form class="catalog-filter inventory-filter" method="GET" action="{{ route('inventory.index') }}">
        <div class="field"><label for="search">Tên hoặc SKU</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" autocomplete="off"></div>
        <div class="field"><label for="stock">Tình trạng kho</label><select id="stock" name="stock"><option value="">Tất cả</option><option value="in_stock" @selected(($filters['stock'] ?? '') === 'in_stock')>Còn hàng</option><option value="low" @selected(($filters['stock'] ?? '') === 'low')>Sắp hết</option><option value="out" @selected(($filters['stock'] ?? '') === 'out')>Hết hàng</option></select></div>
        <div class="field"><label for="sort">Sắp xếp</label><select id="sort" name="sort"><option value="name" @selected($filters['sort'] === 'name')>Tên A–Z</option><option value="stock_asc" @selected($filters['sort'] === 'stock_asc')>Tồn tăng dần</option><option value="stock_desc" @selected($filters['sort'] === 'stock_desc')>Tồn giảm dần</option></select></div>
        <div class="catalog-filter__actions"><button class="button" type="submit">Lọc dữ liệu</button><a class="text-link" href="{{ route('inventory.index') }}">Đặt lại</a></div>
    </form>

    @if($products->isEmpty())
        <section class="catalog-empty"><h2>{{ request()->hasAny(['search','stock']) ? 'Không có kết quả phù hợp' : 'Chưa có sản phẩm' }}</h2><p>Điều chỉnh bộ lọc hoặc tạo Product trước khi vận hành kho.</p></section>
    @else
        <div class="inventory-table-wrap"><table class="inventory-table"><thead><tr><th>Sản phẩm</th><th>Tồn bán được</th><th>Hàng hỏng</th><th>Đã giao</th><th>Tình trạng</th><th>Thao tác</th></tr></thead><tbody>
        @foreach($products as $product)
            @php($stockState = $product->sellable_quantity === 0 ? 'out' : ($product->sellable_quantity <= $product->low_stock_threshold ? 'low' : 'in'))
            <tr>
                <td data-label="Sản phẩm"><div class="inventory-product">@if($product->primaryImage)<img src="{{ $product->primaryImage->url() }}" alt="{{ $product->primaryImage->alt_text ?? $product->name }}">@else<span class="inventory-product__placeholder">Chưa có ảnh</span>@endif<div><strong>{{ $product->name }}</strong><span>{{ $product->sku }} · Ngưỡng {{ $product->low_stock_threshold }}</span></div></div></td>
                <td data-label="Tồn bán được"><strong>{{ number_format($product->sellable_quantity, 0, ',', '.') }}</strong></td>
                <td data-label="Hàng hỏng">{{ number_format($product->damaged_quantity, 0, ',', '.') }}</td>
                <td data-label="Đã giao">{{ number_format($product->sold_quantity, 0, ',', '.') }}</td>
                <td data-label="Tình trạng"><span class="stock-badge stock-badge--{{ $stockState }}">{{ $stockState === 'out' ? 'Hết hàng' : ($stockState === 'low' ? 'Sắp hết' : 'Còn hàng') }}</span></td>
                <td data-label="Thao tác"><div class="inventory-actions"><a href="{{ route('inventory.history', $product) }}">Lịch sử</a><a href="{{ route('inventory.import.form', $product) }}">Nhập kho</a><a href="{{ route('inventory.damaged.form', $product) }}">Hàng hỏng</a><a href="{{ route('inventory.adjustments.create', $product) }}">Đề nghị</a>@if(auth()->user()->isAdmin())<a href="{{ route('inventory.adjustments.direct.form', $product) }}">Điều chỉnh</a>@endif</div></td>
            </tr>
        @endforeach
        </tbody></table></div>
        <div class="pagination-wrap">{{ $products->links() }}</div>
    @endif
</div>
@endsection
