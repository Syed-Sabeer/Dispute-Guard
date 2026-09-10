<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'quota_plan')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->string('quota_plan', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('shops', 'billing_period_start')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->timestamp('billing_period_start')->nullable();
            });
        }

        if (! Schema::hasColumn('shops', 'billing_period_end')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->timestamp('billing_period_end')->nullable();
            });
        }

        if (! Schema::hasTable('subscription_usage_periods')) {
            Schema::create('subscription_usage_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->string('plan', 20);
                $table->unsignedInteger('allowance');
                $table->unsignedInteger('reserved')->default(0);
                $table->unsignedInteger('consumed')->default(0);
                $table->timestamps();

                $table->unique(['shop_id', 'starts_at']);
            });
        }

        if (! Schema::hasColumn('automation_deliveries', 'quota_period_id')) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->foreignId('quota_period_id')
                    ->nullable()
                    ->constrained('subscription_usage_periods')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('automation_deliveries', 'quota_status')) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->string('quota_status', 12)->nullable()->index();
            });
        }

        if (! Schema::hasColumn('automation_deliveries', 'transport_started_at')) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->timestamp('transport_started_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('automation_deliveries', 'quota_period_id')) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('quota_period_id');
            });
        }

        if (Schema::hasColumn('automation_deliveries', 'quota_status')) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->dropColumn('quota_status');
            });
        }

        if (Schema::hasColumn('automation_deliveries', 'transport_started_at')) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->dropColumn('transport_started_at');
            });
        }

        Schema::dropIfExists('subscription_usage_periods');

        if (Schema::hasColumn('shops', 'quota_plan')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropColumn('quota_plan');
            });
        }

        if (Schema::hasColumn('shops', 'billing_period_start')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropColumn('billing_period_start');
            });
        }

        if (Schema::hasColumn('shops', 'billing_period_end')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropColumn('billing_period_end');
            });
        }
    }
};
