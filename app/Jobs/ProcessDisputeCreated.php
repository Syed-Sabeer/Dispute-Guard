<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Models\WebhookEvent;
use App\Services\Disputes\DisputeProcessor;
class ProcessDisputeCreated extends QueuedJob
{
    public function __construct(public int $eventId) {}
    protected function initial(): bool { return true; }
    public function handle(DisputeProcessor $processor): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (!$event || $event->status === 'PROCESSED') { return; }
        $event->increment('attempts');
        $shop = $event->shop;
        if ($shop && $shop->active()) {
            $payload = $event->payload ?? [];
            $id = (string) ($payload['id'] ?? '');
            if (!preg_match('/^[0-9]+$/D', $id)) { $event->update(['status'=>'FAILED','payload'=>null,'error_message'=>'Invalid dispute identifier.']); return; }
            $processor->process($shop, $id, $this->initial(), ($payload['synthetic'] ?? false) ? 'synthetic' : 'shopify');
        }
        $event->update(['status'=>'PROCESSED','payload'=>null,'processed_at'=>now()]);
    }
    public function failed(?\Throwable $exception): void
    {
        WebhookEvent::whereKey($this->eventId)->update(['status'=>'FAILED','error_message'=>'Processing failed after bounded retries. Resync the dispute.','payload'=>null]);
    }
}
