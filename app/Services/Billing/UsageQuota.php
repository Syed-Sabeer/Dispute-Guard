<?php

namespace App\Services\Billing;

use App\Exceptions\EmailProviderException;
use App\Models\AutomationDelivery;
use App\Models\Dispute;
use App\Models\Shop;
use App\Models\SubscriptionUsagePeriod;
use Illuminate\Support\Facades\DB;

class UsageQuota
{
    public const EXHAUSTED = 'Automated follow-up quota exhausted for this billing period. Upgrade your plan to allow new disputes; this dispute requires manual review.';

    public const UNAVAILABLE = 'Subscription usage period is unavailable or expired. Verify billing before sending; this dispute requires manual review.';

    // Called inside the enqueue transaction. Shop row serializes first-period creation
    // and plan/period updates; the conditional increment is the hard capacity boundary.
    public function reserve(Shop $shop): ?SubscriptionUsagePeriod
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Quota reservation requires an enqueue transaction.');
        }
        $period = $this->currentLocked($shop);
        if (! $period) {
            return null;
        }
        $won = SubscriptionUsagePeriod::whereKey($period->id)->whereRaw('reserved + consumed < allowance')
            ->increment('reserved');

        return $won ? $period->refresh() : null;
    }

    private function currentLocked(Shop $shop): ?SubscriptionUsagePeriod
    {
        $shop = Shop::whereKey($shop->id)->lockForUpdate()->first();
        $plan = config('quotas.plans.'.$shop?->quota_plan);
        if (! $shop?->active() || ! is_array($plan) || ! $shop->billing_period_start || ! $shop->billing_period_end
            || $shop->billing_period_start->gt(now()) || $shop->billing_period_end->lte(now())) {
            return null;
        }
        $period = SubscriptionUsagePeriod::firstOrCreate(['shop_id' => $shop->id, 'starts_at' => $shop->billing_period_start],
            ['ends_at' => $shop->billing_period_end, 'plan' => $shop->quota_plan, 'allowance' => $plan['allowance']]);
        $period = SubscriptionUsagePeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
        $period->update(['ends_at' => $shop->billing_period_end, 'plan' => $shop->quota_plan, 'allowance' => $plan['allowance']]);
        // Rollout must not grant a fresh allowance for sends already made this cycle.
        // Legacy in-flight rows have no transport marker: their acceptance is uncertain.
        $legacy = AutomationDelivery::where('shop_id', $shop->id)->whereNull('quota_period_id')
            ->where(fn ($q) => $q->whereIn('status', ['SENT', 'UNKNOWN', 'SENDING'])->orWhereNotNull('sent_at'))
            ->whereRaw('COALESCE(sent_at, claimed_at, created_at) >= ?', [$period->starts_at])
            ->whereRaw('COALESCE(sent_at, claimed_at, created_at) < ?', [$period->ends_at])
            ->lockForUpdate()->get();
        foreach ($legacy as $delivery) {
            $delivery->update(['quota_period_id' => $period->id, 'quota_status' => 'CONSUMED']);
        }
        if ($legacy->isNotEmpty()) {
            $period->increment('consumed', $legacy->count());
        }

        return $period;
    }

    public function summary(Shop $shop): ?array
    {
        return DB::transaction(function () use ($shop) {
            $period = $this->currentLocked($shop);
            if (! $period) {
                return null;
            }

            return ['plan' => $period->plan, 'name' => config('quotas.plans.'.$period->plan.'.name'),
                'used' => $period->consumed, 'reserved' => $period->reserved, 'allowance' => $period->allowance,
                'remaining' => max(0, $period->allowance - $period->reserved - $period->consumed),
                'percentage' => min(100, round(100 * ($period->consumed + $period->reserved) / max(1, $period->allowance))),
                'resets_at' => $period->ends_at];
        });
    }

    // Recheck at the QUEUED -> SENDING claim, in its transaction.
    public function canSend(AutomationDelivery $delivery): bool
    {
        $period = $this->currentLocked($delivery->shop);
        $current = AutomationDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
        $delivery->setRawAttributes($current->getAttributes(), true);
        if (! $period || $delivery->quota_period_id != $period->id || $delivery->quota_status !== 'RESERVED' || $delivery->transport_started_at !== null) {
            return false;
        }
        // A downgrade keeps consumption; earliest outstanding reservations have priority.
        // Locking reads see the latest committed reservations under MySQL's default
        // REPEATABLE READ isolation, even if this transaction previously read a snapshot.
        $reservations = AutomationDelivery::where('shop_id', $delivery->shop_id)->where('quota_period_id', $period->id)
            ->where('quota_status', 'RESERVED')->lockForUpdate()->get(['id', 'transport_started_at']);
        $rank = $reservations->filter(fn ($row) => $row->transport_started_at === null && $row->id <= $delivery->id)->count();
        $inflight = $reservations->filter(fn ($row) => $row->transport_started_at !== null)->count();

        return $rank >= 1 && $rank <= max(0, $period->allowance - $period->consumed - $inflight);
    }

    public function lockReservation(AutomationDelivery $delivery): void
    {
        SubscriptionUsagePeriod::whereKey($delivery->quota_period_id)->where('shop_id', $delivery->shop_id)->lockForUpdate()->first();
    }

    public function settle(AutomationDelivery $delivery, bool $consume, bool $definiteRejection = false): void
    {
        DB::transaction(function () use ($delivery, $consume, $definiteRejection) {
            $period = SubscriptionUsagePeriod::whereKey($delivery->quota_period_id)->where('shop_id', $delivery->shop_id)->lockForUpdate()->first();
            if (! $period) {
                return;
            }
            $changed = AutomationDelivery::whereKey($delivery->id)->where('shop_id', $period->shop_id)
                ->where('quota_period_id', $period->id)->where('quota_status', 'RESERVED')
                ->update(['quota_status' => $consume ? 'CONSUMED' : 'RELEASED']);
            if ($changed) {
                $period->update(['reserved' => max(0, $period->reserved - 1), 'consumed' => $period->consumed + ($consume ? 1 : 0)]);
            } elseif (! $consume && $definiteRejection) {
                // Privacy may conservatively consume an in-flight reservation before
                // a definitive provider rejection arrives. Known non-acceptance releases it.
                $released = AutomationDelivery::whereKey($delivery->id)->where('shop_id', $period->shop_id)
                    ->where('quota_period_id', $period->id)->where('quota_status', 'CONSUMED')
                    ->whereNotIn('status', ['SENT', 'UNKNOWN'])->update(['quota_status' => 'RELEASED']);
                if ($released) {
                    $period->decrement('consumed');
                }
            }
        }, 3);
    }

    public function beginTransport(AutomationDelivery $delivery): void
    {
        DB::transaction(function () use ($delivery) {
            $dispute = Dispute::whereKey($delivery->dispute_id)->lockForUpdate()->first();
            $settings = $delivery->shop->settings()->first();
            if (! $dispute || $dispute->redacted_at || ! $delivery->shop->fresh()->active()
                || ! $settings?->auto_email_enabled || $settings->test_mode || config('chargeguard.test_mode')
                || ! $this->canSend($delivery) || $delivery->status !== 'SENDING') {
                throw new EmailProviderException('QUOTA_UNAVAILABLE');
            }
            $delivery->update(['transport_started_at' => now()]);
        }, 3);
    }
}
