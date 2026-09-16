<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique();
            $table->string('gender', 3)->nullable();
            $table->date('dob')->nullable();
            $table->text('address')->nullable();
            $table->string('role', 16)->default('customer')->index();
            $table->string('status', 16)->default('active')->index();
            $table->string('current_tier', 16)->default('dong')->index();
            $table->unsignedBigInteger('membership_spending')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_phone_unique');
            $table->dropIndex('users_role_index');
            $table->dropIndex('users_status_index');
            $table->dropIndex('users_current_tier_index');
            $table->dropColumn([
                'phone',
                'gender',
                'dob',
                'address',
                'role',
                'status',
                'current_tier',
                'membership_spending',
                'last_login_at',
                'must_change_password',
            ]);
        });
    }
};
