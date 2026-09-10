<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Shops quota / billing period columns
        |--------------------------------------------------------------------------
        |
        | These checks make the migration safe for production environments where
        | some of these columns may already exist.
        |
        */

        if (! Schema::hasColumn('shops', 'quota_plan')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->string('quota_plan', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('shops', 'billing_period_start')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dateTime('billing_period_start')->nullable();
            });
        }

        if (! Schema::hasColumn('shops', 'billing_period_end')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dateTime('billing_period_end')->nullable();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Subscription usage periods
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasTable('subscription_usage_periods')) {
            Schema::create('subscription_usage_periods', function (Blueprint $table) {
                $table->id();

                $table->foreignId('shop_id')
                    ->constrained('shops')
                    ->cascadeOnDelete();

                // DATETIME is intentionally used instead of TIMESTAMP for
                // compatibility with the production MySQL/MariaDB configuration.
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');

                $table->string('plan', 20);
                $table->unsignedInteger('allowance');

                $table->unsignedInteger('reserved')->default(0);
                $table->unsignedInteger('consumed')->default(0);

                $table->timestamps();

                $table->unique(
                    ['shop_id', 'starts_at'],
                    'subscription_usage_periods_shop_start_unique'
                );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Automation delivery quota fields
        |--------------------------------------------------------------------------
        */

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
                $table->string('quota_status', 12)
                    ->nullable()
                    ->index();
            });
        }

        if (! Schema::hasColumn('automation_deliveries', 'transport_started_at')) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->dateTime('transport_started_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Automation deliveries
        |--------------------------------------------------------------------------
        */

        if (
            Schema::hasTable('automation_deliveries')
            && Schema::hasColumn('automation_deliveries', 'quota_period_id')
        ) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('quota_period_id');
            });
        }

        if (
            Schema::hasTable('automation_deliveries')
            && Schema::hasColumn('automation_deliveries', 'quota_status')
        ) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->dropColumn('quota_status');
            });
        }

        if (
            Schema::hasTable('automation_deliveries')
            && Schema::hasColumn('automation_deliveries', 'transport_started_at')
        ) {
            Schema::table('automation_deliveries', function (Blueprint $table) {
                $table->dropColumn('transport_started_at');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Subscription usage periods
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists('subscription_usage_periods');

        /*
        |--------------------------------------------------------------------------
        | Shops
        |--------------------------------------------------------------------------
        */

        if (
            Schema::hasTable('shops')
            && Schema::hasColumn('shops', 'quota_plan')
        ) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropColumn('quota_plan');
            });
        }

        if (
            Schema::hasTable('shops')
            && Schema::hasColumn('shops', 'billing_period_start')
        ) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropColumn('billing_period_start');
            });
        }

        if (
            Schema::hasTable('shops')
            && Schema::hasColumn('shops', 'billing_period_end')
        ) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropColumn('billing_period_end');
            });
        }
    }
};
