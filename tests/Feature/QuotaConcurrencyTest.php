<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\SubscriptionUsagePeriod;
use App\Services\Billing\UsageQuota;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ShopifyData;
use Tests\TestCase;

class QuotaConcurrencyTest extends TestCase
{
    use ShopifyData;

    public function test_two_processes_cannot_reserve_the_last_slot_twice(): void
    {
        $db = config('database.connections.mysql');
        if (DB::getDriverName() !== 'mysql' || ! str_starts_with($db['database'], 'chargeguard_test_')) {
            $this->markTestSkipped('Real concurrency runs in the isolated MySQL suite.');
        }
        // This test cannot use a parent transaction: independent workers must see
        // committed fixtures. The runner owns and deletes this isolated database.
        $shop = $this->shop();
        app(UsageQuota::class)->summary($shop);
        SubscriptionUsagePeriod::where('shop_id', $shop->id)->update(['consumed' => 999]);
        $gate = sys_get_temp_dir().'/quota-gate-'.bin2hex(random_bytes(8));
        $workers = [];
        try {
            foreach ([1, 2] as $i) {
                $dispute = Dispute::factory()->create(['shop_id' => $shop->id]);
                $process = new Process([PHP_BINARY, base_path('tests/Support/quota-worker.php'), (string) $shop->id, (string) $dispute->id, $gate], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $db['database'], 'DB_HOST' => $db['host'],
                        'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'APP_DEBUG' => 'false']);
                $process->setTimeout(25)->start();
                $workers[] = $process;
            }
            touch($gate);
            $outputs = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), 'Quota worker failed (output omitted to protect environment).');
                $outputs[] = trim($worker->getOutput());
            }
            sort($outputs);
            $this->assertSame(['FULL', 'RESERVED'], $outputs);
            $this->assertSame(0, app(UsageQuota::class)->summary($shop)['remaining']);
            $this->assertSame(1, app(UsageQuota::class)->summary($shop)['reserved']);
            $this->assertSame(1, $shop->disputes()->whereHas('automationDeliveries')->count());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_file($gate)) {
                unlink($gate);
            }
            $shop->delete();
        }
    }
}
