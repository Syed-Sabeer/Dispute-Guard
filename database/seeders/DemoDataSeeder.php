<?php
declare(strict_types=1);
namespace Database\Seeders;
use App\Models\{Shop,Dispute};
use App\Services\Email\DefaultEmailTemplateFactory;
use Illuminate\Database\Seeder;
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local','testing'])) { throw new \RuntimeException('Demo data is only permitted locally.'); }
        $shop = Shop::firstOrCreate(['shop_domain'=>'chargeguard-demo.myshopify.com'],['store_name'=>'Demo Store','currency'=>'USD','installed_at'=>now()]);
        $shop->settings()->firstOrCreate([],['test_mode'=>true,'auto_email_enabled'=>false,'store_display_name'=>'Demo Store','support_email'=>'support@example.com']);
        app(DefaultEmailTemplateFactory::class)->seed($shop);
        foreach (\App\Enums\DisputeReason::cases() as $i=>$reason) {
            foreach (\App\Enums\OrderShippingState::automatic() as $j=>$state) {
                $shop->disputes()->firstOrCreate(['shopify_dispute_id'=>'DEMO-'.$i.'-'.$j],['source'=>'demo','order_name'=>'#DEMO-'.$i.$j,'reason'=>$reason->value,'status'=>'NEEDS_RESPONSE','amount'=>'49.00','currency'=>'USD','shipping_state'=>$state->value,'automation_status'=>'MANUAL_REVIEW','review_reason'=>'Demonstration data; no customer email.']);
            }
        }
    }
}
