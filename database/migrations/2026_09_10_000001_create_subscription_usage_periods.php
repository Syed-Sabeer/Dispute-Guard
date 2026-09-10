<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('quota_plan', 20)->nullable();
            $table->timestamp('billing_period_start')->nullable();
            $table->timestamp('billing_period_end')->nullable();
        });
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
        Schema::table('automation_deliveries', function (Blueprint $table) {
            $table->foreignId('quota_period_id')->nullable()->constrained('subscription_usage_periods')->nullOnDelete();
            $table->string('quota_status', 12)->nullable()->index();
            $table->timestamp('transport_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('automation_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quota_period_id');
            $table->dropColumn('quota_status');
            $table->dropColumn('transport_started_at');
        });
        Schema::dropIfExists('subscription_usage_periods');
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn(['quota_plan', 'billing_period_start', 'billing_period_end']));
    }
};
