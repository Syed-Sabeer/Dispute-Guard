<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Disputes\DisputeProcessor;
use App\Services\Shopify\ShopifyRequestVerifier;
use Illuminate\Console\Command;

class SyncDispute extends Command
{
    protected $signature = 'chargeguard:sync-dispute {shop} {dispute}';

    protected $description = 'Synchronize a Shopify dispute without sending customer email';

    public function handle(DisputeProcessor $processor): int
    {
        $domain = ShopifyRequestVerifier::domain($this->argument('shop'));
        $shop = Shop::where('shop_domain', $domain)->firstOrFail();
        $d = $processor->process($shop, (string) $this->argument('dispute'), false);
        $this->info('Dispute synchronized: '.$d->automation_status);

        return self::SUCCESS;
    }
}
