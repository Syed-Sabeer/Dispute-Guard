<?php
declare(strict_types=1);
namespace Database\Factories;
use App\Models\{EmailTemplate,Shop};
use Illuminate\Database\Eloquent\Factories\Factory;
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;
    public function definition(): array { return ['shop_id'=>Shop::factory(),'dispute_reason'=>'PRODUCT_NOT_RECEIVED','shipping_state'=>'IN_TRANSIT','subject'=>'Order {{order_number}}','body'=>'<p>Hello {{customer_name}}</p>','enabled'=>true]; }
}
