<?php
declare(strict_types=1);
namespace App\Services\Disputes;
use App\Exceptions\ShopifyApiException;
use App\Jobs\SendDisputeCustomerEmail;
use App\Models\{Shop,Dispute,AutomationDelivery};
use App\Services\Email\Recipient;
use App\Services\Orders\OrderShippingStateResolver;
use App\Services\Shopify\{ShopifyDisputeService,ShopifyOrderService};
use Illuminate\Support\Facades\{DB,Cache};
class DisputeProcessor
{
    public function __construct(private ShopifyDisputeService $disputes, private ShopifyOrderService $orders, private OrderShippingStateResolver $shipping, private AutomationResolver $automation) {}
    public function process(Shop $shop, string $id, bool $initial = false, string $source = 'shopify'): Dispute
    {
        return Cache::lock('dispute-'.$shop->id.'-'.hash('sha256', $id), 55)->block(2, function () use ($shop,$id,$initial,$source) {
            $shop->refresh();
            $record = $shop->disputes()->firstOrCreate(['shopify_dispute_id'=>$id], ['source'=>$source]);
            if (!$shop->active() || $record->redacted_at) { return $record; }
            try {
                $data = $this->disputes->fetch($shop, $id);
                if (!$data) { return $this->review($record, 'Dispute unavailable; synthetic webhook or missing access.'); }
                $orderId = data_get($data, 'order.id');
                $order = $orderId ? $this->orders->fetch($shop, $orderId) : null;
            } catch (ShopifyApiException $e) {
                $this->review($record, $e->getMessage());
                if ($e->transient) { throw $e; }
                return $record;
            }
            $shipping = $this->shipping->inspect($order ?? []);
            $tracking = $shipping['tracking'][0] ?? [];
            $reason = strtoupper(data_get($data, 'reasonDetails.reason', 'UNKNOWN'));
            $status = strtoupper($data['status'] ?? 'UNKNOWN');
            $email = $order['email'] ?? null;
            $record->update([
                'shopify_order_id'=>$orderId,'order_name'=>$order['name'] ?? null,'reason'=>$reason,'shopify_reason_raw'=>data_get($data,'reasonDetails.reason'),
                'status'=>$status,'shopify_status_raw'=>$data['status'] ?? null,'type'=>$data['type'] ?? null,
                'amount'=>data_get($data,'amount.amount','0'),'currency'=>data_get($data,'amount.currencyCode','XXX'),
                'shipping_state'=>$shipping['state']->value,'raw_shipping_status'=>$shipping['raw'],
                'tracking_company'=>$tracking['company'] ?? null,'tracking_number'=>$tracking['number'] ?? null,'tracking_url'=>$tracking['url'] ?? null,
                'customer_email_hash'=>Recipient::valid($email) ? Recipient::hash($email) : null,'refunds'=>$order['refunds'] ?? null,
                'initiated_at'=>$data['initiatedAt'] ?? null,'evidence_due_at'=>$data['evidenceDueBy'] ?? null,'last_synced_at'=>now(),
            ]);
            if (!$initial || $record->initial_processed_at) { return $record; }
            $template = $this->automation->template($shop, $reason, $record->shipping_state);
            $blocked = !$order ? 'Associated order unavailable; manual review required.' : $this->automation->blocked($shop, $record, $template);
            if (!$blocked && !Recipient::valid($email)) { $blocked = 'Customer email unavailable; manual review required.'; }
            DB::transaction(function () use ($shop, $record, $template, $blocked) {
                $locked = Dispute::whereKey($record->id)->lockForUpdate()->firstOrFail();
                if ($locked->initial_processed_at || $locked->redacted_at) { return; }
                $locked->update(['initial_processed_at'=>now(),'automation_status'=>$blocked ? 'MANUAL_REVIEW' : 'EMAIL_QUEUED','review_reason'=>$blocked]);
                if ($blocked) { return; }
                $delivery = AutomationDelivery::firstOrCreate(['dispute_id'=>$locked->id], [
                    'shop_id'=>$shop->id,'email_template_id'=>$template->id,'shipping_state'=>$locked->shipping_state,
                    'dispute_reason'=>$locked->reason,'recipient_hash'=>$locked->customer_email_hash,
                ]);
                if ($delivery->wasRecentlyCreated) { SendDisputeCustomerEmail::dispatch($delivery->id)->onConnection('database'); }
            });
            return $record->refresh();
        });
    }
    private function review(Dispute $record, string $reason): Dispute
    {
        $record->update(['automation_status'=>'MANUAL_REVIEW','review_reason'=>$reason,'last_synced_at'=>now()]);
        return $record;
    }
}
