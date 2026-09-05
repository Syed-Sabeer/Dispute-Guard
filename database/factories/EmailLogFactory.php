<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailLog;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailLogFactory extends Factory
{
    protected $model = EmailLog::class;

    public function definition(): array
    {
        return ['shop_id' => Shop::factory(), 'type' => 'test', 'recipient_masked' => 's***@example.com', 'subject' => '[TEST] Sample', 'shipping_state' => 'IN_TRANSIT', 'dispute_reason' => 'PRODUCT_NOT_RECEIVED', 'status' => 'SENT', 'sent_at' => now()];
    }
}
