<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuotaMigrationRecoveryTest extends TestCase
{
    public function test_partial_migration_can_be_completed_and_repeated_without_changing_existing_rows(): void
    {
        config(['database.connections.quota_recovery' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);

        $this->exerciseRecovery();
    }

    public function test_partial_migration_recovery_on_isolated_mysql_preserves_constraints_and_data(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/\Achargeguard_test_[a-f0-9]+\z/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Use scripts/test-mysql.php for isolated MySQL recovery validation.');
        }
        $database = 'chargeguard_test_recovery_'.bin2hex(random_bytes(6));
        $connection = config('database.connections.mysql');
        $connection['database'] = $database;
        config(['database.connections.quota_recovery' => $connection]);
        DB::statement('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $this->exerciseRecovery();
        } finally {
            // Only the random database created above is removed; never the app or outer test database.
            DB::statement('DROP DATABASE '.$database);
        }
    }

    private function exerciseRecovery(): void
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('quota_recovery');
        try {
            Schema::create('migrations', function (Blueprint $table) {
                $table->id();
                $table->string('migration');
                $table->integer('batch');
            });
            // Reproduce exactly the completed ALTER before the failed CREATE TABLE.
            Schema::create('shops', function (Blueprint $table) {
                $table->id();
                $table->string('quota_plan', 20)->nullable();
                $table->dateTime('billing_period_start')->nullable();
                $table->dateTime('billing_period_end')->nullable();
            });
            Schema::create('automation_deliveries', fn (Blueprint $table) => $table->id());
            DB::table('shops')->insert(['id' => 1, 'quota_plan' => 'starter', 'billing_period_start' => '2026-09-01 00:00:00', 'billing_period_end' => '2026-10-01 00:00:00']);
            DB::table('automation_deliveries')->insert(['id' => 1]);
            $before = DB::table('shops')->first();
            $this->artisan('chargeguard:repair-quota-migration')->assertExitCode(0);
            $this->assertEquals($before, DB::table('shops')->first());
            $this->assertSame('datetime', Schema::getColumnType('subscription_usage_periods', 'ends_at'));
            $this->assertTrue(Schema::hasColumns('automation_deliveries', ['quota_period_id', 'quota_status', 'transport_started_at']));
            $this->assertSame(1, DB::table('automation_deliveries')->count());
            DB::table('subscription_usage_periods')->insert(['shop_id' => 1, 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-10-01 00:00:00', 'plan' => 'starter', 'allowance' => 1000, 'reserved' => 3, 'consumed' => 4]);
            $period = DB::table('subscription_usage_periods')->first();
            $this->artisan('chargeguard:repair-quota-migration')->assertExitCode(0);
            $this->assertEquals($period, DB::table('subscription_usage_periods')->first());
            $this->assertSame(1, DB::table('migrations')->count());
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('quota_recovery');
        }
    }
}
