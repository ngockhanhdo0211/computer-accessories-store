@extends('layouts.storefront')

@section('title', 'Đề nghị điều chỉnh kho')

@section('content')
<div class="shell inventory-page">
    <header class="inventory-header"><div><p class="eyebrow">Tồn kho / Phê duyệt</p><h1>Đề nghị điều chỉnh</h1><p>{{ auth()->user()->isAdmin() ? 'Danh sách toàn hệ thống và hành động duyệt/từ chối.' : 'Các đề nghị do bạn tạo; pending chưa làm thay đổi tồn kho.' }}</p></div><a class="text-link" href="{{ route('inventory.index') }}">Về tồn kho</a></header>
    @if($adjustments->isEmpty())
        <section class="catalog-empty"><h2>Chưa có đề nghị</h2><p>Đề nghị điều chỉnh sẽ xuất hiện tại đây.</p></section>
    @else
        <div class="adjustment-list">
        @foreach($adjustments as $adjustment)
            <article class="adjustment-row"><header><div><p>{{ $adjustment->product->sku }}</p><h2><a href="{{ route('inventory.history', $adjustment->product) }}">{{ $adjustment->product->name }}</a></h2></div><span class="stock-badge stock-badge--{{ $adjustment->isApproved() ? 'in' : ($adjustment->isRejected() ? 'out' : 'low') }}">{{ $adjustment->statusLabel() }}</span></header><dl><div><dt>Tồn bán được</dt><dd>{{ $adjustment->sellable_delta > 0 ? '+' : '' }}{{ $adjustment->sellable_delta }}</dd></div><div><dt>Hàng hỏng</dt><dd>{{ $adjustment->damaged_delta > 0 ? '+' : '' }}{{ $adjustment->damaged_delta }}</dd></div><div><dt>Người đề nghị</dt><dd>{{ $adjustment->requester->name }}</dd></div></dl><p>{{ $adjustment->reason }}</p>
                @if(auth()->user()->isAdmin() && $adjustment->isPending())<div class="adjustment-actions"><form method="POST" action="{{ route('inventory.adjustments.approve', $adjustment) }}" data-submit-once data-confirm-action="Duyệt đề nghị và cập nhật tồn kho?">@csrf @method('PATCH')<button class="button" type="submit">Duyệt</button></form><form method="POST" action="{{ route('inventory.adjustments.reject', $adjustment) }}" data-submit-once data-confirm-action="Từ chối đề nghị này?">@csrf @method('PATCH')<button class="button button--outline" type="submit">Từ chối</button></form></div>@endif
            </article>
        @endforeach
        </div><div class="pagination-wrap">{{ $adjustments->links() }}</div>
    @endif
</div>
@endsection
