<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MariaDbCodOrderMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_COD_QA') !== '1') {
            $this->markTestSkipped('Run with the isolated MariaDB COD QA runner.');
        }
        if (DB::getDriverName() !== 'mysql'
            || ! str_contains((string) DB::getDatabaseName(), '_cod_order_qa_')) {
            $this->fail('COD metadata QA requires the isolated marker database.');
        }
    }

    private function assertDirectSqlEnforcesCodVnpaySourceAndFingerprintConstraints(): void
    {
        $order = Order::factory()->create();
        $originalFingerprint = $order->idempotency_fingerprint;

        foreach ([null, str_repeat('a', 63), str_repeat('A', 64)] as $invalidFingerprint) {
            $this->assertSqlRejected(fn () => DB::table('orders')
                ->where('id', $order->id)
                ->update(['idempotency_fingerprint' => $invalidFingerprint]));
        }

        $attempt = PaymentAttempt::factory()->create([
            'status' => PaymentStatus::Paid,
            'verified_at' => now(),
            'gateway_transaction_id' => 'TX-'.Str::uuid(),
        ]);
        $this->assertSqlRejected(fn () => DB::table('orders')
            ->where('id', $order->id)
            ->update([
                'payment_method' => 'vnpay',
                'payment_attempt_id' => $attempt->id,
                'request_key' => null,
                'idempotency_fingerprint' => $originalFingerprint,
            ]));
        $this->assertSqlRejected(fn () => DB::table('orders')
            ->where('id', $order->id)
            ->update(['payment_attempt_id' => $attempt->id]));

        $order->refresh();
        $this->assertSame('cod', $order->getRawOriginal('payment_method'));
        $this->assertNull($order->payment_attempt_id);
        $this->assertSame($originalFingerprint, $order->idempotency_fingerprint);
    }

    public function test_mariadb_version_recovery_mode_and_cod_metadata(): void
    {
        $server = DB::selectOne('SELECT VERSION() AS version, @@innodb_force_recovery AS recovery');
        $this->assertStringContainsString('10.4.32-MariaDB', (string) $server->version);
        $this->assertSame(0, (int) $server->recovery);

        $column = DB::selectOne(<<<'SQL'
            SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
              AND COLUMN_NAME = 'idempotency_fingerprint'
            SQL);
        $this->assertSame('char(64)', strtolower($column->column_type));
        $this->assertSame('YES', $column->is_nullable);

        $constraints = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS name
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
            SQL))->pluck('name');
        $this->assertContains('orders_user_request_unique', $constraints);
        $this->assertContains('orders_idempotency_fingerprint_check', $constraints);

        foreach (['orders', 'order_items', 'order_status_histories', 'inventory_transactions', 'coupon_usages'] as $table) {
            $status = DB::selectOne('CHECK TABLE `'.$table.'`');
            $this->assertSame('OK', $status->Msg_text);
        }

        $this->assertDirectSqlEnforcesCodVnpaySourceAndFingerprintConstraints();
    }

    private function assertSqlRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('MariaDB accepted an invalid COD/VNPay Order state.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
