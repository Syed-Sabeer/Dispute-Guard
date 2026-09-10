<?php

use App\Models\AutomationDelivery;
use App\Models\Shop;
use App\Services\Billing\UsageQuota;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || ! str_starts_with(config('database.connections.mysql.database'), 'chargeguard_test_')) {
    exit(2);
}
$deadline = microtime(true) + 15;
while (! is_file($argv[3])) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
try {
    $result = DB::transaction(function () use ($argv) {
        $shop = Shop::findOrFail($argv[1]);
        $period = app(UsageQuota::class)->reserve($shop);
        if (! $period) {
            return 'FULL';
        }
        AutomationDelivery::create(['shop_id' => $shop->id, 'dispute_id' => $argv[2],
            'dispute_reason' => 'PRODUCT_NOT_RECEIVED', 'shipping_state' => 'IN_TRANSIT',
            'quota_period_id' => $period->id, 'quota_status' => 'RESERVED']);

        return 'RESERVED';
    }, 3);
    echo $result;
} catch (Throwable) {
    echo 'FAILED';
    exit(1);
}
