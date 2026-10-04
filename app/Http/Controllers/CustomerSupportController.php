<?php

namespace App\Http\Controllers;

use App\Actions\GetSupportMessages;
use App\Actions\MarkSupportConversationRead;
use App\Actions\SendSupportMessage;
use App\Http\Requests\GetSupportMessagesRequest;
use App\Http\Requests\MarkSupportReadRequest;
use App\Http\Requests\SendSupportMessageRequest;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class CustomerSupportController extends Controller
{
    public function show(): View
    {
        return view('support.customer', ['conversation' => SupportConversation::query()
            ->where('customer_id', request()->user()->id)->first()]);
    }

    public function messages(GetSupportMessagesRequest $request, GetSupportMessages $messages): JsonResponse
    {
        $conversation = SupportConversation::query()->where('customer_id', $request->user()->id)->first();
        if ($conversation === null) {
            return response()->json(['messages' => [], 'has_more' => false]);
        }
        $result = $messages->handle($request->user(), $conversation, $this->cursor($request, 'before_id'), $this->cursor($request, 'after_id'));

        return response()->json(['messages' => $result->map(fn (SupportMessage $message): array => $this->present($message, $request->user()->id))->values(),
            'has_more' => $result->count() === 50]);
    }

    public function store(SendSupportMessageRequest $request, SendSupportMessage $send): JsonResponse
    {
        $message = $send->handle($request->user(), null, $request->validated('client_message_key'), $request->validated('content'));

        return response()->json(['message' => $this->present($message, $request->user()->id)], 201);
    }

    public function read(MarkSupportReadRequest $request, MarkSupportConversationRead $mark): JsonResponse
    {
        $conversation = SupportConversation::query()->where('customer_id', $request->user()->id)->firstOrFail();
        $mark->handle($request->user(), $conversation, (int) $request->validated('message_id'));

        return response()->json(['ok' => true]);
    }

    private function cursor(GetSupportMessagesRequest $request, string $key): ?int
    {
        return $request->filled($key) ? (int) $request->validated($key) : null;
    }

    private function present(SupportMessage $message, int $viewerId): array
    {
        return ['id' => $message->id, 'content' => $message->content, 'mine' => $message->sender_id === $viewerId,
            'sender_label' => $message->sender_id === $viewerId ? 'Bạn' : 'Nhân viên hỗ trợ',
            'created_at' => $message->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y')];
    }
}
