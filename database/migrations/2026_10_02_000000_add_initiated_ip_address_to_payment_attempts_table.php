<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_attempts')) {
            throw new RuntimeException('VNPay initiation migration requires payment_attempts.');
        }

        if (Schema::hasColumn('payment_attempts', 'initiated_ip_address')) {
            throw new RuntimeException('VNPay initiation migration found a partial state: initiated_ip_address already exists. Inspect it manually; no data is changed.');
        }

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->string('initiated_ip_address', 45)->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_attempts') || ! Schema::hasColumn('payment_attempts', 'initiated_ip_address')) {
            throw new RuntimeException('VNPay initiation rollback found an unexpected schema state.');
        }

        if (DB::table('payment_attempts')->whereNotNull('initiated_ip_address')->exists()) {
            throw new RuntimeException('Cannot drop initiated_ip_address while VNPay initiation evidence exists.');
        }

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn('initiated_ip_address');
        });
    }
};
