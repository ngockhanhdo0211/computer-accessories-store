<div class="support-widget" data-support-chat
    data-messages-url="{{ route('support.messages.index') }}"
    data-send-url="{{ route('support.messages.store') }}"
    data-read-url="{{ route('support.read') }}">
    <button class="support-widget__toggle" type="button" aria-expanded="false" aria-controls="support-chat-panel" data-support-toggle>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 12a8 8 0 0 1-8 8H7l-4 2 1.5-4A9 9 0 1 1 21 12Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/></svg>
        <span>Hỗ trợ</span>
        <span class="support-widget__unread" data-support-unread hidden aria-label="Có tin nhắn hỗ trợ mới"></span>
    </button>
    <section id="support-chat-panel" class="support-panel" aria-label="Hỗ trợ khách hàng" data-support-panel hidden>
        <header class="support-panel__header">
            <div><strong>Hỗ trợ khách hàng</strong><span>Đội ngũ cửa hàng sẽ phản hồi tại đây.</span></div>
            <button type="button" class="support-panel__close" aria-label="Đóng hỗ trợ khách hàng" data-support-close>×</button>
        </header>
        <button type="button" class="support-load-older" data-support-older hidden>Tải tin nhắn cũ hơn</button>
        <div class="support-messages" data-support-messages role="log" aria-live="polite" aria-relevant="additions text"></div>
        <div class="support-empty" data-support-empty>Hãy gửi câu hỏi đầu tiên. Chúng tôi sẽ phản hồi ngay khi có thể.</div>
        <div class="support-feedback" data-support-feedback role="status" aria-live="polite"></div>
        <form class="support-composer" data-support-form novalidate>
            @csrf
            <input type="hidden" name="client_message_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label for="support-customer-content">Tin nhắn</label>
            <textarea id="support-customer-content" name="content" maxlength="2000" rows="3" placeholder="Bạn cần hỗ trợ điều gì?" aria-describedby="support-customer-hint support-customer-error" required></textarea>
            <p id="support-customer-hint" class="support-composer__hint">Enter để gửi · Shift+Enter để xuống dòng</p>
            <div class="support-composer__actions"><span id="support-customer-error" class="field-error" data-support-error role="alert"></span><button class="button" type="submit">Gửi tin nhắn</button></div>
        </form>
    </section>
</div>
