<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_email_senders', function (Blueprint $table) {
            $table->timestamp('verification_refresh_failed_at')->nullable();
        });
        Schema::table('automation_deliveries', function (Blueprint $table) {
            $table->string('sender_identity_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('automation_deliveries', fn (Blueprint $table) => $table->dropColumn('sender_identity_hash'));
        Schema::table('merchant_email_senders', fn (Blueprint $table) => $table->dropColumn('verification_refresh_failed_at'));
    }
};
