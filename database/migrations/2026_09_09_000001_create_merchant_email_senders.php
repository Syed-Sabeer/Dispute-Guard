<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sending_domains', function (Blueprint $t) {
            $t->id();
            $t->string('domain', 253)->unique();
            $t->string('provider', 20)->default('postmark');
            $t->unsignedBigInteger('provider_domain_id')->nullable()->unique();
            $t->text('dkim_host')->nullable();
            $t->text('dkim_value')->nullable();
            $t->text('return_path_host')->nullable();
            $t->text('return_path_value')->nullable();
            $t->timestamps();
        });
        Schema::create('merchant_email_senders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('email_sending_domain_id')->constrained()->restrictOnDelete();
            $t->string('sender_name', 100);
            $t->string('sender_email', 254);
            $t->string('verification_status', 20)->default('PENDING');
            $t->boolean('dkim_verified')->default(false);
            $t->boolean('return_path_verified')->default(false);
            $t->string('ownership_host', 253);
            $t->string('ownership_value', 100);
            $t->boolean('ownership_verified')->default(false);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['verification_status', 'last_checked_at']);
        });
        // email_logs.provider_message_id already exists; preserve it and all claims.
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_email_senders');
        Schema::dropIfExists('email_sending_domains');
    }
};
