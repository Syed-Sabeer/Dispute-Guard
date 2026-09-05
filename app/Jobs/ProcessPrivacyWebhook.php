<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Privacy\PrivacyService;

class ProcessPrivacyWebhook extends QueuedJob
{
    public function __construct(public int $eventId) {}

    public function handle(PrivacyService $service): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (! $event || $event->status === 'PROCESSED') {
            return;
        }
        $event->increment('attempts');
        $service->process($event);
    }

    public function failed(?\Throwable $exception): void
    {
        WebhookEvent::whereKey($this->eventId)->update(['status' => 'FAILED', 'error_message' => 'Privacy action failed. Operator intervention required.']);
    }
}
