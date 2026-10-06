@extends('layouts.storefront')
@section('title', 'Hỗ trợ khách hàng')
@section('content')
<section class="shell support-entry-page">
    <div class="page-header">
        <div><h1>Hỗ trợ khách hàng</h1><p>Gửi câu hỏi về sản phẩm, đơn hàng hoặc trải nghiệm mua sắm. Hội thoại chỉ hiển thị với bạn và đội ngũ hỗ trợ.</p></div>
        <button class="button" type="button" data-open-support-chat>Mở khung hỗ trợ</button>
    </div>
    <div class="support-entry-card">
        <h2>{{ $conversation ? 'Tiếp tục hội thoại' : 'Bắt đầu hội thoại' }}</h2>
        <p>{{ $conversation ? 'Lịch sử trao đổi của bạn được giữ nguyên để đội ngũ hỗ trợ theo dõi liền mạch.' : 'Bạn chưa có hội thoại. Tin nhắn đầu tiên sẽ tạo một hội thoại riêng cho tài khoản này.' }}</p>
    </div>
</section>
@endsection
