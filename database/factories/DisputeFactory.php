<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Dispute;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class DisputeFactory extends Factory
{
    protected $model = Dispute::class;

    public function definition(): array
    {
        return ['shop_id' => Shop::factory(), 'shopify_dispute_id' => fake()->unique()->numerify('########'), 'order_name' => '#DEMO-1001', 'reason' => 'PRODUCT_NOT_RECEIVED', 'status' => 'NEEDS_RESPONSE', 'amount' => '49.00', 'currency' => 'USD', 'shipping_state' => 'IN_TRANSIT', 'automation_status' => 'MANUAL_REVIEW', 'source' => 'demo'];
    }
}
