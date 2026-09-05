<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\Shop;
final class CurrentShop
{
    private ?Shop $shop = null;
    public function set(Shop $shop): void { $this->shop = $shop; }
    public function get(): Shop { return $this->shop ?? abort(401, 'Open this app from Shopify Admin.'); }
}
