<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $t) {
            $t->id();
            $t->string('shop_domain')->unique();
            $t->string('shopify_shop_id')->nullable();
            $t->text('access_token')->nullable();
            $t->string('store_name')->nullable();
            $t->string('email')->nullable();
            $t->string('support_email')->nullable();
            $t->string('reply_to_email')->nullable();
            $t->string('timezone')->default('UTC');
            $t->string('currency', 3)->nullable();
            $t->string('status')->default('ACTIVE')->index();
            $t->timestamp('installed_at')->nullable();
            $t->timestamp('uninstalled_at')->nullable();
            $t->string('billing_status')->default('UNVERIFIED');
            $t->string('plan_handle')->nullable();
            $t->timestamp('billing_checked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('shop_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('store_display_name')->nullable();
            $t->string('support_email')->nullable();
            $t->string('reply_to_email')->nullable();
            $t->boolean('auto_email_enabled')->default(false);
            $t->boolean('test_mode')->default(true);
            $t->string('timezone')->default('UTC');
            $t->text('email_footer')->nullable();
            $t->timestamp('templates_reviewed_at')->nullable();
            $t->timestamp('onboarded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('email_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $t->string('dispute_reason', 40);
            $t->string('shipping_state', 30);
            $t->boolean('enabled')->default(true);
            $t->string('subject');
            $t->text('body');
            $t->boolean('is_default_modified')->default(false);
            $t->timestamps();
            $t->unique(['shop_id', 'dispute_reason', 'shipping_state'], 'template_combination_unique');
        });
        Schema::create('disputes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $t->string('shopify_dispute_id');
            $t->string('shopify_order_id')->nullable();
            $t->string('order_name')->nullable();
            $t->string('reason')->default('UNKNOWN');
            $t->string('shopify_reason_raw')->nullable();
            $t->string('status')->default('UNKNOWN');
            $t->string('shopify_status_raw')->nullable();
            $t->string('type')->nullable();
            $t->decimal('amount', 20, 4)->default(0);
            $t->string('currency', 3)->default('XXX');
            $t->string('shipping_state')->default('UNKNOWN');
            $t->text('raw_shipping_status')->nullable();
            $t->string('tracking_company')->nullable();
            $t->text('tracking_number')->nullable();
            $t->text('tracking_url')->nullable();
            $t->string('customer_email_hash', 64)->nullable();
            $t->json('refunds')->nullable();
            $t->timestamp('initiated_at')->nullable();
            $t->timestamp('evidence_due_at')->nullable();
            $t->string('automation_status')->default('PENDING');
            $t->string('review_reason')->nullable();
            $t->boolean('email_sent')->default(false);
            $t->timestamp('email_sent_at')->nullable();
            $t->timestamp('initial_processed_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->string('source')->default('shopify');
            $t->timestamp('redacted_at')->nullable();
            $t->timestamps();
            $t->unique(['shop_id', 'shopify_dispute_id']);
            foreach (['reason', 'status', 'shipping_state', 'automation_status', 'created_at', 'shopify_order_id', 'customer_email_hash'] as $column) {
                $t->index(['shop_id', $column]);
            }
        });
        Schema::create('automation_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $t->foreignId('dispute_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $t->string('shipping_state', 30);
            $t->string('dispute_reason', 40);
            $t->string('status')->default('QUEUED');
            $t->string('recipient_hash', 64)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->timestamps();
            $t->unique(['dispute_id', 'shipping_state', 'dispute_reason'], 'delivery_combination_unique');
        });
        Schema::create('email_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $t->foreignId('dispute_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('automation_delivery_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('recipient_masked')->nullable();
            $t->string('recipient_hash', 64)->nullable();
            $t->text('subject')->nullable();
            $t->text('rendered_body')->nullable();
            $t->string('shipping_state');
            $t->string('dispute_reason');
            $t->string('status')->default('QUEUED');
            $t->string('provider_message_id')->nullable();
            $t->string('error_message')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['shop_id', 'type', 'status', 'created_at']);
        });
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('shop_domain');
            $t->string('webhook_id')->unique();
            $t->string('topic');
            $t->text('payload')->nullable();
            $t->string('status')->default('PENDING');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_message')->nullable();
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'received_at']);
        });
        Schema::create('privacy_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $t->string('webhook_id')->unique();
            $t->string('status')->default('READY');
            $t->text('export')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        foreach (['jobs', 'privacy_requests', 'webhook_events', 'email_logs', 'automation_deliveries', 'disputes', 'email_templates', 'shop_settings', 'shops'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
