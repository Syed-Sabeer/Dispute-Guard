<?php
declare(strict_types=1);
namespace Database\Factories;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
class ShopFactory extends Factory
{
    protected $model = Shop::class;
    public function definition(): array
    {
        return ['shop_domain'=>'demo-'.fake()->unique()->numerify('########').'.myshopify.com','store_name'=>'Demo Store','currency'=>'USD','status'=>'ACTIVE','installed_at'=>now()];
    }
}
