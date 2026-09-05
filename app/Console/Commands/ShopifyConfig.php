<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ShopifyConfig extends Command
{
    protected $signature = 'chargeguard:shopify-config';

    protected $description = 'Synchronize the Shopify TOML webhook API version from Laravel configuration';

    public function handle(): int
    {
        $version = config('shopify.api_version');
        if (! preg_match('/^20\d{2}-(01|04|07|10)$/D', $version)) {
            $this->error('Invalid API version.');

            return self::FAILURE;
        }
        $path = base_path('shopify.app.toml');
        $text = file_get_contents($path);
        $text = preg_replace('/^api_version = "[^"]*"$/m', 'api_version = "'.$version.'"', $text);
        file_put_contents($path, $text);
        $this->info('Shopify webhook version synchronized.');

        return self::SUCCESS;
    }
}
