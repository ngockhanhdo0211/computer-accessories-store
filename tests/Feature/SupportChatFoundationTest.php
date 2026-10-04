<?php

namespace Tests\Feature;

use App\Actions\CloseSupportConversation;
use App\Actions\GetSupportInbox;
use App\Actions\GetSupportMessages;
use App\Actions\MarkSupportConversationRead;
use App\Actions\SendSupportMessage;
use App\Enums\SupportConversationStatus;
use App\Models\AuditLog;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SupportChatFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_models_relationships_and_database_guards_are_strict(): void
    {
        $customer = User::factory()->create();
        $conversation = SupportConversation::factory()->for($customer, 'customer')->create();
        $message = $this->send($customer, null, 'Xin chào');

        $this->assertSame($customer->id, $conversation->customer->id);
        $this->assertTrue($conversation->is($customer->supportConversation));
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame($customer->id, $message->sender->id);
        $this->assertDatabaseHas('support_conversation_reads', ['conversation_id' => $conversation->id,
            'user_id' => $customer->id, 'last_read_message_id' => $message->id]);
        $this->assertTrue(Schema::hasColumns('support_conversations', ['customer_id', 'status', 'last_message_at', 'closed_by', 'closed_at']));

        $this->expectException(LogicException::class);
        $message->delete();
    }

    public function test_database_rejects_duplicate_customer_bad_sender_mutation_and_backward_read_marker(): void
    {
        $customer = User::factory()->create();
        $conversation = SupportConversation::factory()->for($customer, 'customer')->create();
        $first = $this->send($customer, null, 'Một');
        $second = $this->send($customer, null, 'Hai');

        foreach ([
            fn () => SupportConversation::factory()->for($customer, 'customer')->create(),
            fn () => DB::table('support_messages')->where('id', $first->id)->update(['content' => 'Sửa']),
            fn () => DB::table('support_conversation_reads')->where('conversation_id', $conversation->id)
                ->where('user_id', $customer->id)->update(['last_read_message_id' => $first->id]),
            fn () => DB::table('support_conversations')->where('id', $conversation->id)->update(['last_message_at' => null]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Database accepted invalid Support Chat evidence.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($second->id, (int) DB::table('support_conversation_reads')->where('conversation_id', $conversation->id)
            ->where('user_id', $customer->id)->value('last_read_message_id'));

    }

    public function test_direct_sql_enforces_actor_roles_content_shape_and_conversation_projection(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $inactiveStaff = User::factory()->employee()->inactive()->create();
        $conversation = SupportConversation::factory()->for($customer, 'customer')->create();

        foreach ([$otherCustomer, $inactiveStaff] as $invalidSender) {
            try {
                DB::table('support_messages')->insert(['conversation_id' => $conversation->id,
                    'sender_id' => $invalidSender->id, 'content' => 'Không hợp lệ',
                    'client_message_key' => (string) Str::uuid(), 'created_at' => now()]);
                $this->fail('Database accepted an invalid support sender.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        foreach (["\t\r\n", "\u{00A0}"] as $blankContent) {
            try {
                DB::table('support_messages')->insert(['conversation_id' => $conversation->id,
                    'sender_id' => $customer->id, 'content' => $blankContent,
                    'client_message_key' => (string) Str::uuid(), 'created_at' => now()]);
                $this->fail('Database accepted whitespace-only support content.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $createdAt = now()->addSecond();
        DB::table('support_messages')->insert(['conversation_id' => $conversation->id,
            'sender_id' => $customer->id, 'content' => 'Direct SQL hợp lệ',
            'client_message_key' => (string) Str::uuid(), 'created_at' => $createdAt]);
        $this->assertSame($createdAt->format('Y-m-d H:i:s'), $conversation->fresh()->last_message_at?->format('Y-m-d H:i:s'));
    }

    public function test_send_and_close_faults_roll_back_all_support_evidence(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->employee()->create();
        $message = $this->send($customer, null, 'Ban đầu');
        $conversation = $message->conversation;
        app(CloseSupportConversation::class)->handle($conversation, $staff, (string) Str::uuid());
        $closed = $conversation->fresh();

        DB::statement("CREATE TRIGGER support_test_read_failure BEFORE UPDATE ON support_conversation_reads BEGIN SELECT RAISE(ABORT, 'injected read failure'); END");
        try {
            app(SendSupportMessage::class)->handle($customer, null, (string) Str::uuid(), 'Phải rollback');
            $this->fail('Injected read-marker failure did not abort send.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS support_test_read_failure');
        }
        $this->assertDatabaseCount('support_messages', 1);
        $this->assertSame(SupportConversationStatus::Closed, $conversation->fresh()->status);
        $this->assertTrue($conversation->fresh()->last_message_at->equalTo($closed->last_message_at));

        DB::statement("CREATE TRIGGER support_test_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action = 'support.conversation_closed' BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");
        $this->send($customer, null, 'Mở lại hợp lệ');
        try {
            app(CloseSupportConversation::class)->handle($conversation->fresh(), $staff, (string) Str::uuid());
            $this->fail('Injected audit failure did not abort close.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS support_test_audit_failure');
        }
        $this->assertSame(SupportConversationStatus::Open, $conversation->fresh()->status);
    }

    public function test_customer_send_replay_conflict_closed_reopen_and_ownership(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $staff = User::factory()->employee()->create();
        $key = strtoupper((string) Str::uuid());
        $message = app(SendSupportMessage::class)->handle($customer, null, $key, '  Nội dung hỗ trợ  ');
        $conversation = $message->conversation;

        $replay = app(SendSupportMessage::class)->handle($customer, null, strtolower($key), 'Nội dung hỗ trợ');
        $this->assertTrue($message->is($replay));
        $this->assertSame(strtolower($key), $message->client_message_key);
        $this->assertDatabaseCount('support_messages', 1);
        try {
            app(SendSupportMessage::class)->handle($customer, null, $key, 'Nội dung khác');
            $this->fail('Changed idempotency payload must conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_message_key', $exception->errors());
        }

        app(CloseSupportConversation::class)->handle($conversation, $staff, (string) Str::uuid());
        $this->assertSame(SupportConversationStatus::Closed, $conversation->fresh()->status);
        $this->send($customer, null, 'Mở lại');
        $this->assertSame(SupportConversationStatus::Open, $conversation->fresh()->status);
        $this->expectException(NotFoundHttpException::class);
        app(GetSupportMessages::class)->handle($other, $conversation);
    }

    public function test_message_pagination_is_incremental_stable_and_rejects_invalid_cursor_combinations(): void
    {
        $customer = User::factory()->create();
        foreach (range(1, 61) as $number) {
            $this->send($customer, null, 'Tin '.$number);
        }
        $conversation = SupportConversation::query()->sole();
        $initial = app(GetSupportMessages::class)->handle($customer, $conversation);
        $this->assertCount(50, $initial);
        $this->assertSame(range(12, 61), $initial->pluck('id')->all());
        $older = app(GetSupportMessages::class)->handle($customer, $conversation, 12);
        $this->assertSame(range(1, 11), $older->pluck('id')->all());
        $newer = app(GetSupportMessages::class)->handle($customer, $conversation, null, 58);
        $this->assertSame(range(59, 61), $newer->pluck('id')->all());

        $this->actingAs($customer)->getJson(route('support.messages.index', ['after_id' => 1]))
            ->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('has_more', true);
        $this->actingAs($customer)->getJson(route('support.messages.index', ['after_id' => 51]))
            ->assertOk()->assertJsonCount(10, 'messages')->assertJsonPath('has_more', false);

        $this->actingAs($customer)->getJson(route('support.messages.index', ['before_id' => '1e2']))->assertUnprocessable();
        $this->actingAs($customer)->getJson(route('support.messages.index', ['before_id' => 1, 'after_id' => 2]))->assertUnprocessable();
        foreach (['true', '1.5', '9223372036854775808'] as $invalidCursor) {
            $this->actingAs($customer)->getJson(route('support.messages.index', ['before_id' => $invalidCursor]))->assertUnprocessable();
        }
        $this->actingAs($customer)->getJson('/support/messages?before_id[]=1')->assertUnprocessable();
    }

    public function test_read_markers_are_per_user_monotonic_idempotent_and_do_not_count_own_message(): void
    {
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $customerMessage = $this->send($customer, null, 'Cần hỗ trợ');
        $conversation = $customerMessage->conversation;
        $staffMessage = $this->send($employee, $conversation, 'Đã tiếp nhận');

        app(MarkSupportConversationRead::class)->handle($employee, $conversation, $staffMessage->id);
        app(MarkSupportConversationRead::class)->handle($employee, $conversation, $customerMessage->id);
        $this->assertSame($staffMessage->id, (int) DB::table('support_conversation_reads')->where('conversation_id', $conversation->id)
            ->where('user_id', $employee->id)->value('last_read_message_id'));
        $inbox = app(GetSupportInbox::class)->handle($employee, null, null);
        $this->assertSame(0, (int) $inbox->first()->unread_count);
        $adminInbox = app(GetSupportInbox::class)->handle($admin, null, null);
        $this->assertSame(2, (int) $adminInbox->first()->unread_count);
    }

    public function test_close_is_staff_only_idempotent_audited_and_old_replay_detects_reopen(): void
    {
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $conversation = $this->send($customer, null, 'Đóng giúp')->conversation;
        $key = (string) Str::uuid();

        app(CloseSupportConversation::class)->handle($conversation, $employee, $key);
        app(CloseSupportConversation::class)->handle($conversation->fresh(), $employee, $key);
        $this->assertSame(1, AuditLog::query()->where('request_id', $key)->count());
        $audit = AuditLog::query()->where('request_id', $key)->sole();
        $this->assertSame($conversation->customer_id, $audit->after_json['customer_id']);
        $this->assertStringNotContainsString('Đóng giúp', json_encode($audit->after_json, JSON_THROW_ON_ERROR));
        $this->send($customer, null, 'Mở lại giúp');

        $this->expectException(ValidationException::class);
        app(CloseSupportConversation::class)->handle($conversation->fresh(), $employee, $key);
    }

    public function test_routes_authorization_field_injection_xss_and_rate_limit_are_safe(): void
    {
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $this->get(route('support.show'))->assertRedirect(route('login'));
        $this->actingAs($employee)->get(route('support.show'))->assertForbidden();
        $this->actingAs($customer)->get(route('employee.support.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('admin.support.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('employee.support.index'))->assertForbidden();

        $payload = '<img src=x onerror=alert(1)> Unicode ✓';
        $this->actingAs($customer)->postJson(route('support.messages.store'), [
            'client_message_key' => (string) Str::uuid(), 'content' => $payload, 'sender_id' => $admin->id,
        ])->assertUnprocessable();
        $this->actingAs($customer)->postJson(route('support.messages.store'), [
            'client_message_key' => (string) Str::uuid(), 'content' => $payload,
        ])->assertCreated();
        $this->actingAs($customer)->get(route('support.show'))->assertOk()->assertDontSee($payload, false);

        $rateCustomer = User::factory()->create();
        foreach (range(1, 10) as $number) {
            $this->actingAs($rateCustomer)->postJson(route('support.messages.store'), [
                'client_message_key' => (string) Str::uuid(), 'content' => 'Rate '.$number,
            ])->assertCreated();
        }
        $this->actingAs($rateCustomer)->postJson(route('support.messages.store'), [
            'client_message_key' => (string) Str::uuid(), 'content' => 'Quá giới hạn',
        ])->assertTooManyRequests();
    }

    public function test_staff_inbox_search_filter_sort_and_query_count_are_bounded(): void
    {
        $staff = User::factory()->employee()->create();
        foreach (range(1, 5) as $number) {
            $customer = User::factory()->create(['name' => 'Customer '.$number, 'email' => "customer{$number}%_@example.test"]);
            $this->send($customer, null, 'Nội dung '.$number);
        }
        $literal = app(GetSupportInbox::class)->handle($staff, '%_', null);
        $this->assertSame(5, $literal->total());
        $none = app(GetSupportInbox::class)->handle($staff, 'không có', null);
        $this->assertSame(0, $none->total());

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(GetSupportInbox::class)->handle($staff, null, 'open')->items();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(6, $queryCount);
        $this->actingAs($staff)->get(route('employee.support.index'))->assertOk()->assertSee('Customer 5');
    }

    public function test_staff_rate_limit_is_per_actor_and_does_not_insert_the_blocked_message(): void
    {
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $conversation = $this->send($customer, null, 'Cần hỗ trợ')->conversation;

        foreach (range(1, 30) as $number) {
            $this->actingAs($employee)->postJson(route('employee.support.messages.store', $conversation), [
                'client_message_key' => (string) Str::uuid(), 'content' => 'Employee '.$number,
            ])->assertCreated();
        }
        $this->actingAs($employee)->postJson(route('employee.support.messages.store', $conversation), [
            'client_message_key' => (string) Str::uuid(), 'content' => 'Bị giới hạn',
        ])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->actingAs($admin)->postJson(route('admin.support.messages.store', $conversation), [
            'client_message_key' => (string) Str::uuid(), 'content' => 'Quota riêng',
        ])->assertCreated();
        $this->assertDatabaseCount('support_messages', 32);
    }

    public function test_staff_reply_reopens_closed_conversation_and_read_markers_remain_individual(): void
    {
        $customer = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $conversation = $this->send($customer, null, 'Câu hỏi')->conversation;
        app(CloseSupportConversation::class)->handle($conversation, $admin, (string) Str::uuid());
        $reply = $this->send($employee, $conversation->fresh(), 'Phản hồi từ shared inbox');

        $this->assertSame(SupportConversationStatus::Open, $conversation->fresh()->status);
        $this->assertDatabaseHas('support_conversation_reads', ['conversation_id' => $conversation->id,
            'user_id' => $employee->id, 'last_read_message_id' => $reply->id]);
        $this->assertDatabaseMissing('support_conversation_reads', ['conversation_id' => $conversation->id, 'user_id' => $admin->id]);
    }

    public function test_validation_role_status_routes_and_polling_ui_contract_are_explicit(): void
    {
        $customer = User::factory()->create();
        foreach ([true, ['array'], str_repeat('x', 2001)] as $invalid) {
            $this->actingAs($customer)->postJson(route('support.messages.store'), [
                'client_message_key' => (string) Str::uuid(), 'content' => $invalid,
            ])->assertUnprocessable();
        }
        $this->actingAs($customer)->postJson(route('support.messages.store'), [
            'client_message_key' => (string) Str::uuid(), 'content' => "\u{00A0}\t\r\n",
        ])->assertUnprocessable();
        try {
            app(SendSupportMessage::class)->handle($customer, null, (string) Str::uuid(), "\xC3\x28");
            $this->fail('Invalid UTF-8 was accepted by the domain action.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content', $exception->errors());
        }
        $locked = User::factory()->locked()->create();
        try {
            app(SendSupportMessage::class)->handle($locked, null, (string) Str::uuid(), 'Không hợp lệ');
            $this->fail('Locked user sent a support message.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authorization', $exception->errors());
        }

        $routes = app('router')->getRoutes();
        $this->assertSame(['GET', 'HEAD'], $routes->getByName('support.messages.index')->methods());
        $this->assertSame(['POST'], $routes->getByName('support.messages.store')->methods());
        $this->assertContains('throttle:support-customer', $routes->getByName('support.messages.store')->gatherMiddleware());
        foreach (['admin', 'employee'] as $prefix) {
            $this->assertSame(['PATCH'], $routes->getByName($prefix.'.support.close')->methods());
            $this->assertContains('throttle:support-staff', $routes->getByName($prefix.'.support.messages.store')->gatherMiddleware());
        }

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("url.searchParams.set('after_id'", $javascript);
        $this->assertStringContainsString('new AbortController()', $javascript);
        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $javascript);
        $this->assertStringContainsString('textContent = message.content', $javascript);
        $this->assertStringNotContainsString('innerHTML', $javascript);
        $this->assertStringContainsString('5000', $javascript);
        $this->assertStringContainsString('event.isComposing', $javascript);
        $this->assertStringContainsString("catchUp = mode === 'new' && data.has_more === true", $javascript);
        $this->actingAs($customer)->get(route('support.show'))->assertOk()
            ->assertSee('aria-expanded="false"', false)->assertSee('aria-live="polite"', false);
    }

    public function test_migration_round_trip_is_safe_only_without_evidence(): void
    {
        $migration = require database_path('migrations/2026_10_06_000000_create_support_chat_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('support_conversations'));
        $migration->up();
        $customer = User::factory()->create();
        $this->send($customer, null, 'Bằng chứng');

        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    private function send(User $sender, ?SupportConversation $conversation, string $content): SupportMessage
    {
        return app(SendSupportMessage::class)->handle($sender, $conversation, (string) Str::uuid(), $content);
    }
}
