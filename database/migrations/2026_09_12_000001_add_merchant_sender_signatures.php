<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_email_senders', function (Blueprint $table) {
            // Existing records keep their domain ownership and DNS verification rules.
            $table->string('sender_mode', 12)->default('DOMAIN');
            $table->unsignedBigInteger('provider_signature_id')->nullable();
            $table->dateTime('provider_confirmed_at')->nullable();
            $table->string('verification_token_hash', 64)->nullable()->unique();
            $table->dateTime('verification_expires_at')->nullable();
            $table->dateTime('verification_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_email_senders', function (Blueprint $table) {
            $table->dropUnique(['verification_token_hash']);
            $table->dropColumn(['sender_mode', 'provider_signature_id', 'provider_confirmed_at', 'verification_token_hash', 'verification_expires_at', 'verification_sent_at']);
        });
    }
};
