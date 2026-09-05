<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Jobs\{ProcessDisputeCreated,ProcessDisputeUpdated,ProcessPrivacyWebhook};
use App\Models\{Shop,WebhookEvent};
use App\Services\Shopify\{ShopifyAppService,ShopifyRequestVerifier};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, string $category, string $action, ShopifyAppService $app)
    {
        $topic = $category.'/'.$action;
        abort_unless(in_array($topic, ['disputes/create','disputes/update','app/uninstalled','customers/data_request','customers/redact','shop/redact'], true), 404);
        $result = $app->sdk()->verifyWebhookReq(ShopifyRequestVerifier::request($request));
        if (!$result->ok) { return ShopifyRequestVerifier::response($result); }
        $domain = ShopifyRequestVerifier::domain($result->shop.'.myshopify.com');
        abort_unless($request->header('X-Shopify-Topic') === $topic, 400);
        $id = (string) $request->header('X-Shopify-Webhook-Id');
        abort_unless(preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $id), 400);
        try { $payload = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { abort(400, 'Invalid JSON.'); }
        abort_unless(is_array($payload), 400);
        $shop = Shop::where('shop_domain',$domain)->first();
        DB::transaction(function () use ($shop,$domain,$id,$topic,$payload,$request) {
            $event = WebhookEvent::firstOrCreate(['webhook_id'=>$id], [
                'shop_id'=>$shop?->id,'shop_domain'=>$domain,'topic'=>$topic,'received_at'=>now(),
                'payload'=>str_starts_with($topic,'disputes/') ? ['id'=>$payload['id'] ?? null,'synthetic'=>$request->header('X-Shopify-Test') === 'true'] : $payload,
            ]);
            if (!$event->wasRecentlyCreated) { return; }
            if (!$shop) { $event->update(['status'=>'IGNORED','payload'=>null,'error_message'=>'Shop not installed.','processed_at'=>now()]); return; }
            if ($topic === 'app/uninstalled') {
                $shop->update(['status'=>'INACTIVE','uninstalled_at'=>now(),'access_token'=>null,'billing_status'=>'INACTIVE']);
                $shop->settings()->update(['auto_email_enabled'=>false,'onboarded_at'=>null]);
                \App\Models\AutomationDelivery::forShop($shop)->where('status','QUEUED')->update(['status'=>'CANCELLED','failure_reason'=>'App uninstalled.']);
                $event->update(['status'=>'PROCESSED','payload'=>null,'processed_at'=>now()]);
                return;
            }
            if (str_starts_with($topic, 'disputes/')) {
                $job = $topic === 'disputes/create' ? new ProcessDisputeCreated($event->id) : new ProcessDisputeUpdated($event->id);
            } else { $job = new ProcessPrivacyWebhook($event->id); }
            dispatch($job->onConnection('database'));
        });
        return response('', 200);
    }
}
