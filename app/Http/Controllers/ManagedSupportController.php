<?php

namespace App\Http\Controllers;

use App\Actions\CloseSupportConversation;
use App\Actions\GetSupportInbox;
use App\Actions\GetSupportMessages;
use App\Actions\MarkSupportConversationRead;
use App\Actions\SendSupportMessage;
use App\Enums\UserRole;
use App\Http\Requests\CloseSupportConversationRequest;
use App\Http\Requests\GetSupportMessagesRequest;
use App\Http\Requests\MarkSupportReadRequest;
use App\Http\Requests\SendSupportMessageRequest;
use App\Http\Requests\SupportInboxRequest;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ManagedSupportController extends Controller
{
    public function index(SupportInboxRequest $request, GetSupportInbox $inbox): View|RedirectResponse
    {
        $conversations = $inbox->handle($request->user(), $request->validated('search'), $request->validated('status'));
        if ($conversations->isEmpty() && $conversations->total() > 0) {
            return redirect()->route($this->prefix($request).'.support.index', [
                ...$request->safe()->except('page'), 'page' => $conversations->lastPage(),
            ]);
        }

        return view('support.workspace', ['conversations' => $conversations, 'selected' => null,
            'filters' => $request->validated(), 'routePrefix' => $this->prefix($request)]);
    }

    public function show(SupportInboxRequest $request, SupportConversation $conversation, GetSupportInbox $inbox): View
    {
        $conversations = $inbox->handle($request->user(), $request->validated('search'), $request->validated('status'));

        return view('support.workspace', ['conversations' => $conversations,
            'selected' => $conversation->load('customer:id,name,email'), 'filters' => $request->validated(),
            'routePrefix' => $this->prefix($request)]);
    }

    public function messages(GetSupportMessagesRequest $request, SupportConversation $conversation, GetSupportMessages $messages): JsonResponse
    {
        $result = $messages->handle($request->user(), $conversation, $this->cursor($request, 'before_id'), $this->cursor($request, 'after_id'));

        return response()->json(['messages' => $result->map(fn (SupportMessage $message): array => $this->present($message, $request->user()->id))->values(),
            'has_more' => $result->count() === 50]);
    }

    public function store(SendSupportMessageRequest $request, SupportConversation $conversation, SendSupportMessage $send): JsonResponse
    {
        $message = $send->handle($request->user(), $conversation, $request->validated('client_message_key'), $request->validated('content'));

        return response()->json(['message' => $this->present($message, $request->user()->id)], 201);
    }

    public function read(MarkSupportReadRequest $request, SupportConversation $conversation, MarkSupportConversationRead $mark): JsonResponse
    {
        $mark->handle($request->user(), $conversation, (int) $request->validated('message_id'));

        return response()->json(['ok' => true]);
    }

    public function close(CloseSupportConversationRequest $request, SupportConversation $conversation, CloseSupportConversation $close): RedirectResponse
    {
        try {
            $close->handle($conversation, $request->user(), $request->validated('event_key'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors(), 'closeSupport')->withInput();
        }

        return redirect()->route($this->prefix($request).'.support.show', $conversation)->with('status', 'Đã đóng hội thoại hỗ trợ.');
    }

    private function prefix(Request $request): string
    {
        return $request->routeIs('admin.*') ? 'admin' : 'employee';
    }

    private function cursor(GetSupportMessagesRequest $request, string $key): ?int
    {
        return $request->filled($key) ? (int) $request->validated($key) : null;
    }

    private function present(SupportMessage $message, int $viewerId): array
    {
        $role = UserRole::tryFrom((string) $message->sender->getRawOriginal('role'));
        $label = $message->sender_id === $viewerId ? 'Bạn'
            : ($role === UserRole::Customer ? $message->sender->name : 'Nhân viên hỗ trợ');

        return ['id' => $message->id, 'content' => $message->content, 'mine' => $message->sender_id === $viewerId,
            'sender_label' => $label, 'created_at' => $message->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y')];
    }
}
