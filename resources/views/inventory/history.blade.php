@extends('layouts.workspace')

@section('title', 'Lịch sử tồn kho')

@section('content')
<div class="shell inventory-page">
    <nav class="breadcrumbs" aria-label="Đường dẫn"><a href="{{ route('inventory.index') }}">Tồn kho</a><span aria-hidden="true">/</span><span>{{ $product->name }}</span></nav>
    <header class="inventory-header"><div><p class="eyebrow">Lịch sử kho / {{ $product->sku }}</p><h1>{{ $product->name }}</h1><p>Các biến động được lưu theo thời gian. Nếu cần sửa sai, hãy tạo một điều chỉnh mới.</p></div><div class="inventory-balances"><span>Bán được <strong>{{ $product->sellable_quantity }}</strong></span><span>Hỏng <strong>{{ $product->damaged_quantity }}</strong></span><span>Đã giao <strong>{{ $product->sold_quantity }}</strong></span></div></header>
    <div class="inventory-toolbar"><a class="button" href="{{ route('inventory.import.form', $product) }}">Nhập kho</a><a class="button button--outline" href="{{ route('inventory.damaged.form', $product) }}">Ghi nhận hàng hỏng</a><a class="text-link" href="{{ route('inventory.adjustments.create', $product) }}">Tạo đề nghị điều chỉnh</a></div>
    @if($transactions->isEmpty())
        <section class="catalog-empty"><h2>Chưa có biến động tồn kho</h2><p>Các lần nhập kho, ghi nhận hàng hỏng hoặc điều chỉnh sẽ xuất hiện tại đây.</p></section>
    @else
        <ol class="inventory-ledger">
        @foreach($transactions as $transaction)
            <li><time datetime="{{ $transaction->created_at->toIso8601String() }}">{{ $transaction->created_at->format('d/m/Y H:i:s') }}</time><div><strong>{{ $transaction->type->label() }}</strong><p>{{ $transaction->reason ?: 'Không có ghi chú' }}</p><small>Thực hiện bởi {{ $transaction->actor?->name ?? 'Hệ thống' }}</small></div><dl><div><dt>Bán được</dt><dd>{{ $transaction->sellable_delta > 0 ? '+' : '' }}{{ $transaction->sellable_delta }}</dd></div><div><dt>Hàng hỏng</dt><dd>{{ $transaction->damaged_delta > 0 ? '+' : '' }}{{ $transaction->damaged_delta }}</dd></div></dl></li>
        @endforeach
        </ol><div class="pagination-wrap">{{ $transactions->links() }}</div>
    @endif
</div>
@endsection
