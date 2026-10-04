<?php

namespace Tests\Feature;

use App\Actions\SendSupportMessage;
use App\Enums\SupportConversationStatus;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbSupportChatConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (env('RUN_MARIADB_SUPPORT_CHAT_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB Support Chat QA runner.');
        }
        if (DB::getDriverName() !== 'mysql' || ! str_contains((string) DB::getDatabaseName(), '_support_chat_qa_')) {
            $this->fail('Support Chat concurrency QA requires the isolated marker database.');
        }
    }

    public function test_first_send_idempotency_close_send_and_cross_actor_races_are_serialized(): void
    {
        $customer = User::factory()->create();
        $first = ['operation' => 'send', 'actor_id' => $customer->id, 'conversation_id' => null,
            'key' => (string) Str::uuid(), 'content' => 'First A'];
        $second = [...$first, 'key' => (string) Str::uuid(), 'content' => 'First B'];
        $this->assertSame([0, 0], $this->codes($this->runTwo($first, $second)));
        $conversation = SupportConversation::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(2, SupportMessage::query()->where('conversation_id', $conversation->id)->count());

        $sameKey = (string) Str::uuid();
        $same = ['operation' => 'send', 'actor_id' => $customer->id, 'conversation_id' => null,
            'key' => $sameKey, 'content' => 'Exactly once'];
        $this->assertSame([0, 0], $this->codes($this->runTwo($same, $same)));
        $this->assertSame(1, SupportMessage::query()->where('sender_id', $customer->id)->where('client_message_key', $sameKey)->count());

        $conflictKey = (string) Str::uuid();
        $conflict = ['operation' => 'send', 'actor_id' => $customer->id, 'conversation_id' => null,
            'key' => $conflictKey, 'content' => 'Payload A'];
        $this->assertSame([0, 2], $this->codes($this->runTwo($conflict, [...$conflict, 'content' => 'Payload B'])));

        $staff = User::factory()->employee()->create();
        $before = SupportMessage::query()->where('conversation_id', $conversation->id)->count();
        $this->assertSame([0, 0], $this->codes($this->runTwo(
            ['operation' => 'close', 'actor_id' => $staff->id, 'conversation_id' => $conversation->id, 'key' => (string) Str::uuid()],
            ['operation' => 'send', 'actor_id' => $customer->id, 'conversation_id' => null, 'key' => (string) Str::uuid(), 'content' => 'Race reopen'],
        )));
        $this->assertSame($before + 1, SupportMessage::query()->where('conversation_id', $conversation->id)->count());
        $this->assertContains($conversation->fresh()->status, [SupportConversationStatus::Open, SupportConversationStatus::Closed]);

        $this->assertSame([0, 0], $this->codes($this->runTwo(
            ['operation' => 'send', 'actor_id' => $customer->id, 'conversation_id' => null, 'key' => (string) Str::uuid(), 'content' => 'Customer concurrent'],
            ['operation' => 'send', 'actor_id' => $staff->id, 'conversation_id' => $conversation->id, 'key' => (string) Str::uuid(), 'content' => 'Staff concurrent'],
        )));
        $this->assertSame(
            SupportMessage::query()->where('conversation_id', $conversation->id)->max('created_at'),
            $conversation->fresh()->getRawOriginal('last_message_at'),
        );
    }

    public function test_read_race_never_moves_backward_and_metadata_guards_are_present(): void
    {
        $customer = User::factory()->create();
        $first = app(SendSupportMessage::class)->handle($customer, null, (string) Str::uuid(), 'One');
        $second = app(SendSupportMessage::class)->handle($customer, null, (string) Str::uuid(), 'Two');
        $staff = User::factory()->employee()->create();
        $this->assertSame([0, 0], $this->codes($this->runTwo(
            ['operation' => 'read', 'actor_id' => $staff->id, 'conversation_id' => $first->conversation_id, 'message_id' => $second->id],
            ['operation' => 'read', 'actor_id' => $staff->id, 'conversation_id' => $first->conversation_id, 'message_id' => $first->id],
        )));
        $this->assertSame($second->id, (int) DB::table('support_conversation_reads')->where('conversation_id', $first->conversation_id)
            ->where('user_id', $staff->id)->value('last_read_message_id'));

        $this->assertDatabaseWriteRejected(fn () => DB::table('support_conversations')
            ->where('id', $first->conversation_id)->update(['last_message_at' => null]));
        $otherCustomer = User::factory()->create();
        $this->assertDatabaseWriteRejected(fn () => DB::table('support_messages')->insert([
            'conversation_id' => $first->conversation_id, 'sender_id' => $otherCustomer->id,
            'content' => 'Sai owner', 'client_message_key' => (string) Str::uuid(), 'created_at' => now(),
        ]));
        foreach (["\t\r\n", "\u{00A0}"] as $blankContent) {
            $this->assertDatabaseWriteRejected(fn () => DB::table('support_messages')->insert([
                'conversation_id' => $first->conversation_id, 'sender_id' => $customer->id,
                'content' => $blankContent, 'client_message_key' => (string) Str::uuid(), 'created_at' => now(),
            ]));
        }
        DB::table('users')->where('id', $staff->id)->update(['status' => 'inactive']);
        $this->assertDatabaseWriteRejected(fn () => DB::table('support_conversation_reads')
            ->where('conversation_id', $first->conversation_id)->where('user_id', $staff->id)
            ->update(['read_at' => now()->addSecond()]));

        $metadata = DB::selectOne('SELECT VERSION() version, @@innodb_force_recovery recovery');
        $this->assertStringContainsString('10.4.32-MariaDB', $metadata->version);
        $this->assertSame(0, (int) $metadata->recovery);
        foreach (DB::select('CHECK TABLE support_conversations, support_messages, support_conversation_reads') as $check) {
            $this->assertSame('OK', $check->Msg_text);
        }
        $this->assertSame(10, DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'like', 'support_%')->count());
    }

    private function workerCode(): string
    {
        return <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 10;
while (! file_exists($argv[2]) && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($argv[2])) { exit(4); }
try {
    $actor = App\Models\User::query()->findOrFail($payload['actor_id']);
    $conversation = isset($payload['conversation_id']) ? App\Models\SupportConversation::query()->findOrFail($payload['conversation_id']) : null;
    if ($payload['operation'] === 'send') {
        app(App\Actions\SendSupportMessage::class)->handle($actor, $conversation, $payload['key'], $payload['content']);
    } elseif ($payload['operation'] === 'close') {
        app(App\Actions\CloseSupportConversation::class)->handle($conversation, $actor, $payload['key']);
    } else {
        app(App\Actions\MarkSupportConversationRead::class)->handle($actor, $conversation, $payload['message_id']);
    }
    exit(0);
} catch (Illuminate\Validation\ValidationException) { exit(2); }
catch (Throwable $exception) { fwrite(STDERR, get_class($exception).': '.$exception->getMessage()); exit(3); }
PHP;
    }

    /** @return list<Process> */
    private function runTwo(array $first, array $second): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'support-chat-'.Str::uuid();
        $processes = collect([$first, $second])->map(function (array $payload) use ($barrier): Process {
            $process = new Process([PHP_BINARY, '-r', $this->workerCode(), base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $barrier], base_path());
            $process->setTimeout(30);
            $process->start();

            return $process;
        })->all();
        try {
            usleep(100000);
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertNotSame(4, $process->getExitCode(), $process->getErrorOutput());
                $this->assertNotSame(3, $process->getExitCode(), $process->getErrorOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }

        return $processes;
    }

    private function codes(array $processes): array
    {
        $codes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        sort($codes);

        return $codes;
    }

    private function assertDatabaseWriteRejected(callable $write): void
    {
        try {
            $write();
            $this->fail('MariaDB accepted invalid Support Chat evidence.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
