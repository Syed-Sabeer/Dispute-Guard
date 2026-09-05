<?php
declare(strict_types=1);
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail,Queue,DB};
use App\Models\{Dispute,EmailLog};
use App\Jobs\SendTestAutomationEmail;
class MerchantTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;
    public function test_signed_authenticated_pages_render(): void
    {
        $shop = $this->shop(); $this->merchant($shop);
        $verified = app(\App\Services\Shopify\ShopifyAppService::class)->sdk()->verifyAppHomeReq(['method'=>'GET','url'=>'http://localhost/dashboard','body'=>'','headers'=>['authorization'=>'Bearer '.$this->token($shop)]], '/auth/patch-id-token');
        $this->assertTrue($verified->ok, $verified->log->code.' '.$verified->log->detail);
        foreach (['/dashboard','/onboarding','/disputes','/templates','/test-automation','/email-logs','/settings','/billing','/privacy-requests','/templates/'.$shop->emailTemplates()->first()->id.'/edit'] as $url) { $this->get($url)->assertOk(); }
        $this->get('/dashboard')->assertSee('s-app-nav',false)->assertDontSee('offline-test-token');
    }
    public function test_invalid_authentication_is_rejected(): void
    {
        $shop = $this->shop();
        $this->withToken($this->token($shop,['aud'=>'wrong-app']))->getJson('/dashboard')->assertStatus(401);
    }
    public function test_tenant_isolation(): void
    {
        $a = $this->shop(); $b = $this->shop(); $dispute = Dispute::factory()->create(['shop_id'=>$b->id]); $template = $b->emailTemplates()->first();
        $log = EmailLog::factory()->create(['shop_id'=>$b->id,'subject'=>'Secret B subject']);
        $this->merchant($a)->get('/disputes/'.$dispute->id)->assertNotFound();
        $this->putJson('/templates/'.$template->id,['enabled'=>true,'subject'=>'Intrusion','body'=>'x'])->assertNotFound();
        $this->get('/email-logs/'.$log->id)->assertNotFound();
        $this->get('/email-logs')->assertOk()->assertDontSee('Secret B subject');
    }
    public function test_test_email_uses_real_rendering_and_mailable(): void
    {
        Queue::fake(); Mail::fake(); $shop = $this->shop(['auto_email_enabled'=>false,'test_mode'=>true]);
        $this->merchant($shop)->postJson('/test-automation/send',['dispute_reason'=>'FRAUDULENT','shipment_status'=>'DELIVERED','customer_name'=>'Test Alex','customer_email'=>'developer@example.com','order_number'=>'#TEST','order_amount'=>'49.00','currency'=>'USD','store_name'=>'Sample store'])->assertOk();
        $this->assertDatabaseCount('disputes',0); $this->assertSame('test',EmailLog::sole()->type);
        $job = Queue::pushed(SendTestAutomationEmail::class)->first();
        $this->assertStringNotContainsString('developer@example.com',$job->encryptedInput);
        app()->call([$job,'handle']);
        Mail::assertSent(\App\Mail\DisputeCustomerMail::class,fn ($mail)=>$mail->hasTo('developer@example.com') && str_starts_with($mail->mailSubject,'[TEST]'));
    }
    public function test_csrf_cross_origin_form_and_invalid_email_are_rejected(): void
    {
        $shop = $this->shop(); $this->merchant($shop);
        $this->post('/test-automation/send')->assertStatus(419);
        $this->withHeader('Origin','https://evil.example')->postJson('/test-automation/send',[])->assertStatus(419);
        $this->withHeader('Origin',config('app.url'))->postJson('/test-automation/send',['customer_email'=>"x@example.com\r\nBcc:other@example.com"])->assertUnprocessable();
    }
    public function test_restore_requires_confirmation_and_activation_requires_onboarding(): void
    {
        $shop = $this->shop(); $this->merchant($shop); $template = $shop->emailTemplates()->first();
        $this->postJson('/templates/'.$template->id.'/restore',[])->assertUnprocessable();
        $this->postJson('/templates/'.$template->id.'/restore',['confirmed'=>true])->assertOk();
        $this->putJson('/settings',['store_display_name'=>'Store','support_email'=>'help@example.com','auto_email_enabled'=>true,'test_mode'=>false,'timezone'=>'UTC','templates_reviewed'=>true])->assertUnprocessable();
    }
    public function test_tokens_are_encrypted_at_rest(): void
    {
        $shop = $this->shop();
        $this->assertStringNotContainsString('offline-test-token',DB::table('shops')->where('id',$shop->id)->value('access_token'));
        $this->assertArrayNotHasKey('access_token',$shop->toArray());
    }
}
