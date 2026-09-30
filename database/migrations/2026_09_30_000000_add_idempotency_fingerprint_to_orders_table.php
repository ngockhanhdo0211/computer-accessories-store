<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            throw new RuntimeException('COD idempotency migration requires the orders table.');
        }

        if (Schema::hasColumn('orders', 'idempotency_fingerprint')) {
            throw new RuntimeException('COD idempotency migration found a partial migration state. Inspect orders.idempotency_fingerprint manually.');
        }

        if (DB::table('orders')->where('payment_method', 'cod')->exists()) {
            throw new RuntimeException('Existing COD Orders require a reviewed fingerprint backfill before this migration can run. No row was changed.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                ALTER TABLE orders ADD COLUMN idempotency_fingerprint CHAR(64) NULL
                CHECK (
                    (payment_method = 'cod' AND idempotency_fingerprint IS NOT NULL AND LENGTH(idempotency_fingerprint) = 64 AND idempotency_fingerprint NOT GLOB '*[^0-9a-f]*')
                    OR (payment_method = 'vnpay' AND idempotency_fingerprint IS NULL)
                )
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD COLUMN idempotency_fingerprint CHAR(64) NULL AFTER request_key,
                ADD CONSTRAINT orders_idempotency_fingerprint_check CHECK (
                    (BINARY payment_method = 'cod' AND idempotency_fingerprint IS NOT NULL AND idempotency_fingerprint REGEXP BINARY '^[0-9a-f]{64}$')
                    OR (BINARY payment_method = 'vnpay' AND idempotency_fingerprint IS NULL)
                )
            SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'idempotency_fingerprint')) {
            return;
        }

        if (DB::table('orders')->whereNotNull('idempotency_fingerprint')->exists()) {
            throw new RuntimeException('Cannot remove COD idempotency fingerprints while COD Orders exist. No Order was deleted.');
        }

        if (DB::getDriverName() === 'mysql') {
            $version = strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version);
            DB::statement(str_contains($version, 'mariadb')
                ? 'ALTER TABLE orders DROP CONSTRAINT orders_idempotency_fingerprint_check'
                : 'ALTER TABLE orders DROP CHECK orders_idempotency_fingerprint_check');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('idempotency_fingerprint');
        });
    }
};
