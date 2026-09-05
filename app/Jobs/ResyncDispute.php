<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Dispute;
use App\Services\Disputes\DisputeProcessor;

class ResyncDispute extends QueuedJob
{
    public function __construct(public int $disputeId) {}

    public function handle(DisputeProcessor $processor): void
    {
        $d = Dispute::find($this->disputeId);
        if ($d && $d->shop->active()) {
            $processor->process($d->shop, $d->shopify_dispute_id, false);
        }
    }
}
