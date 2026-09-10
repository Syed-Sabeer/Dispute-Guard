<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairQuotaMigration extends Command
{
    protected $signature = 'chargeguard:repair-quota-migration';

    protected $description = 'Complete only the interrupted subscription quota migration without changing existing data';

    public function handle(): int
    {
        return Cache::lock('quota-migration-recovery', 300)->block(2, fn () => $this->repair());
    }

    private function repair(): int
    {
        $migration = '2026_09_10_000001_create_subscription_usage_periods';
        foreach (['migrations', 'shops', 'automation_deliveries'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error('Required earlier migrations are missing. Apply the earlier migrations first.');

                return self::FAILURE;
            }
        }
        if (DB::table('migrations')->where('migration', $migration)->exists()) {
            $this->info('Quota migration is already recorded. No changes made.');

            return self::SUCCESS;
        }
        // MySQL DDL is not transactional. Each step tolerates a previous interrupted run.
        foreach (['quota_plan', 'billing_period_start', 'billing_period_end'] as $column) {
            if (! Schema::hasColumn('shops', $column)) {
                Schema::table('shops', function (Blueprint $table) use ($column) {
                    $column === 'quota_plan' ? $table->string($column, 20)->nullable() : $table->dateTime($column)->nullable();
                });
            }
        }
        if (! Schema::hasTable('subscription_usage_periods')) {
            Schema::create('subscription_usage_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
                // DATETIME has no legacy implicit TIMESTAMP defaults.
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('plan', 20);
                $table->unsignedInteger('allowance');
                $table->unsignedInteger('reserved')->default(0);
                $table->unsignedInteger('consumed')->default(0);
                $table->timestamps();
                $table->unique(['shop_id', 'starts_at']);
            });
        }
        foreach (['id', 'shop_id', 'starts_at', 'ends_at', 'plan', 'allowance', 'reserved', 'consumed', 'created_at', 'updated_at'] as $column) {
            if (! Schema::hasColumn('subscription_usage_periods', $column)) {
                $this->error('Existing quota table has an unexpected schema. No existing quota data was changed.');

                return self::FAILURE;
            }
        }
        if (! Schema::hasColumn('automation_deliveries', 'quota_period_id')) {
            Schema::table('automation_deliveries', fn (Blueprint $table) => $table->foreignId('quota_period_id')->nullable()->constrained('subscription_usage_periods')->nullOnDelete());
        }
        if (! Schema::hasColumn('automation_deliveries', 'quota_status')) {
            Schema::table('automation_deliveries', fn (Blueprint $table) => $table->string('quota_status', 12)->nullable()->index());
        }
        if (! Schema::hasColumn('automation_deliveries', 'transport_started_at')) {
            Schema::table('automation_deliveries', fn (Blueprint $table) => $table->dateTime('transport_started_at')->nullable());
        }
        if (! Schema::hasIndex('subscription_usage_periods', ['shop_id', 'starts_at'], 'unique')
            || ! collect(Schema::getForeignKeys('subscription_usage_periods'))->contains(fn ($key) => $key['columns'] === ['shop_id'] && $key['foreign_table'] === 'shops')
            // SQLite cannot add a foreign key through ALTER TABLE; production MySQL must have it.
            || (DB::connection()->getDriverName() === 'mysql' && ! collect(Schema::getForeignKeys('automation_deliveries'))->contains(fn ($key) => $key['columns'] === ['quota_period_id'] && $key['foreign_table'] === 'subscription_usage_periods'))) {
            $this->error('Quota constraints do not match the expected schema. Migration was not recorded; no existing rows were changed.');

            return self::FAILURE;
        }
        DB::table('migrations')->insert(['migration' => $migration, 'batch' => ((int) DB::table('migrations')->max('batch')) + 1]);
        $this->info('Quota schema completed and migration recorded. Existing rows and usage values were preserved. Run php artisan migrate --force next.');

        return self::SUCCESS;
    }
}
