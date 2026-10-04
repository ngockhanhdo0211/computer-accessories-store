@extends('layouts.workspace')
@section('title', 'Hỗ trợ khách hàng')
@section('content')
<div class="shell admin-page support-workspace">
    <header class="page-header"><div><p class="eyebrow">Shared inbox</p><h1>Hỗ trợ khách hàng</h1><p>Admin và Employee cùng xử lý một hộp thư, mỗi người có trạng thái đọc riêng.</p></div></header>
    <div class="support-workspace__layout">
        <section class="support-inbox" aria-label="Danh sách hội thoại">
            <form class="filter-bar" method="GET" action="{{ route($routePrefix.'.support.index') }}">
                <div class="field"><label for="support-search">Tìm Customer</label><input id="support-search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Tên hoặc email"></div>
                <div class="field"><label for="support-status">Trạng thái</label><select id="support-status" name="status"><option value="">Tất cả</option><option value="open" @selected(($filters['status'] ?? '') === 'open')>Đang mở</option><option value="closed" @selected(($filters['status'] ?? '') === 'closed')>Đã đóng</option></select></div>
                <button class="button button--quiet" type="submit">Lọc</button>
            </form>
            @if($conversations->isEmpty())
                <div class="empty-state"><h2>{{ request()->hasAny(['search','status']) ? 'Không có kết quả' : 'Chưa có hội thoại' }}</h2><p>{{ request()->hasAny(['search','status']) ? 'Thử thay đổi từ khóa hoặc trạng thái.' : 'Hội thoại mới sẽ xuất hiện khi Customer gửi tin nhắn.' }}</p></div>
            @else
                <div class="support-inbox__list">
                    @foreach($conversations as $conversation)
                        <a class="support-inbox__item" href="{{ route($routePrefix.'.support.show', ['conversation' => $conversation, ...request()->only(['search','status','page'])]) }}" @if($selected?->id === $conversation->id) aria-current="page" @endif>
                            <span class="support-inbox__top"><strong>{{ $conversation->customer->name }}</strong><span class="status-badge {{ $conversation->status === \App\Enums\SupportConversationStatus::Open ? 'status-badge--success' : '' }}">{{ $conversation->status->label() }}</span></span>
                            <span class="support-inbox__preview">{{ $conversation->lastMessage?->content ?? 'Chưa có tin nhắn' }}</span>
                            <span class="support-inbox__meta"><span>{{ $conversation->lastMessage?->sender_id === $conversation->customer_id ? 'Customer' : 'Nhân viên hỗ trợ' }}</span><time>{{ $conversation->last_message_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') ?? '—' }}</time>@if($conversation->unread_count > 0)<span class="support-unread-count">{{ $conversation->unread_count }} chưa đọc</span>@endif</span>
                        </a>
                    @endforeach
                </div>
                {{ $conversations->links() }}
            @endif
        </section>

        <section class="support-thread" aria-label="Nội dung hội thoại">
            @if($selected)
                <a class="text-link support-thread__back" href="{{ route($routePrefix.'.support.index', request()->only(['search','status','page'])) }}">← Danh sách hội thoại</a>
                <header class="support-thread__header"><div><h2>{{ $selected->customer->name }}</h2><p>{{ $selected->customer->email }}</p></div><span class="status-badge">{{ $selected->status->label() }}</span></header>
                <div data-support-chat data-messages-url="{{ route($routePrefix.'.support.messages.index', $selected) }}" data-send-url="{{ route($routePrefix.'.support.messages.store', $selected) }}" data-read-url="{{ route($routePrefix.'.support.read', $selected) }}" data-workspace-thread>
                    <button type="button" class="support-load-older" data-support-older hidden>Tải tin nhắn cũ hơn</button>
                    <div class="support-messages" data-support-messages role="log" aria-live="polite" aria-relevant="additions text"></div>
                    <div class="support-empty" data-support-empty>Hội thoại chưa có tin nhắn.</div>
                    <div class="support-feedback" data-support-feedback role="status" aria-live="polite"></div>
                    <form class="support-composer" data-support-form novalidate>
                        @csrf<input type="hidden" name="client_message_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <label for="support-staff-content">Phản hồi</label><textarea id="support-staff-content" name="content" maxlength="2000" rows="3" required></textarea>
                        <p class="support-composer__hint">Tin nhắn mới sẽ tự mở lại hội thoại đã đóng.</p>
                        <div class="support-composer__actions"><span class="field-error" data-support-error role="alert"></span><button class="button" type="submit">Gửi phản hồi</button></div>
                    </form>
                </div>
                @if($selected->status === \App\Enums\SupportConversationStatus::Open)
                    <form class="support-close-form" method="POST" action="{{ route($routePrefix.'.support.close', $selected) }}" data-submit-once data-confirm-action="Đóng hội thoại này? Customer gửi tin mới sẽ tự mở lại.">
                        @csrf @method('PATCH')<input type="hidden" name="event_key" value="{{ $errors->closeSupport->any() ? old('event_key') : (string) \Illuminate\Support\Str::uuid() }}"><button class="button button--quiet" type="submit">Đóng hội thoại</button>
                        @foreach(['event_key','conversation','authorization','request'] as $field) @if($errors->closeSupport->has($field))<p class="field-error" role="alert">{{ $errors->closeSupport->first($field) }}</p>@endif @endforeach
                    </form>
                @endif
            @else
                <div class="empty-state support-thread__placeholder"><h2>Chọn một hội thoại</h2><p>Nội dung và thao tác trả lời sẽ hiển thị tại đây.</p></div>
            @endif
        </section>
    </div>
</div>
@endsection
